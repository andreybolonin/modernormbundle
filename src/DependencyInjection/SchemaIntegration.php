<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DependencyInjection;

use ModernORM\Bundle\Schema\SchemaScope;
use ModernORM\Bundle\Schema\CacheInvalidator;
use ModernORM\Bundle\Migrations\DependencyFactoryFactory;
use ModernORM\Bundle\Fixtures\Fixture;
use ModernORM\Bundle\Fixtures\Runner;
use ModernORM\Bundle\Fixtures\LoadCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;

/** Optional packages are resolved only when the matching feature is enabled. */
final class SchemaIntegration
{
    public static function register(ContainerBuilder $container, array $config): void
    {
        if (!$config['migrations']['enabled'] && !$config['fixtures']['enabled']) { return; }
        $filter = $config['managed_schema_filter'];
        if (!is_string($filter) || @preg_match($filter, '') === false) { throw new \InvalidArgumentException('Set an explicit managed_schema_filter before enabling migrations or fixtures.'); }
        $ref = static fn (string $id): Reference => new Reference($id);
        $container->register('modernorm.schema_scope', SchemaScope::class)
            ->setArguments([$ref('modernorm.entity_manager'), $filter, $config['migrations']['table_name']]);
        $container->register('modernorm.schema_cache_invalidator', CacheInvalidator::class)
            ->setArguments([$ref('modernorm.entity_manager'), $ref('modernorm.schema_scope')]);
        if ($config['migrations']['enabled']) {
            if (!class_exists(\Doctrine\Migrations\DependencyFactory::class)) { throw new \LogicException('Install doctrine/migrations to enable modernorm.migrations.'); }
            if ($config['migrations']['paths'] === []) { throw new \InvalidArgumentException('Configure at least one modernorm.migrations.paths entry.'); }
            $container->register('modernorm.migrations.dependency_factory', \Doctrine\Migrations\DependencyFactory::class)
                ->setFactory([DependencyFactoryFactory::class, 'create'])
                ->setArguments([$ref('modernorm.connection'), $ref('modernorm.schema_scope'), $config['migrations']['paths'], $config['migrations']['table_name'], $config['migrations']['all_or_nothing']]);
            foreach ([
                'generate' => \Doctrine\Migrations\Tools\Console\Command\GenerateCommand::class,
                'diff' => \Doctrine\Migrations\Tools\Console\Command\DiffCommand::class,
                'status' => \Doctrine\Migrations\Tools\Console\Command\StatusCommand::class,
                'migrate' => \Doctrine\Migrations\Tools\Console\Command\MigrateCommand::class,
                'sync-metadata-storage' => \Doctrine\Migrations\Tools\Console\Command\SyncMetadataCommand::class,
            ] as $name => $class) {
                $id = 'modernorm.migrations.command.'.$name;
                $container->register($id.'.inner', $class)->setArguments([$ref('modernorm.migrations.dependency_factory')]);
                $container->register($id, \ModernORM\Bundle\Migrations\Command::class)
                    ->setArguments([$ref($id.'.inner'), $ref('modernorm.schema_scope'), $ref('modernorm.entity_manager'), $ref('modernorm.schema_cache_invalidator'), $name === 'migrate'])
                    ->addTag('console.command', ['command' => 'modernorm:migrations:'.$name]);
            }
        }
        if ($config['fixtures']['enabled']) {
            if (!class_exists(\Doctrine\Common\DataFixtures\Loader::class)) { throw new \LogicException('Install doctrine/data-fixtures to enable modernorm.fixtures.'); }
            $container->registerForAutoconfiguration(Fixture::class)->addTag('modernorm.fixture');
            $container->register('modernorm.fixtures.runner', Runner::class)
                ->setArguments([$ref('modernorm.entity_manager'), $ref('modernorm.schema_scope'), $ref('modernorm.schema_cache_invalidator'), new TaggedIteratorArgument('modernorm.fixture')]);
            $container->register('modernorm.fixtures.command', LoadCommand::class)
                ->setArguments([$ref('modernorm.fixtures.runner'), $container->getParameter('kernel.environment')])
                ->addTag('console.command', ['command' => 'modernorm:fixtures:load']);
        }
    }
}
