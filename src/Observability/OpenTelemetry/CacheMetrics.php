<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Observability\OpenTelemetry;

use ModernORM\Cache\Logging\CacheLogger;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterInterface;

/** Global L2 activity, independent of Statistics and Profiler lifecycle. No network I/O. */
final class CacheMetrics implements CacheLogger
{
    private readonly CounterInterface $operations;

    /** @param array<class-string, non-empty-string> $entityLabels Explicit class-to-label allowlist; everything else uses "other". */
    public function __construct(MeterInterface $meter, private readonly array $entityLabels = [])
    {
        foreach ($entityLabels as $class => $label) {
            if (!is_string($class) || $class === '' || !is_string($label) || $label === '' || $label === 'other') {
                throw new \InvalidArgumentException('L2 metric labels require nonempty class-to-label mappings; "other" is reserved.');
            }
        }
        $this->operations = $meter->createCounter('modernorm.l2.operations', '{operation}', 'Observed L2 hits, misses, successful puts and ORM invalidation calls; not backend evictions.');
    }

    public function hit(string $entity): void
    {
        $this->record('hit', $entity);
    }
    public function miss(string $entity): void
    {
        $this->record('miss', $entity);
    }
    public function put(string $entity): void
    {
        $this->record('put', $entity);
    }
    public function evict(string $entity): void
    {
        $this->record('invalidation', $entity);
    }

    private function record(string $operation, string $entity): void
    {
        $this->operations->add(1, ['operation' => $operation, 'entity' => $this->entityLabels[$entity] ?? 'other']);
    }
}
