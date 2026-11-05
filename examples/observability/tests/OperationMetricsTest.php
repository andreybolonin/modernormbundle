<?php

declare(strict_types=1);
namespace ModernORM\Bundle\Example\Observability\Tests;

use ModernORM\Bundle\Observability\OpenTelemetry\FlushMetrics;
use ModernORM\Bundle\Observability\OpenTelemetry\OperationMetrics;
use ModernORM\Statistics;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationKind;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\TestCase;

final class OperationMetricsTest extends TestCase
{
    private function setupMetrics(): array
    {
        $exporter = new InMemoryExporter(temporality: Temporality::DELTA);
        $provider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();
        return [new OperationMetrics($provider->getMeter('modernorm')), $provider, $exporter];
    }

    private function points(InMemoryExporter $exporter, string $name): array
    {
        $points = [];
        foreach ($exporter->collect() as $metric) {
            if ($metric->name === $name) { foreach ($metric->data->dataPoints as $point) { $points[] = $point; } }
        }
        return $points;
    }

    public function testRequestStatisticsResetDoesNotResetDeltaInstruments(): void
    {
        [$observer, $provider, $exporter] = $this->setupMetrics();
        $statistics = new Statistics(true, $observer);
        foreach ([1, 2] as $boundary) {
            $statistics->recordFlush($statistics->start(), false);
            self::assertSame(1, $statistics->snapshot()->flushCount);
            self::assertTrue($provider->forceFlush());
            $points = $this->points($exporter, 'modernorm.operations');
            self::assertCount(1, $points);
            self::assertSame(1, $points[0]->value); // Each export is a delta, never the previous job's total.
            self::assertSame(['operation' => 'flush', 'outcome' => 'success'], $points[0]->attributes->toArray());
            $exporter->collect(true);
            $statistics->reset();
        }
        $provider->shutdown();
        self::assertSame([], $this->points($exporter, 'modernorm.operations'), 'Shutdown must not repeat an already exported delta');
    }

    public function testDurationUsesSecondsAndDoesNotExportSensitiveEventFields(): void
    {
        [$observer, $provider, $exporter] = $this->setupMetrics();
        $observer->completed(new OperationEvent(OperationKind::SQL, 1, 1_500_000, true, entityClass: 'Private\\Customer42', association: 'privateAssociation'));
        self::assertTrue($provider->forceFlush());
        $counter = $this->points($exporter, 'modernorm.operations')[0];
        self::assertSame(['operation' => 'sql', 'outcome' => 'error'], $counter->attributes->toArray());
        $histogram = $this->points($exporter, 'modernorm.operation.duration')[0];
        self::assertEqualsWithDelta(0.0015, $histogram->sum, 0.000000001);
        self::assertSame(1, $histogram->count);
        self::assertContains(0.001, $histogram->explicitBounds);
        $provider->shutdown();
    }

    public function testBulkUnknownRowsAreOmittedAndPureEnumPolicyIsSupported(): void
    {
        [$observer, $provider, $exporter] = $this->setupMetrics();
        $details = new \ModernORM\Statistics\BulkDetails(\ModernORM\Statistics\BulkOperation::INSERT, 'PrivateEntity', \ModernORM\Bulk\BulkPolicy::BYPASS, 12, null, null, null);
        $observer->completed(new OperationEvent(OperationKind::BULK, 1, 100, false, bulk: $details));
        $provider->forceFlush();
        $points = $this->points($exporter, 'modernorm.bulk.rows');
        self::assertCount(1, $points);
        self::assertSame(12, $points[0]->value);
        self::assertSame('input', $points[0]->attributes->toArray()['row_kind']);
        self::assertSame('BYPASS', $points[0]->attributes->toArray()['policy']);
        self::assertArrayNotHasKey('entity', $points[0]->attributes->toArray());
        $provider->shutdown();
    }

    public function testBatchFallbackIsNotCountedAsAnExecutedOperation(): void
    {
        [$observer, $provider, $exporter] = $this->setupMetrics();
        $observer->completed(new OperationEvent(OperationKind::BATCH_FALLBACK, 1, 0, false, fallbackReason: \ModernORM\Statistics\BatchFallbackReason::SIZE_ONE));
        $provider->forceFlush();
        self::assertSame([], $this->points($exporter, 'modernorm.operations'));
        self::assertSame([], $this->points($exporter, 'modernorm.operation.duration'));
        self::assertSame('size_one', $this->points($exporter, 'modernorm.batch.fallbacks')[0]->attributes->toArray()['reason']);
        $provider->shutdown();
    }

    public function testMessengerSuccessAndFailureEventsFlushTheSameProvider(): void
    {
        [$observer, $provider, $exporter] = $this->setupMetrics();
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $dispatcher->addSubscriber(new FlushMetrics($provider->forceFlush(...)));
        $events = [
            new \Symfony\Component\Messenger\Event\WorkerMessageHandledEvent(new \Symfony\Component\Messenger\Envelope(new \stdClass()), 'prototype'),
            new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent(new \Symfony\Component\Messenger\Envelope(new \stdClass()), 'prototype', new \RuntimeException('private failure')),
        ];
        foreach ($events as $event) {
            $observer->completed(new OperationEvent(OperationKind::SQL, 1, 100, false));
            $dispatcher->dispatch($event);
            $points = $this->points($exporter, 'modernorm.operations');
            self::assertCount(1, $points);
            self::assertSame(1, $points[0]->value);
            $exporter->collect(true);
        }
        $provider->shutdown();
    }

    public function testFlushFailureDoesNotEscapeIntoTheApplication(): void
    {
        $listener = new FlushMetrics(static function (): bool { throw new \RuntimeException('private transport exception'); });
        self::assertFalse($listener->flush());
        self::assertSame(1, $listener->failures());
        $calls = 0;
        $listener = new FlushMetrics(static function () use (&$calls): bool { ++$calls; return true; });
        self::assertTrue($listener->flush());
        self::assertSame(1, $calls);
        self::assertSame(0, $listener->failures());
    }
}
