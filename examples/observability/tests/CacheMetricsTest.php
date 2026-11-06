<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Example\Observability\Tests;

use ModernORM\Bundle\Observability\CacheLoggerDispatcher;
use ModernORM\Bundle\Observability\OpenTelemetry\CacheMetrics;
use ModernORM\Bundle\Observability\OpenTelemetry\FlushMetrics;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\TestCase;

final class CacheMetricsTest extends TestCase
{
    private function metrics(array $labels = []): array
    {
        $exporter = new InMemoryExporter(temporality: Temporality::DELTA);
        $provider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();
        return [new CacheMetrics($provider->getMeter('modernorm'), $labels), $provider, $exporter];
    }

    private function points(InMemoryExporter $exporter): array
    {
        $points = [];
        foreach ($exporter->collect() as $metric) {
            self::assertSame('modernorm.l2.operations', $metric->name);
            $data = $metric->data;
            if (!$data instanceof \OpenTelemetry\SDK\Metrics\Data\Sum && !$data instanceof \OpenTelemetry\SDK\Metrics\Data\Histogram) {
                self::fail('Expected counter or histogram metric data.');
            }
            foreach ($data->dataPoints as $point) {
                $attributes = $point->attributes->toArray();
                self::assertSame(['operation', 'entity'], array_keys($attributes));
                $points[$attributes['entity']][$attributes['operation']] = $point->value;
            }
        }
        return $points;
    }

    public function testEveryOperationHasBoundedLabelsWithoutLeakingUnknownClasses(): void
    {
        [$metrics, $provider, $exporter] = $this->metrics(['Domain\\Product' => 'product']);
        foreach (['hit', 'miss', 'put', 'evict'] as $operation) {
            $metrics->$operation('Domain\\Product');
        }
        foreach (['Tenant42\\Secret', 'Tenant99\\Secret', 'request-123'] as $unknown) {
            $metrics->miss($unknown);
        }
        self::assertTrue($provider->forceFlush());
        self::assertSame(['product' => ['hit' => 1, 'miss' => 1, 'put' => 1, 'invalidation' => 1], 'other' => ['miss' => 3]], $this->points($exporter));
        $provider->shutdown();
    }

    public function testRequestAndStatisticsResetsDoNotLoseOrReplayCacheEvents(): void
    {
        [$metrics, $provider, $exporter] = $this->metrics(['Product' => 'product']);
        $request = new StatisticsCacheLogger();
        $dispatcher = new CacheLoggerDispatcher($request, [$metrics]);
        $statistics = new \ModernORM\Statistics(false);
        foreach ([1, 2] as $job) {
            $dispatcher->hit('Product');
            $dispatcher->miss('Product');
            $dispatcher->put('Product');
            $dispatcher->evict('Product');
            $request->reset();
            $statistics->reset();
            self::assertSame(0, $request->getHitCount());
            self::assertTrue($provider->forceFlush());
            self::assertSame(['product' => ['hit' => 1, 'miss' => 1, 'put' => 1, 'invalidation' => 1]], $this->points($exporter));
            $exporter->collect(true);
        }
        $provider->shutdown();
        self::assertSame([], $this->points($exporter));
    }

    public function testMultipleCounterResetsBeforeExportRetainEveryEvent(): void
    {
        [$metrics, $provider, $exporter] = $this->metrics();
        $request = new StatisticsCacheLogger();
        $dispatcher = new CacheLoggerDispatcher($request, [$metrics]);
        for ($i = 0; $i < 3; ++$i) {
            $dispatcher->hit('PrivateClass');
            $request->reset();
        }
        $provider->forceFlush();
        self::assertSame(['other' => ['hit' => 3]], $this->points($exporter));
        $provider->shutdown();
    }

    public function testMessengerHandledAndFailedBoundariesFlushCacheCounters(): void
    {
        [$metrics, $provider, $exporter] = $this->metrics();
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $dispatcher->addSubscriber(new FlushMetrics($provider->forceFlush(...)));
        $events = [
            new \Symfony\Component\Messenger\Event\WorkerMessageHandledEvent(new \Symfony\Component\Messenger\Envelope(new \stdClass()), 'test'),
            new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent(new \Symfony\Component\Messenger\Envelope(new \stdClass()), 'test', new \RuntimeException()),
        ];
        foreach ($events as $event) {
            $metrics->evict('Product');
            $dispatcher->dispatch($event);
            self::assertSame(['other' => ['invalidation' => 1]], $this->points($exporter));
            $exporter->collect(true);
        }
        $provider->shutdown();
    }

    public static function invalidLabels(): array
    {
        return [[['Product' => '']], [['Product' => 'other']], [['Product' => 42]], [['' => 'product']], [['product']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLabels')]
    public function testInvalidStaticLabelsFailAtConfigurationTime(array $labels): void
    {
        $provider = MeterProvider::builder()->build();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('class-to-label');
            new CacheMetrics($provider->getMeter('modernorm'), $labels);
        } finally {
            $provider->shutdown();
        }
    }
}
