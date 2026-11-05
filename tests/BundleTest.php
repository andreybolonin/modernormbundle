<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use ModernORM\Bundle\Tests\Fixtures\Kernel;
use ModernORM\Bundle\Tests\Fixtures\Entity\Product;
use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\Profiler\DataCollector;
use ModernORM\Bundle\Profiler\OperationHistory;
use ModernORM\EntityManagerInterface;
use ModernORM\Statistics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class BundleTest extends TestCase
{
    private ?Kernel $kernel = null;
    private mixed $previousHandler;
    protected function setUp(): void
    {
        $this->previousHandler = set_exception_handler(static function (\Throwable $error): void {});
        restore_exception_handler();
    }
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        // FrameworkBundle installs Symfony ErrorHandler even without a Runtime bootstrap.
        for ($i = 0; $i < 10; ++$i) {
            $current = set_exception_handler(static function (\Throwable $error): void {});
            restore_exception_handler();
            if ($current === $this->previousHandler) { break; }
            restore_exception_handler();
        }
    }
    private function boot(bool $profiling = true, bool $statistics = true, bool $ui = true, bool $sql = false): Kernel
    {
        $this->kernel = new Kernel($profiling, $statistics, $ui, $sql);
        $this->kernel->boot();
        return $this->kernel;
    }
    private function request(Kernel $kernel, string $path): array
    {
        $request = Request::create($path);
        $response = $kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $kernel->terminate($request, $response);
        $token = $response->headers->get('X-Debug-Token');
        return [$response, $token ? $kernel->getContainer()->get('profiler')->loadProfile($token) : null];
    }
    public function testProfilePersistenceAndActualPanelRendering(): void
    {
        $kernel = $this->boot();
        [, $profile] = $this->request($kernel, '/metrics');
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('modernorm');
        self::assertInstanceOf(DataCollector::class, $collector);
        self::assertSame(1, $collector->getSnapshot()->sqlCount);
        self::assertSame(1, $collector->getSnapshot()->flushErrors);
        self::assertSame(5, $collector->getSnapshot()->batch->selectedCollections);
        self::assertCount(2, $collector->getEvents());
        self::assertSame(2, $collector->getDropped());
        self::assertSame(1, $collector->getL2()['hits']);
        [$response] = $this->request($kernel, '/_profiler/'.$profile->getToken().'?panel=modernorm');
        self::assertStringContainsString('Batch fallback decisions', $response->getContent());
        self::assertStringContainsString('size_one', $response->getContent());
        self::assertStringContainsString('events omitted', $response->getContent());
        [$toolbar] = $this->request($kernel, '/_wdt/'.$profile->getToken());
        self::assertStringContainsString('ModernORM', $toolbar->getContent());
        [, $empty] = $this->request($kernel, '/empty');
        self::assertSame(0, $empty->getCollector('modernorm')->getSnapshot()->sqlCount);
        self::assertSame(0, $empty->getCollector('modernorm')->getL2()['hits']);
        self::assertSame(1, $collector->getSnapshot()->sqlCount);
    }
    public function testHistoryIsOffWhenProfilerDoesNotCollect(): void
    {
        $kernel = $this->boot(false);
        [, $profile] = $this->request($kernel, '/metrics');
        self::assertNull($profile);
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertSame(1, $container->get('modernorm.statistics')->snapshot()->sqlCount);
        self::assertSame([], $container->get('modernorm.profiler.history')->events());
    }
    public function testWorksWithoutTwigOrProfiler(): void
    {
        $kernel = $this->boot(false, true, false);
        $this->request($kernel, '/metrics');
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertSame(1, $container->get(Statistics::class)->snapshot()->sqlCount);
        self::assertSame([], $container->get('modernorm.profiler.history')->events());
    }
    public function testDisabledStatistics(): void
    {
        $kernel = $this->boot(true, false);
        [, $profile] = $this->request($kernel, '/metrics');
        $collector = $profile->getCollector('modernorm');
        self::assertFalse($collector->isEnabled());
        self::assertSame(0, $collector->getSnapshot()->sqlCount);
        self::assertSame([], $collector->getEvents());
    }
    public function testManagerSurvivesWorkerResetAndClosedManager(): void
    {
        $kernel = $this->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        $manager = $container->get('test.manager');
        self::assertSame('modernorm_bundle_product', $manager->getClassMetadata(Product::class)->getTableName());
        self::assertTrue($manager->isOpen());
        $manager->close();
        $kernel->getContainer()->get('services_resetter')->reset();
        self::assertTrue($manager->isOpen());
        self::assertSame($manager, $container->get('test.manager'));
    }
    public function testSubrequestsKeepMainRequestCounters(): void
    {
        $kernel = $this->boot();
        [, $profile] = $this->request($kernel, '/nested');
        self::assertSame(2, $profile->getCollector('modernorm')->getSnapshot()->sqlCount);
        self::assertSame(2, $profile->getCollector('modernorm')->getL2()['hits']);
    }
    public function testRegistryAndConsoleIntegration(): void
    {
        $kernel = $this->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        $registry = $container->get('test.registry');
        self::assertSame($container->get('test.manager'), $registry->getManagerForClass(Product::class));
        $registry->getManager()->close();
        self::assertTrue($registry->resetManager()->isOpen());
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $command = $application->find('modernorm:schema:create');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['--dump-sql' => true]));
        self::assertStringContainsString('CREATE TABLE modernorm_bundle_product', $tester->getDisplay());
    }
    public function testMessengerResetClearsCountersAndHistory(): void
    {
        $kernel = $this->boot();
        $this->request($kernel, '/metrics');
        $container = $kernel->getContainer()->get('test.service_container');
        $statistics = $container->get(Statistics::class);
        self::assertSame(1, $statistics->snapshot()->sqlCount);
        $listener = new \Symfony\Component\Messenger\EventListener\ResetServicesListener($kernel->getContainer()->get('services_resetter'));
        $listener->resetServicesAtStop(new \Symfony\Component\Messenger\Event\WorkerStoppedEvent(
            new \Symfony\Component\Messenger\Worker([], new \Symfony\Component\Messenger\MessageBus()),
        ));
        self::assertSame(0, $statistics->snapshot()->sqlCount);
        self::assertSame([], $container->get('modernorm.profiler.history')->events());
        self::assertSame(0, $container->get('modernorm.cache_logger')->getHitCount());
    }
    public function testPostgreSQLMiddlewareAndTransactionCleanup(): void
    {
        if (!getenv('MODERNORM_TEST_URL')) { self::markTestSkipped('Set MODERNORM_TEST_URL for PostgreSQL integration.'); }
        $kernel = $this->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        $manager = $container->get('test.manager');
        $connection = $manager->getConnection();
        $statistics = $container->get(Statistics::class);
        self::assertSame(42, (int) $connection->fetchOne('SELECT 42'));
        self::assertSame(1, $statistics->snapshot()->sqlCount);
        $connection->beginTransaction();
        $kernel->getContainer()->get('services_resetter')->reset();
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertFalse($connection->isConnected());
        self::assertTrue($manager->isOpen());
        self::assertSame(43, (int) $manager->getConnection()->fetchOne('SELECT 43'));
        self::assertSame(1, $statistics->snapshot()->sqlCount);
    }
    public function testSqlDetailsRepeatsLimitsErrorsAndSavedPanel(): void
    {
        if (!getenv('MODERNORM_TEST_URL')) { self::markTestSkipped('Set MODERNORM_TEST_URL.'); }
        $kernel = $this->boot(true, true, true, true);
        [, $profile] = $this->request($kernel, '/sql');
        $collector = $profile->getCollector('modernorm');
        self::assertSame(5, $collector->getSnapshot()->sqlCount);
        self::assertSame(1, $collector->getSnapshot()->sqlErrors);
        self::assertCount(4, $collector->getQueries());
        self::assertSame(1, $collector->getQueriesDropped());
        self::assertSame('22012', $collector->getQueries()[3]['sqlState']);
        self::assertSame(3, $collector->getRepeatedQueries()[0]['count']);
        self::assertStringNotContainsString('private-binding', serialize($collector));
        self::assertSame('execute', $collector->getQueries()[0]['operation']);
        [$response] = $this->request($kernel, '/_profiler/'.$profile->getToken().'?panel=modernorm&modernorm_tab=queries');
        self::assertStringContainsString('SELECT CAST(? AS TEXT)', $response->getContent());
        self::assertStringContainsString('Repeated SQL', $response->getContent());
        self::assertStringContainsString('22012', $response->getContent());
        self::assertCount(4, $collector->getQueries());
        [, $empty] = $this->request($kernel, '/empty');
        self::assertSame([], $empty->getCollector('modernorm')->getQueries());
    }
    public function testSqlHistoryRequiresActiveProfilerButNotStatistics(): void
    {
        if (!getenv('MODERNORM_TEST_URL')) { self::markTestSkipped('Set MODERNORM_TEST_URL.'); }
        $kernel = $this->boot(false, true, true, true);
        $this->request($kernel, '/sql');
        $history = $kernel->getContainer()->get('test.service_container')->get('modernorm.profiler.sql_history');
        self::assertSame([], $history->queries());
        $kernel->shutdown();
        $kernel = $this->boot(true, false, true, true);
        [, $profile] = $this->request($kernel, '/sql');
        self::assertSame(0, $profile->getCollector('modernorm')->getSnapshot()->sqlCount);
        self::assertCount(4, $profile->getCollector('modernorm')->getQueries());
    }
    public function testRealOrmBatchingL2CrudAndNextWorkerJobAfterFailedFlush(): void
    {
        if (!getenv('MODERNORM_TEST_URL')) { self::markTestSkipped('Set MODERNORM_TEST_URL.'); }
        $kernel = $this->boot(true, true, true, true);
        [$response, $profile] = $this->request($kernel, '/orm');
        $values = json_decode($response->getContent(), true);
        self::assertSame(['Customer 1', 'Customer 2', 'Customer 3'], $values['customers']);
        self::assertSame([1, 1, 0], $values['lines']);
        self::assertSame([1, 1, 1], $values['tags']);
        self::assertSame('after', $values['updated']);
        self::assertTrue($values['deleted']);
        self::assertTrue($values['closedAfterFailure']);
        $collector = $profile->getCollector('modernorm');
        foreach (['many_to_one', 'one_to_many', 'many_to_many'] as $kind) {
            self::assertSame(1, $collector->getSnapshot()->batch->byKind[$kind]->count, $kind);
        }
        self::assertGreaterThanOrEqual(1, $collector->getL2()['hits']);
        self::assertGreaterThanOrEqual(2, $collector->getL2()['misses']);
        self::assertGreaterThanOrEqual(2, $collector->getL2()['puts']);
        self::assertGreaterThanOrEqual(1, $collector->getL2()['evictions']);
        self::assertSame(1, $collector->getSnapshot()->flushErrors);
        $container = $kernel->getContainer()->get('test.service_container');
        $manager = $container->get('test.manager');
        $listener = new \Symfony\Component\Messenger\EventListener\ResetServicesListener($kernel->getContainer()->get('services_resetter'));
        $worker = new \Symfony\Component\Messenger\Worker([], new \Symfony\Component\Messenger\MessageBus());
        $listener->resetServices(new \Symfony\Component\Messenger\Event\WorkerRunningEvent($worker, false));
        self::assertTrue($manager->isOpen());
        self::assertSame(0, $container->get(Statistics::class)->snapshot()->sqlCount);
        self::assertSame([], $container->get('modernorm.profiler.sql_history')->queries());
        self::assertSame(9, (int) $manager->getConnection()->fetchOne('SELECT 9'));
        self::assertSame(1, $collector->getSnapshot()->flushErrors);
    }
    public function testEverySavedProfilerTabAndEmptyProfileRenderWithNativeTheme(): void
    {
        $kernel = $this->boot();
        foreach (['/metrics', '/empty'] as $route) {
            [, $profile] = $this->request($kernel, $route);
            foreach (['overview', 'queries', 'unitofwork', 'hydration', 'batch', 'cache', 'bulk', 'postgresql'] as $tab) {
                [$response] = $this->request($kernel, '/_profiler/'.$profile->getToken().'?panel=modernorm&modernorm_tab='.$tab);
                $html = $response->getContent();
                self::assertStringContainsString('data-modernorm-panel="'.$tab.'"', $html);
                self::assertStringContainsString('var(--color-text)', $html);
                self::assertStringContainsString('var(--color-link)', $html);
            }
            [$response] = $this->request($kernel, '/_profiler/'.$profile->getToken().'?panel=modernorm&modernorm_tab=invalid');
            self::assertStringContainsString('data-modernorm-panel="overview"', $response->getContent());
        }
    }
    public function testRepeatedResetsPreserveAnUninitializedManager(): void
    {
        if (!getenv('MODERNORM_TEST_URL')) { self::markTestSkipped('Set MODERNORM_TEST_URL.'); }
        $kernel = $this->boot(false, true, false);
        $container = $kernel->getContainer()->get('test.service_container');
        $manager = $container->get('test.manager');
        $reflection = new \ReflectionClass(\ModernORM\EntityManager::class);
        self::assertTrue($reflection->isUninitializedLazyObject($manager));
        for ($i = 0; $i < 2; ++$i) { $kernel->getContainer()->get('services_resetter')->reset(); }
        self::assertTrue($reflection->isUninitializedLazyObject($manager));
        self::assertSame(42, (int) $manager->getConnection()->fetchOne('SELECT 42'));
        for ($i = 0; $i < 2; ++$i) { $kernel->getContainer()->get('services_resetter')->reset(); }
        self::assertTrue($reflection->isUninitializedLazyObject($manager));
        self::assertTrue($manager->isOpen());
        self::assertSame(43, (int) $manager->getConnection()->fetchOne('SELECT 43'));
        self::assertSame(1, $container->get(Statistics::class)->snapshot()->sqlCount);
    }
    public function testRejectsNonPostgreSQL(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConnectionFactory::create('mysql://localhost/test', null, new Statistics());
    }
}
