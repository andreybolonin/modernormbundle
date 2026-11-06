<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use ModernORM\Bundle\Observability\CacheLoggerDispatcher;
use ModernORM\Cache\Logging\CacheLogger;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use PHPUnit\Framework\TestCase;

final class CacheLoggerDispatcherTest extends TestCase
{
    public function testTelemetrySurvivesRequestCountersReset(): void
    {
        $request = new StatisticsCacheLogger();
        $process = new StatisticsCacheLogger();
        $dispatcher = new CacheLoggerDispatcher($request, [$process]);
        foreach ([1, 2] as $job) {
            foreach (['hit', 'miss', 'put', 'evict'] as $operation) {
                $dispatcher->$operation('Product');
            }
            foreach (['getHitCount', 'getMissCount', 'getPutCount', 'getEvictionCount'] as $getter) {
                self::assertSame(1, $request->$getter());
                self::assertSame($job, $process->$getter());
            }
            $request->reset();
        }
        self::assertSame(0, $dispatcher->failures());
    }

    public function testFailingLoggerCannotInterruptOtherLoggersOrRequestStatistics(): void
    {
        $request = new StatisticsCacheLogger();
        $healthy = new StatisticsCacheLogger();
        $broken = new class () implements CacheLogger {
            public function hit(string $entity): void
            {
                throw new \RuntimeException('private telemetry failure');
            }
            public function miss(string $entity): void
            {
                $this->hit($entity);
            }
            public function put(string $entity): void
            {
                $this->hit($entity);
            }
            public function evict(string $entity): void
            {
                $this->hit($entity);
            }
        };
        $dispatcher = new CacheLoggerDispatcher($request, [$broken, $healthy]);
        foreach (['hit', 'miss', 'put', 'evict'] as $operation) {
            $dispatcher->$operation('Product');
        }
        foreach (['getHitCount', 'getMissCount', 'getPutCount', 'getEvictionCount'] as $getter) {
            self::assertSame(1, $request->$getter());
            self::assertSame(1, $healthy->$getter());
        }
        self::assertSame(4, $dispatcher->failures());
    }

    public function testLazyServicesAreResolvedBeforeTheFirstCacheCallback(): void
    {
        $logger = new StatisticsCacheLogger();
        $loggers = (static function () use ($logger): iterable {
            yield $logger;
        })();
        $dispatcher = new CacheLoggerDispatcher(new StatisticsCacheLogger(), $loggers);
        self::assertFalse($loggers->valid());
        $dispatcher->hit('Product');
        $dispatcher->hit('Product');
        self::assertSame(2, $logger->getHitCount());
    }

    public function testBundleRoutesOrmCacheLoggerThroughTaggedServices(): void
    {
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new \ModernORM\Bundle\DependencyInjection\ModernORMExtension())->load([], $container);
        $dispatcher = $container->getDefinition('modernorm.cache_logger_dispatcher');
        self::assertSame(CacheLoggerDispatcher::class, $dispatcher->getClass());
        self::assertSame('modernorm.cache_logger', (string) $dispatcher->getArgument(0));
        self::assertSame('modernorm.cache_logger', $dispatcher->getArgument(1)->getTag());
        self::assertFalse($dispatcher->hasTag('kernel.reset'));
        self::assertSame('modernorm.cache_logger_dispatcher', (string) $container->getDefinition('modernorm.configuration')->getArgument(6));
        self::assertSame('modernorm.cache_logger', (string) $container->getAlias(StatisticsCacheLogger::class));
        self::assertSame('modernorm.cache_logger', (string) $container->getDefinition('modernorm.profiler.collector')->getArgument(1));
    }
}
