<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Observability;

use ModernORM\Cache\Logging\CacheLogger;
use ModernORM\Cache\Logging\StatisticsCacheLogger;

/** Keeps request counters separate from optional, process-lived telemetry. */
final class CacheLoggerDispatcher implements CacheLogger
{
    private int $failures = 0;

    /** @var list<CacheLogger> */
    private readonly array $loggers;

    /** @param iterable<CacheLogger> $loggers */
    public function __construct(private readonly StatisticsCacheLogger $statistics, iterable $loggers = [])
    {
        // Resolve lazy tagged services before any cache callback/commit can run.
        $resolved = [];
        foreach ($loggers as $logger) {
            $resolved[] = $logger;
        }
        $this->loggers = $resolved;
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
        $this->record('evict', $entity);
    }

    /** Cumulative failed logger callbacks; independent of request/job resets. */
    public function failures(): int
    {
        return $this->failures;
    }

    private function record(string $operation, string $entity): void
    {
        $this->statistics->$operation($entity);
        foreach ($this->loggers as $logger) {
            try {
                $logger->$operation($entity);
            } catch (\Throwable) {
                // A telemetry failure must not interrupt cache publication, invalidation or commit.
                ++$this->failures;
            }
        }
    }
}
