<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Observability\OpenTelemetry;

use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationObserver;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;

/** Records into the application's MeterProvider; never exports or retains events. */
final class OperationMetrics implements OperationObserver
{
    private readonly CounterInterface $operations;
    private readonly HistogramInterface $duration;
    private readonly HistogramInterface $batchSize;
    private readonly CounterInterface $fallbacks;
    private readonly CounterInterface $bulkRows;
    private readonly CounterInterface $hydrationRows;
    private readonly CounterInterface $hydrationEntities;
    private readonly HistogramInterface $managed;

    public function __construct(MeterInterface $meter)
    {
        $this->operations = $meter->createCounter('modernorm.operations', '{operation}', 'Completed operation attempts; scopes overlap.');
        $this->duration = $meter->createHistogram('modernorm.operation.duration', 's', 'Completed operation duration; scopes overlap.', ['ExplicitBucketBoundaries' => [0.0001, 0.0005, 0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5]]);
        $this->batchSize = $meter->createHistogram('modernorm.batch.size', '{reference}', 'Selected references or collections per batch.', ['ExplicitBucketBoundaries' => [1, 2, 4, 8, 16, 32, 64, 128, 256]]);
        $this->fallbacks = $meter->createCounter('modernorm.batch.fallbacks', '{decision}', 'Batch loader fallback decisions, not additional SQL counts.');
        $this->bulkRows = $meter->createCounter('modernorm.bulk.rows', '{row}', 'Known input, affected or returned rows; unknown counts omitted.');
        $this->hydrationRows = $meter->createCounter('modernorm.hydration.rows', '{row}', 'Rows consumed by hydration scopes.');
        $this->hydrationEntities = $meter->createCounter('modernorm.hydration.entities', '{entity}', 'Entities newly created during hydration.');
        $this->managed = $meter->createHistogram('modernorm.unit_of_work.managed', '{entity}', 'Managed entities observed at phase boundaries.', ['ExplicitBucketBoundaries' => [1, 10, 100, 1000, 10000]]);
    }

    public function completed(OperationEvent $event): void
    {
        // No SQL, entity class, association name, identifier, job ID or exception label.
        $operation = $event->kind->value;
        $attributes = ['operation' => $operation, 'outcome' => $event->failed ? 'error' : 'success'];
        if ($operation === 'batch_fallback') {
            $this->fallbacks->add(1, ['reason' => $event->fallbackReason->value ?? 'unknown']);
            return;
        }
        if ($event->batchKind !== null) {
            $attributes['association_kind'] = $event->batchKind->value;
        }
        if (($event->bulk ?? null) !== null) {
            $attributes['bulk_operation'] = $event->bulk->operation->value;
            $attributes['policy'] = $event->bulk->policy->name;
        }
        if (($event->unitOfWork ?? null) !== null) {
            $attributes['phase'] = $event->unitOfWork->phase->value;
        }
        $this->operations->add(1, $attributes);
        $this->duration->record($event->durationNanoseconds / 1_000_000_000, $attributes);
        if ($operation === 'batch') {
            $this->batchSize->record($event->selectedReferences + $event->selectedCollections, ['association_kind' => $event->batchKind->value ?? 'unknown']);
        }
        if (($event->bulk ?? null) !== null) {
            foreach (['input' => $event->bulk->inputRows, 'affected' => $event->bulk->affectedRows, 'returned' => $event->bulk->returnedRows] as $kind => $rows) {
                if ($rows !== null) {
                    $this->bulkRows->add($rows, $attributes + ['row_kind' => $kind]);
                }
            }
        }
        if (($event->hydration ?? null) !== null) {
            $attributes['completed'] = $event->hydration->completed;
            $this->hydrationRows->add($event->hydration->rows, $attributes);
            $this->hydrationEntities->add($event->hydration->entitiesCreated, $attributes);
        }
        if (($event->unitOfWork ?? null) !== null) {
            $this->managed->record($event->unitOfWork->managedEntities, ['phase' => $event->unitOfWork->phase->value]);
        }
    }
}
