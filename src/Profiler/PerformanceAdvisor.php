<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Profiler;

/** Evidence-based hints from this saved request. Never executes SQL or claims a proven N+1. */
final class PerformanceAdvisor
{
    public function __construct(private readonly array $options = []) {}

    public function analyze(array $metrics, array $queries, array $l2, int $queriesDropped = 0): array
    {
        if (!($this->options['enabled'] ?? true)) { return []; }
        $findings = [];
        $add = static function (string $id, string $severity, string $title, string $evidence, string $recommendation, string $tab, ?int $query = null) use (&$findings): void {
            $findings[] = compact('id', 'severity', 'title', 'evidence', 'recommendation', 'tab', 'query');
        };
        foreach (ProfileView::repeatedQueries($queries) as $group) {
            if ($group['count'] < ($this->options['repeated_query_threshold'] ?? 5)) { continue; }
            $add('repeated_'.$group['fingerprint'], 'warning', 'Repeated SQL · possible N+1',
                sprintf('%d identical SQL executions in the retained sample (%0.2f ms total). Bindings are not recorded; repetitions alone do not prove N+1.', $group['count'], $group['durationMilliseconds']),
                'Inspect the query group and its call sites. Consider association batch fetching when repeated lazy loads are responsible.', 'queries', $group['queryIndex']);
        }
        $slow = [];
        foreach ($queries as $index => $query) {
            if (!$query['failed'] && $query['durationMilliseconds'] >= ($this->options['slow_query_ms'] ?? 10)) { $slow[] = $query + ['index' => $index + 1]; }
        }
        usort($slow, static fn (array $a, array $b): int => $b['durationMilliseconds'] <=> $a['durationMilliseconds']);
        foreach (array_slice($slow, 0, 3) as $query) {
            $add('slow_'.$query['index'], 'warning', 'Slow SQL execution',
                sprintf('Query #%d took %0.2f ms at the driver boundary. Fetching and hydration are measured separately.', $query['index'], $query['durationMilliseconds']),
                'Review the SQL and inspect a PostgreSQL execution plan in your database tooling. A slow query alone does not identify a missing index.', 'queries', $query['index']);
        }
        $work = $metrics['unitOfWork'];
        if ($work !== null && ($work['observedManagedMax'] ?? 0) >= ($this->options['managed_entities_threshold'] ?? 1000)) {
            $add('large_context', 'warning', 'Large observed UnitOfWork', sprintf('%d managed entities observed at retained phase boundaries; this is not a memory measurement or a guaranteed request peak.', $work['observedManagedMax']),
                'Review context lifetime and processing size. Consider chunked processing or bulk CLEAR when discarding managed state is appropriate.', 'unitofwork');
        }
        if ($work !== null && $work['phases']['change_sets']['timeMs'] >= ($this->options['change_sets_ms'] ?? 10)) {
            $add('dirty_checking', 'warning', 'Costly change-set calculation', sprintf('Change-set phases accumulated %0.2f ms across %d attempts.', $work['phases']['change_sets']['timeMs'], $work['phases']['change_sets']['count']),
                'Inspect the managed context and flush frequency. Use Immutable only for models whose field updates should be ignored.', 'unitofwork');
        }
        foreach ($metrics['batch']['fallbackCounts'] ?? [] as $reason => $count) {
            if ($reason !== 'size_one' || $count < ($this->options['batch_fallback_threshold'] ?? 5)) { continue; }
            $add('batch_'.$reason, 'info', 'Repeated single-load fallback', sprintf('%d size_one fallback decisions were recorded. They describe loader selection, not proven extra queries.', $count),
                'Review the association batchSize where repeated lazy access is expected. A size of one can be an intentional opt-out.', 'batch');
        }
        $lookups = $l2['hits'] + $l2['misses'];
        if ($l2['enabled'] && $lookups >= ($this->options['l2_min_lookups'] ?? 20) && $l2['hits'] / $lookups < ($this->options['l2_min_hit_rate'] ?? 0.5)) {
            $add('l2_reuse', 'info', 'Low L2 reuse in this request', sprintf('%d hits and %d misses (%0.1f%% hit rate). This is one request, not a production trend.', $l2['hits'], $l2['misses'], 100 * $l2['hits'] / $lookups),
                'Check whether this request is warming a cold cache. Assess entity reuse, TTL and invalidation frequency before changing cache policy.', 'cache');
        }
        if (($metrics['bulk']['errors'] ?? 0) > 0) {
            $add('bulk_errors', 'error', 'Bulk operation failed', sprintf('%d bulk attempts failed. An error can occur after database commit; the aggregate does not establish rollback.', $metrics['bulk']['errors']),
                'Inspect retained bulk outcomes and application exceptions before retrying. A committed cache failure requires cache/context recovery.', 'bulk');
        }
        $priority = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($findings, static fn (array $a, array $b): int => $priority[$a['severity']] <=> $priority[$b['severity']] ?: strcmp($a['id'], $b['id']));
        return array_slice($findings, 0, $this->options['max_findings'] ?? 10);
    }
}
