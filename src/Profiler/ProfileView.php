<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Profiler;

/** Stable scalar views for saved profiles, including cores without extended Statistics. */
final class ProfileView
{
    public static function values(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) { return $value->value; }
        if ($value instanceof \UnitEnum) { return $value->name; }
        if (is_object($value)) {
            if (!str_starts_with($value::class, 'ModernORM\\Statistics\\')) { return null; }
            $value = get_object_vars($value);
        }
        if (is_array($value)) { return array_map(self::values(...), $value); }
        return is_scalar($value) || $value === null ? $value : null;
    }

    public static function metrics(object $snapshot, array $events = []): array
    {
        $s = self::values($snapshot);
        $timed = static fn (array $v): array => $v + ['timeMs' => ($v['timeNanoseconds'] ?? 0) / 1_000_000];
        $batch = $timed($s['batch'] ?? []);
        $batch['byKind'] = array_map($timed, $batch['byKind'] ?? []);
        $work = null;
        if (isset($s['unitOfWork'])) {
            $phases = [];
            foreach (['change_sets', 'writes', 'commit'] as $phase) {
                $phases[$phase] = $timed(($s['unitOfWork']['byPhase'][$phase] ?? []) + ['count' => 0, 'errors' => 0, 'timeNanoseconds' => 0, 'last' => null]);
            }
            $managed = [];
            foreach ($phases as $phase) { if ($phase['last'] !== null) { $managed[] = $phase['last']['managedEntities']; } }
            foreach ($events as $event) {
                $e = self::values($event);
                if (isset($e['unitOfWork']['managedEntities'])) { $managed[] = $e['unitOfWork']['managedEntities']; }
            }
            $work = ['phases' => $phases, 'observedManagedMax' => $managed === [] ? null : max($managed), 'lastWrites' => $phases['writes']['last']];
        }
        return [
            'sql' => ['count' => $s['sqlCount'], 'errors' => $s['sqlErrors'], 'timeMs' => $s['sqlTimeNanoseconds'] / 1_000_000],
            'flush' => ['count' => $s['flushCount'], 'errors' => $s['flushErrors'], 'timeMs' => $s['flushTimeNanoseconds'] / 1_000_000],
            'batch' => $batch,
            'bulk' => isset($s['bulk']) ? $timed($s['bulk']) : null,
            'hydration' => isset($s['hydration']) ? $timed($s['hydration']) : null,
            'unitOfWork' => $work, 'observerErrors' => $s['observerErrors'] ?? 0,
        ];
    }

    public static function events(array $events): array
    {
        return array_map(static function (object $event): array {
            $values = self::values($event);
            return $values + ['timeMs' => $values['durationNanoseconds'] / 1_000_000, 'bulk' => null, 'hydration' => null, 'unitOfWork' => null];
        }, $events);
    }

    /** Groups only actionable retained evidence; no attribution is inferred from SQL. */
    public static function batchAdviceGroups(array $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            $e = self::values($event);
            $kind = $e['kind'] ?? null;
            if (!in_array($kind, ['batch', 'batch_fallback'], true) || ($e['failed'] ?? false)) { continue; }
            $association = $e['association'] ?? null;
            $entity = $e['entityClass'] ?? null;
            if (($association === null || $association === '') && ($entity === null || $entity === '')) { continue; }
            $size = $e['configuredSize'] ?? 0;
            $fallback = $kind === 'batch_fallback' && ($e['fallbackReason'] ?? null) === 'size_one' && $size === 1;
            $singleton = $kind === 'batch' && $size > 1 && ($e['selectedReferences'] ?? 0) + ($e['selectedCollections'] ?? 0) === 1;
            if (!$fallback && !$singleton) { continue; }
            $key = hash('sha256', serialize([$e['batchKind'] ?? null, $entity, $association, $size]));
            $groups[$key] ??= ['id' => $key, 'kind' => $e['batchKind'] ?? 'unknown', 'entity' => $entity,
                'association' => $association, 'configuredSize' => $size, 'fallbacks' => 0, 'singleLoads' => 0, 'singleTimeMs' => 0.0];
            if ($fallback) { ++$groups[$key]['fallbacks']; }
            if ($singleton) {
                ++$groups[$key]['singleLoads'];
                $groups[$key]['singleTimeMs'] += ($e['durationNanoseconds'] ?? 0) / 1_000_000;
            }
        }
        return array_values($groups);
    }

    public static function repeatedQueries(array $queries): array
    {
        $groups = [];
        foreach ($queries as $index => $query) {
            $key = $query['fingerprint'];
            $groups[$key] ??= ['fingerprint' => $key, 'sql' => $query['sql'], 'count' => 0, 'durationMilliseconds' => 0.0,
                'truncated' => $query['truncated'], 'queryIndex' => $index + 1];
            ++$groups[$key]['count'];
            $groups[$key]['durationMilliseconds'] += $query['durationMilliseconds'];
        }
        return array_values(array_filter($groups, static fn (array $group): bool => $group['count'] > 1));
    }
}
