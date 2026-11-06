<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Profiler;

/** Evidence-based hints from this saved request. Never executes SQL or claims a proven N+1. */
final class PerformanceAdvisor
{
    public function __construct(private readonly array $options = []) {}

    public function analyze(array $metrics, array $queries, array $l2, int $queriesDropped = 0, array $events = [], int $eventsDropped = 0): array
    {
        if (!($this->options['enabled'] ?? true)) { return []; }
        $findings = [];
        $add = static function (string $id, string $severity, string $title, string $evidence, string $recommendation, string $tab, ?int $query = null, float $timeMs = 0) use (&$findings): void {
            $findings[] = compact('id', 'severity', 'title', 'evidence', 'recommendation', 'tab', 'query', 'timeMs');
        };
        $sampleNote = $queriesDropped > 0 ? sprintf(' %d SQL entries were omitted; counts and timings cover retained entries only.', $queriesDropped) : '';
        foreach (ProfileView::repeatedQueries($queries) as $group) {
            if ($group['count'] < ($this->options['repeated_query_threshold'] ?? 5)) { continue; }
            $add('repeated_'.$group['fingerprint'], 'warning', 'Repeated SQL · possible N+1',
                sprintf('%d identical SQL executions in the retained sample (%0.2f ms total). Bindings are not recorded; repetitions alone do not prove N+1.', $group['count'], $group['durationMilliseconds']) . $sampleNote,
                'Inspect the query group and its call sites. Consider association batch fetching when repeated lazy loads are responsible.', 'queries', $group['queryIndex'], $group['durationMilliseconds']);
        }
        $slow = [];
        foreach ($queries as $index => $query) {
            if (!$query['failed'] && $query['durationMilliseconds'] >= ($this->options['slow_query_ms'] ?? 10)) { $slow[] = $query + ['index' => $index + 1]; }
        }
        usort($slow, static fn (array $a, array $b): int => $b['durationMilliseconds'] <=> $a['durationMilliseconds']);
        foreach (array_slice($slow, 0, 3) as $query) {
            $add('slow_'.$query['index'], 'warning', 'Slow SQL execution',
                sprintf('Query #%d took %0.2f ms at the driver boundary. Fetching and hydration are measured separately.', $query['index'], $query['durationMilliseconds']) . $sampleNote,
                'Review the SQL and inspect a PostgreSQL execution plan in your database tooling. A slow query alone does not identify a missing index.', 'queries', $query['index'], $query['durationMilliseconds']);
        }
        $flush = $metrics['flush'] ?? null;
        if ($flush !== null && $flush['count'] - $flush['errors'] >= ($this->options['flush_count_threshold'] ?? 5)) {
            $add('repeated_flush', 'warning', 'Repeated flush() calls',
                sprintf('%d successful flush attempts out of %d (%d failed), accumulating %0.2f ms in this request. Counts alone do not prove a flush inside a loop or unnecessary writes.', $flush['count'] - $flush['errors'], $flush['count'], $flush['errors'], $flush['timeMs']),
                'Review flush call sites and transaction boundaries. Group changes into fewer flushes only when business semantics allow it; for large jobs consider explicit chunks. Inspect failures before retrying.', 'unitofwork', timeMs: $flush['timeMs']);
        }
        $work = $metrics['unitOfWork'] ?? null;
        if ($work !== null && ($work['observedManagedMax'] ?? 0) >= ($this->options['managed_entities_threshold'] ?? 1000)) {
            $add('large_context', 'warning', 'Large observed UnitOfWork', sprintf('%d managed entities observed at retained phase boundaries; this is not a memory measurement or a guaranteed request peak.', $work['observedManagedMax']),
                'Review context lifetime and processing size. Consider chunked processing or bulk CLEAR when discarding managed state is appropriate.', 'unitofwork');
        }
        if ($work !== null && $work['phases']['change_sets']['timeMs'] >= ($this->options['change_sets_ms'] ?? 10)) {
            $add('dirty_checking', 'warning', 'Costly change-set calculation', sprintf('Change-set phases accumulated %0.2f ms across %d attempts.', $work['phases']['change_sets']['timeMs'], $work['phases']['change_sets']['count']),
                'Inspect the managed context and flush frequency. Use Immutable only for models whose field updates should be ignored.', 'unitofwork', timeMs: $work['phases']['change_sets']['timeMs']);
        }
        $hasAttributedFallback = false;
        $eventNote = $eventsDropped > 0 ? sprintf(' %d operation events were omitted; these counts cover retained events only.', $eventsDropped) : '';
        foreach (ProfileView::batchAdviceGroups($events) as $group) {
            $subject = $group['association'] ?: 'target '.$group['entity'].' (source association not recorded)';
            if ($group['fallbacks'] >= ($this->options['batch_fallback_threshold'] ?? 5)) {
                $hasAttributedFallback = true;
                $add('batch_size_one_'.$group['id'], 'info', 'Repeated single-load fallback · '.$subject,
                    sprintf('%s: %d size_one fallback decisions in retained events; configured batchSize %d (%s). These are loader decisions, not SQL counts.', $subject, $group['fallbacks'], $group['configuredSize'], $group['kind']) . $eventNote,
                    'Review batchSize for this association or target class where repeated lazy access is expected. A size of one can be an intentional opt-out.', 'batch');
            }
            if ($group['singleLoads'] >= ($this->options['batch_singleton_threshold'] ?? 5)) {
                $add('batch_singleton_'.$group['id'], 'info', 'Repeated one-item batches · '.$subject,
                    sprintf('%s: %d successful batches selected one reference or collection each, with configured batchSize %d (%s); %0.2f ms in retained events. Selected items are not database row or SQL counts.', $subject, $group['singleLoads'], $group['configuredSize'], $group['kind'], $group['singleTimeMs']) . $eventNote,
                    'Review when references or collection owners enter the loading queue and when they are accessed. A larger batchSize alone may not help; isolated access can be intentional. These events do not prove N+1.', 'batch', timeMs: $group['singleTimeMs']);
            }
        }
        $fallbackCount = $metrics['batch']['fallbackCounts']['size_one'] ?? 0;
        if (!$hasAttributedFallback && $fallbackCount >= ($this->options['batch_fallback_threshold'] ?? 5)) {
            $add('batch_size_one', 'info', 'Repeated single-load fallback', sprintf('%d size_one fallback decisions were recorded in aggregate. No individual association or target reached the threshold in retained events. Decisions do not prove extra queries.', $fallbackCount),
                'Review the association batchSize where repeated lazy access is expected. A size of one can be an intentional opt-out. Inspect event-history coverage before attributing this aggregate to a specific association.', 'batch');
        }
        $hydration = $metrics['hydration'] ?? null;
        if ($hydration !== null && ($hydration['timeMs'] ?? 0) > 0 && $hydration['timeMs'] >= ($this->options['hydration_ms'] ?? 50)) {
            $add('hydration_time', 'warning', 'Costly hydration',
                sprintf('Active hydration accumulated %0.2f ms across %d attempts (%d errors, %d incomplete). This scope can overlap SQL and batch timings; do not add them.', $hydration['timeMs'], $hydration['count'], $hydration['errors'], $hydration['incomplete']),
                'Inspect hydration groups and the selected projection. Consider scalar results or smaller result sets when managed entities are unnecessary.', 'hydration', timeMs: $hydration['timeMs']);
        }
        if ($hydration !== null && ($hydration['entitiesCreated'] ?? 0) >= ($this->options['hydrated_entities_threshold'] ?? 1000)) {
            $add('hydration_entities', 'warning', 'Many entities created during hydration',
                sprintf('%d new entity instances were created across %d hydration attempts. This is not a count of distinct database entities, retained objects or peak memory.', $hydration['entitiesCreated'], $hydration['count']),
                'Review result size and fetched associations. Consider pagination or scalar projections; for batch jobs, consider chunking with an appropriate context reset.', 'hydration');
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
        usort($findings, static fn (array $a, array $b): int => $priority[$a['severity']] <=> $priority[$b['severity']] ?: $b['timeMs'] <=> $a['timeMs'] ?: strcmp($a['id'], $b['id']));
        return array_map(static function (array $finding): array {
            unset($finding['timeMs']);
            return $finding;
        }, array_slice($findings, 0, $this->options['max_findings'] ?? 10));
    }
}
