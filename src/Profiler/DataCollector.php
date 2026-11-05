<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Profiler;

use ModernORM\Statistics;
use ModernORM\Statistics\Snapshot;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use Symfony\Component\HttpKernel\DataCollector\DataCollector as BaseCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DataCollector extends BaseCollector
{
    public function __construct(private readonly Statistics $statistics, private readonly StatisticsCacheLogger $cache, private readonly OperationHistory $history, private readonly bool $l2Enabled, private readonly SqlHistory $sqlHistory) {}

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // Store values only. A profile loaded from disk does not need live services.
        $this->data = [
            'enabled' => $this->statistics->isEnabled(),
            'snapshot' => $this->statistics->snapshot(),
            'l2' => ['enabled' => $this->l2Enabled, 'hits' => $this->cache->getHitCount(), 'misses' => $this->cache->getMissCount(), 'puts' => $this->cache->getPutCount(), 'evictions' => $this->cache->getEvictionCount()],
            'events' => $this->history->events(),
            'sqlEnabled' => $this->sqlHistory->isEnabled(),
            'queries' => $this->sqlHistory->queries(),
            'queriesDropped' => $this->sqlHistory->dropped(),
            'dropped' => $this->history->dropped(),
        ];
    }

    public function isSqlEnabled(): bool { return $this->data['sqlEnabled'] ?? false; }
    public function getQueries(): array { return $this->data['queries'] ?? []; }
    public function getQueriesDropped(): int { return $this->data['queriesDropped'] ?? 0; }
    /** Exact SQL repetitions in retained samples, not a semantic query normalizer. */
    public function getRepeatedQueries(): array
    {
        $groups = [];
        foreach ($this->getQueries() as $query) {
            $key = $query['fingerprint'];
            $groups[$key] ??= ['sql' => $query['sql'], 'count' => 0, 'durationMilliseconds' => 0.0, 'truncated' => $query['truncated']];
            ++$groups[$key]['count'];
            $groups[$key]['durationMilliseconds'] += $query['durationMilliseconds'];
        }
        return array_values(array_filter($groups, static fn (array $group): bool => $group['count'] > 1));
    }
    public function getName(): string { return 'modernorm'; }
    public function isEnabled(): bool { return $this->data['enabled'] ?? false; }
    public function getSnapshot(): Snapshot { return $this->data['snapshot'] ?? new Snapshot(0, 0, 0, 0, 0, 0); }
    public function getL2(): array { return $this->data['l2'] ?? ['enabled' => false, 'hits' => 0, 'misses' => 0, 'puts' => 0, 'evictions' => 0]; }
    public function getEvents(): array { return $this->data['events'] ?? []; }
    public function getDropped(): int { return $this->data['dropped'] ?? 0; }
    public function getErrorCount(): int { return $this->getSnapshot()->sqlErrors + $this->getSnapshot()->flushErrors + $this->getSnapshot()->batch->errors; }
}
