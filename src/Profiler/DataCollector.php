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
    public function __construct(
        private readonly Statistics $statistics,
        private readonly StatisticsCacheLogger $cache,
        private readonly OperationHistory $history,
        private readonly bool $l2Enabled,
        private readonly SqlHistory $sqlHistory,
        private readonly ?PerformanceAdvisor $advisor = null,
        private readonly string $environment = '',
        private readonly ?string $serverVersion = null,
        private readonly bool $advisorEnabled = true,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $snapshot = $this->statistics->snapshot();
        $events = $this->history->events();
        $metrics = ProfileView::metrics($snapshot, $events);
        $l2 = ['enabled' => $this->l2Enabled, 'hits' => $this->cache->getHitCount(), 'misses' => $this->cache->getMissCount(),
            'puts' => $this->cache->getPutCount(), 'evictions' => $this->cache->getEvictionCount()];
        // Only values survive serialization. Saved panels never touch a live manager or connection.
        $this->data = [
            'enabled' => $this->statistics->isEnabled(), 'snapshot' => $snapshot, 'metrics' => $metrics, 'l2' => $l2,
            'events' => $events, 'dropped' => $this->history->dropped(),
            'sqlEnabled' => $this->sqlHistory->isEnabled(), 'queries' => $this->sqlHistory->queries(), 'queriesDropped' => $this->sqlHistory->dropped(),
            'advisorEnabled' => $this->advisorEnabled && $this->statistics->isEnabled(),
            'runtime' => ['environment' => $this->environment ?: 'unknown', 'configuredServerVersion' => $this->serverVersion,
                'coreVersion' => \Composer\InstalledVersions::getPrettyVersion('andreybolonin/modernorm') ?? 'unknown', 'phpVersion' => PHP_VERSION],
        ];
        $this->data['findings'] = $this->data['advisorEnabled']
            ? ($this->advisor ?? new PerformanceAdvisor())->analyze($metrics, $this->data['queries'], $l2, $this->data['queriesDropped'], $events, $this->data['dropped']) : [];
    }

    public function getName(): string
    {
        return 'modernorm';
    }
    public function isEnabled(): bool
    {
        return $this->data['enabled'] ?? false;
    }
    public function getSnapshot(): Snapshot
    {
        return $this->data['snapshot'] ?? new Snapshot(0, 0, 0, 0, 0, 0);
    }
    public function getMetrics(): array
    {
        return ($this->data['metrics'] ?? ProfileView::metrics($this->getSnapshot(), $this->getEvents())) + ['dml' => null];
    }
    public function getRuntime(): array
    {
        return $this->data['runtime'] ?? ['environment' => 'unknown', 'configuredServerVersion' => null, 'coreVersion' => 'unknown', 'phpVersion' => null];
    }
    public function getL2(): array
    {
        return $this->data['l2'] ?? ['enabled' => false, 'hits' => 0, 'misses' => 0, 'puts' => 0, 'evictions' => 0];
    }
    public function getL2HitRate(): ?float
    {
        $cache = $this->getL2();
        $lookups = $cache['hits'] + $cache['misses'];
        return $cache['enabled'] && $lookups > 0 ? 100 * $cache['hits'] / $lookups : null;
    }
    public function getEvents(): array
    {
        return $this->data['events'] ?? [];
    }
    public function getEventRows(?string $kind = null): array
    {
        $rows = ProfileView::events($this->getEvents());
        return $kind === null ? $rows : array_values(array_filter($rows, static fn (array $row): bool => $row['kind'] === $kind));
    }
    public function getDropped(): int
    {
        return $this->data['dropped'] ?? 0;
    }
    public function isSqlEnabled(): bool
    {
        return $this->data['sqlEnabled'] ?? false;
    }
    public function getQueries(): array
    {
        return $this->data['queries'] ?? [];
    }
    public function getQueriesDropped(): int
    {
        return $this->data['queriesDropped'] ?? 0;
    }
    public function getFindings(): array
    {
        return $this->data['findings'] ?? [];
    }
    public function isAdvisorEnabled(): bool
    {
        return $this->data['advisorEnabled'] ?? false;
    }
    public function getFilteredQueries(string $filter = '', bool $errorsOnly = false): array
    {
        $filter = substr($filter, 0, 200);
        $result = [];
        foreach ($this->getQueries() as $index => $query) {
            if (($errorsOnly && !$query['failed']) || ($filter !== '' && stripos($query['sql'], $filter) === false)) {
                continue;
            }
            $result[] = $query + ['index' => $index + 1];
        }
        return $result;
    }
    public function getRepeatedQueries(): array
    {
        return ProfileView::repeatedQueries($this->getQueries());
    }
    public function getSlowestQueries(): array
    {
        $queries = $this->getFilteredQueries();
        usort($queries, static fn (array $a, array $b): int => $b['durationMilliseconds'] <=> $a['durationMilliseconds']);
        return array_slice($queries, 0, 5);
    }
    public function getErrorCount(): int
    {
        $m = $this->getMetrics();
        return $m['sql']['errors'] + $m['flush']['errors'] + ($m['batch']['errors'] ?? 0)
            + ($m['bulk']['errors'] ?? 0) + ($m['hydration']['errors'] ?? 0);
    }
    public function getBatchGroups(): array
    {
        $groups = [];
        foreach ($this->getEventRows('batch') as $event) {
            $key = json_encode([$event['batchKind'], $event['association'], $event['entityClass']]);
            $groups[$key] ??= ['kind' => $event['batchKind'], 'association' => $event['association'], 'entity' => $event['entityClass'],
                'loads' => 0, 'selected' => 0, 'configuredMax' => 0, 'timeMs' => 0.0, 'hits' => 0, 'misses' => 0];
            ++$groups[$key]['loads'];
            $groups[$key]['selected'] += $event['selectedReferences'] + $event['selectedCollections'];
            $groups[$key]['configuredMax'] = max($groups[$key]['configuredMax'], $event['configuredSize']);
            $groups[$key]['timeMs'] += $event['timeMs'];
            $groups[$key]['hits'] += $event['cacheHits'];
            $groups[$key]['misses'] += $event['cacheMisses'];
        }
        return array_values($groups);
    }
    public function getHydrationGroups(): array
    {
        $groups = [];
        foreach ($this->getEventRows('hydration') as $event) {
            if ($event['hydration'] === null) {
                continue;
            }
            $details = $event['hydration'];
            $key = $details['hydrator'];
            $groups[$key] ??= ['hydrator' => $key, 'count' => 0, 'rows' => 0, 'entitiesCreated' => 0, 'timeMs' => 0.0, 'incomplete' => 0];
            ++$groups[$key]['count'];
            $groups[$key]['rows'] += $details['rows'];
            $groups[$key]['entitiesCreated'] += $details['entitiesCreated'];
            $groups[$key]['timeMs'] += $event['timeMs'];
            $groups[$key]['incomplete'] += (int) !$details['completed'];
        }
        return array_values($groups);
    }
}
