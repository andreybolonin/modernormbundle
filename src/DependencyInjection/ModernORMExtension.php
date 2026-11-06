<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DependencyInjection;

use Doctrine\DBAL\Connection;
use Doctrine\Common\EventManager;
use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\EntityManagerFactory;
use ModernORM\Bundle\Registry;
use ModernORM\Bundle\Lifecycle\RequestListener;
use ModernORM\Bundle\Profiler\DataCollector;
use ModernORM\Bundle\Profiler\OperationHistory;
use ModernORM\Configuration as ORMConfiguration;
use ModernORM\EntityManager;
use ModernORM\EntityManagerInterface;
use ModernORM\Statistics;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class ModernORMExtension extends Extension
{
    public function getAlias(): string { return 'modernorm'; }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration((bool) $container->getParameter('kernel.debug')), $configs);
        SchemaIntegration::register($container, $config);
        $ref = static fn (string $id): Reference => new Reference($id);
        $container->register('modernorm.profiler.history', OperationHistory::class)
            ->setArguments([$config['profiler']['max_events'], $config['profiler']['enabled'] ? new ServiceClosureArgument(new Reference('profiler', ContainerInterface::NULL_ON_INVALID_REFERENCE)) : null, new TaggedIteratorArgument('modernorm.statistics_observer')])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register('modernorm.profiler.sql_history', \ModernORM\Bundle\Profiler\SqlHistory::class)
            ->setArguments([$config['profiler']['enabled'] && $config['profiler']['collect_sql'], $config['profiler']['max_queries'], $config['profiler']['max_sql_length'], $config['profiler']['enabled'] ? new ServiceClosureArgument(new Reference('profiler', ContainerInterface::NULL_ON_INVALID_REFERENCE)) : null])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register('modernorm.statistics', Statistics::class)
            ->setArguments([$config['statistics']['enabled'], $ref('modernorm.profiler.history')])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->setAlias(Statistics::class, 'modernorm.statistics');
        $container->register('modernorm.cache_logger', StatisticsCacheLogger::class)->addTag('kernel.reset', ['method' => 'reset']);
        $container->setAlias(StatisticsCacheLogger::class, 'modernorm.cache_logger');
        $container->register('modernorm.cache_logger_dispatcher', \ModernORM\Bundle\Observability\CacheLoggerDispatcher::class)
            ->setArguments([$ref('modernorm.cache_logger'), new TaggedIteratorArgument('modernorm.cache_logger')]);
        $container->setAlias(\ModernORM\Bundle\Observability\CacheLoggerDispatcher::class, 'modernorm.cache_logger_dispatcher');
        $container->register('modernorm.connection', Connection::class)->setFactory([ConnectionFactory::class, 'create'])
            ->setArguments([$config['url'], $config['server_version'], $ref('modernorm.statistics'), $ref('modernorm.profiler.sql_history')]);
        // A named argument alias lets DoctrineBundle coexist without taking its default DBAL alias.
        $container->registerAliasForArgument('modernorm.connection', Connection::class, 'modernormConnection');
        $container->register('modernorm.event_manager', EventManager::class);
        $container->register('modernorm.configuration', ORMConfiguration::class)->setFactory([EntityManagerFactory::class, 'configuration'])
            ->setArguments([$config['mapping_paths'], $config['batch_size'], $ref($config['metadata_cache_pool']), $ref($config['query_cache_pool']), $config['l2_cache']['pool'] === null ? null : $ref($config['l2_cache']['pool']), $config['l2_cache']['namespace'], $ref('modernorm.cache_logger_dispatcher')]);
        $container->register('modernorm.entity_manager_factory', EntityManagerFactory::class)
            ->setArguments([$ref('modernorm.connection'), $ref('modernorm.configuration'), $ref('modernorm.event_manager')])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register('modernorm.entity_manager', EntityManager::class)->setFactory([$ref('modernorm.entity_manager_factory'), 'create']);
        $container->register('modernorm.registry', Registry::class)
            ->setArguments([$ref('modernorm.entity_manager_factory'), $ref('modernorm.connection')]);
        $container->setAlias(Registry::class, 'modernorm.registry');
        $container->registerAliasForArgument('modernorm.registry', \Doctrine\Persistence\ManagerRegistry::class, 'modernormRegistry');
        $container->register('modernorm.console.provider', \ModernORM\Tools\Console\EntityManagerProvider\SingleManagerProvider::class)
            ->setArguments([$ref('modernorm.entity_manager')]);
        foreach ([
            'mapping:info' => \ModernORM\Tools\Console\Command\InfoCommand::class,
            'mapping:describe' => \ModernORM\Tools\Console\Command\MappingDescribeCommand::class,
            'schema:create' => \ModernORM\Tools\Console\Command\SchemaTool\CreateCommand::class,
            'schema:update' => \ModernORM\Tools\Console\Command\SchemaTool\UpdateCommand::class,
            'schema:drop' => \ModernORM\Tools\Console\Command\SchemaTool\DropCommand::class,
            'schema:validate' => \ModernORM\Tools\Console\Command\ValidateSchemaCommand::class,
        ] as $name => $class) {
            $container->register('modernorm.command.'.str_replace(':', '.', $name), $class)
                ->setArguments([$ref('modernorm.console.provider')])
                ->addMethodCall('setName', ['modernorm:'.$name])
                ->addTag('console.command', ['command' => 'modernorm:'.$name]);
        }
        $container->setAlias(EntityManagerInterface::class, 'modernorm.entity_manager');
        $container->setAlias(EntityManager::class, 'modernorm.entity_manager');
        $container->setAlias(ORMConfiguration::class, 'modernorm.configuration');
        $container->register('modernorm.request_listener', RequestListener::class)
            ->setArguments([$ref('modernorm.statistics'), $ref('modernorm.cache_logger'), $ref('modernorm.profiler.history'), $ref('modernorm.profiler.sql_history')])->addTag('kernel.event_subscriber');
        if ($config['profiler']['enabled']) {
            $container->register('modernorm.profiler.advisor', \ModernORM\Bundle\Profiler\PerformanceAdvisor::class)
                ->setArguments([$config['profiler']['advisor']]);
            $container->register('modernorm.profiler.collector', DataCollector::class)
                ->setArguments([$ref('modernorm.statistics'), $ref('modernorm.cache_logger'), $ref('modernorm.profiler.history'), $config['l2_cache']['pool'] !== null, $ref('modernorm.profiler.sql_history'), $ref('modernorm.profiler.advisor'), $container->getParameter('kernel.environment'), $config['server_version'] === null ? null : (string) $config['server_version'], $config['profiler']['advisor']['enabled']])
                ->addTag('data_collector', ['id' => 'modernorm', 'template' => '@ModernORM/Collector/modernorm.html.twig', 'priority' => 250]);
        }
    }
}
