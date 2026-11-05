<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests\Fixtures;

use ModernORM\Bundle\ModernORMBundle;
use ModernORM\Bundle\Profiler\OperationHistory;
use ModernORM\Statistics;
use ModernORM\Statistics\BatchKind;
use ModernORM\Statistics\BatchFallbackReason;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\HttpFoundation\Response;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
    public function __construct(private readonly bool $profiling = true, private readonly bool $statistics = true, private readonly bool $ui = true, private readonly bool $sql = false)
    {
        parent::__construct('test', false);
    }
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ModernORMBundle();
        if ($this->ui) { yield new TwigBundle(); yield new WebProfilerBundle(); }
    }
    public function getProjectDir(): string { return dirname(__DIR__, 2); }
    public function getCacheDir(): string { return sys_get_temp_dir().'/modernorm-bundle-tests/'.substr(hash('sha256', file_get_contents(__FILE__).file_get_contents(dirname(__DIR__, 2).'/src/DependencyInjection/ModernORMExtension.php').file_get_contents(dirname(__DIR__, 2).'/composer.lock')), 0, 12).'/'.(int)$this->profiling.(int)$this->statistics.(int)$this->ui.(int)$this->sql; }
    public function getLogDir(): string { return $this->getCacheDir().'/log'; }
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = ['secret' => 'test', 'test' => true, 'router' => ['utf8' => true]];
        if ($this->ui) { $framework['profiler'] = ['enabled' => true, 'collect' => $this->profiling]; }
        $container->extension('framework', $framework);
        $container->extension('modernorm', [
            'url' => getenv('MODERNORM_TEST_URL') ?: 'postgresql://localhost/modernorm_test', 'server_version' => '17',
            'mapping_paths' => [__DIR__.'/Entity'],
            'statistics' => ['enabled' => $this->statistics],
            'profiler' => ['max_events' => 2, 'collect_sql' => $this->sql, 'max_queries' => 4, 'max_sql_length' => 128],
            'l2_cache' => ['pool' => 'cache.app'],
        ]);
        $container->services()->set('test.sql_controller', SqlController::class)->public()->args([new \Symfony\Component\DependencyInjection\Reference('modernorm.connection')]);
        $container->services()->set('test.orm_controller', OrmController::class)->public()->args([new \Symfony\Component\DependencyInjection\Reference('modernorm.entity_manager'), new \Symfony\Component\DependencyInjection\Reference('modernorm.cache_logger')]);
        $container->services()->set('test.nested_controller', NestedController::class)->public()->args([new \Symfony\Component\DependencyInjection\Reference('http_kernel')]);
        if ($this->ui) {
            $container->extension('twig', ['strict_variables' => true]);
            $container->extension('web_profiler', ['toolbar' => true]);
        }
        $container->services()->alias('test.registry', 'modernorm.registry')->public();
        $container->services()->alias('test.manager', 'modernorm.entity_manager')->public();
        $container->services()->set('test.metrics_controller', MetricsController::class)->public()->args([
            new \Symfony\Component\DependencyInjection\Reference('modernorm.statistics'),
            new \Symfony\Component\DependencyInjection\Reference('modernorm.cache_logger'),
        ]);
    }
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('sql', '/sql')->controller('test.sql_controller');
        $routes->add('orm', '/orm')->controller('test.orm_controller');
        $routes->add('metrics', '/metrics')->controller('test.metrics_controller');
        $routes->add('nested', '/nested')->controller('test.nested_controller');
        $routes->add('empty', '/empty')->controller([MetricsController::class, 'empty']);
        if ($this->ui) {
            $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.'.(is_file(dirname((new \ReflectionClass(WebProfilerBundle::class))->getFileName()).'/Resources/config/routing/wdt.php') ? 'php' : 'xml'))->prefix('/_wdt');
            $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.'.(is_file(dirname((new \ReflectionClass(WebProfilerBundle::class))->getFileName()).'/Resources/config/routing/profiler.php') ? 'php' : 'xml'))->prefix('/_profiler');
        }
    }
}

final class MetricsController
{
    public function __construct(private readonly Statistics $statistics, private readonly StatisticsCacheLogger $logger) {}
    public static function empty(): Response { return new Response('<html><body>empty</body></html>'); }
    public function __invoke(): Response
    {
        if (($measurement = $this->statistics->start()) !== null) { $this->statistics->recordSql($measurement, false); }
        if (($measurement = $this->statistics->start()) !== null) { $this->statistics->recordFlush($measurement, true); }
        if (($batch = $this->statistics->startBatch(BatchKind::ONE_TO_MANY, 'Order', 'Order::items', 32, 5)) !== null) { $this->statistics->recordBatch($batch, false); }
        $this->statistics->recordBatchFallback(BatchKind::MANY_TO_ONE, BatchFallbackReason::SIZE_ONE, 'Product', null, 1);
        $this->logger->hit('Product');
        $this->logger->miss('Product');
        return new Response('<html><body>metrics</body></html>');
    }
}

final class NestedController
{
    public function __construct(private readonly \Symfony\Component\HttpKernel\HttpKernelInterface $kernel) {}
    public function __invoke(): Response
    {
        $this->kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/metrics'), \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        $this->kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/metrics'), \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
        return new Response('<html><body>nested</body></html>');
    }
}

final class SqlController
{
    public function __construct(private readonly \Doctrine\DBAL\Connection $connection) {}
    public function __invoke(): Response
    {
        $statement = $this->connection->prepare('SELECT CAST(? AS TEXT) AS value');
        for ($i = 0; $i < 3; ++$i) { $statement->bindValue(1, 'private-binding-'.$i); $statement->executeQuery()->fetchOne(); }
        try { $this->connection->executeQuery('SELECT 1 / 0'); } catch (\Doctrine\DBAL\Exception) {}
        $this->connection->executeStatement('SELECT 7');
        return new Response('<html><body>SQL</body></html>');
    }
}

final class OrmController
{
    public function __construct(private readonly \ModernORM\EntityManagerInterface $manager, private readonly StatisticsCacheLogger $logger) {}
    public function __invoke(): Response
    {
        $em = $this->manager;
        $db = $em->getConnection();
        foreach ([
            'CREATE TEMP TABLE mb_customer (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)',
            'CREATE TEMP TABLE mb_tag (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)',
            'CREATE TEMP TABLE mb_order (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL, customer_id INT NOT NULL)',
            'CREATE TEMP TABLE mb_line (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL, order_id INT NOT NULL)',
            'CREATE TEMP TABLE mb_order_tag (order_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY (order_id, tag_id))',
            'CREATE TEMP TABLE mb_reference (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)',
        ] as $sql) { $db->executeStatement($sql); }
        $orders = [];
        for ($i = 1; $i <= 3; ++$i) {
            $customer = new Entity\Customer(); $customer->id = $i; $customer->name = 'Customer '.$i; $em->persist($customer);
            $tag = new Entity\Tag(); $tag->id = $i; $tag->name = 'Tag '.$i; $em->persist($tag);
            $order = new Entity\Order(); $order->id = $i; $order->name = 'Order '.$i; $order->customer = $customer; $order->tags->add($tag); $em->persist($order); $orders[] = $order;
            if ($i < 3) { $line = new Entity\Line(); $line->id = $i; $line->name = 'Line '.$i; $line->order = $order; $em->persist($line); }
        }
        $reference = new Entity\ReferenceItem(); $reference->id = 1; $reference->name = 'before'; $em->persist($reference);
        $em->flush();
        $em->clear();
        $orders = $em->getRepository(Entity\Order::class)->findBy([], ['id' => 'ASC']);
        $customers = array_map(static fn (Entity\Order $order): string => $order->customer->name, $orders);
        $lines = array_map(static fn (Entity\Order $order): int => $order->lines->count(), $orders);
        $tags = array_map(static fn (Entity\Order $order): int => $order->tags->count(), $orders);
        $em->getCache()->evict(Entity\ReferenceItem::class, 1);
        $em->clear();
        $reference = $em->find(Entity\ReferenceItem::class, 1); // miss + put
        $em->clear();
        $reference = $em->find(Entity\ReferenceItem::class, 1); // hit
        $reference->name = 'after'; $em->flush(); // update + invalidate
        $em->clear();
        $updated = $em->find(Entity\ReferenceItem::class, 1)->name;
        $em->remove($em->find(Entity\ReferenceItem::class, 1)); $em->flush();
        $em->clear();
        $deleted = $em->find(Entity\ReferenceItem::class, 1) === null;
        // A real failed flush closes the manager. The next kernel/job reset must restore it.
        $duplicate = new Entity\Customer(); $duplicate->id = 1; $duplicate->name = 'duplicate'; $em->persist($duplicate);
        try { $em->flush(); } catch (\Doctrine\DBAL\Exception) {}
        return new \Symfony\Component\HttpFoundation\JsonResponse(['customers' => $customers, 'lines' => $lines, 'tags' => $tags, 'updated' => $updated, 'deleted' => $deleted, 'closedAfterFailure' => !$em->isOpen()]);
    }
}
