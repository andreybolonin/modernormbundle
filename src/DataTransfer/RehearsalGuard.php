<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;

final class RehearsalGuard
{
    public function checkDatabase(Connection $target, ?Connection $application): void
    {
        $applicationName = $application?->getParams()['dbname'] ?? null;
        if (!is_string($applicationName) || $applicationName === '') {
            throw new \InvalidArgumentException('Rehearsal requires an explicitly configured application database name to verify isolation.');
        }
        $name = $target->fetchOne('SELECT current_database()');
        if ($applicationName === $name || in_array($name, ['postgres', 'template0', 'template1'], true)) {
            throw new \InvalidArgumentException('Rehearsal requires a separate non-system database with a name different from the explicitly configured application database.');
        }
        if ($target->fetchOne('SHOW session_replication_role') !== 'origin') {
            throw new \InvalidArgumentException('Rehearsal requires session_replication_role=origin so constraints and triggers run.');
        }
    }

    public function lockTargets(Connection $target, array $plan): void
    {
        $names = array_column($plan['tables'], 'target');
        sort($names);
        foreach ($names as $name) {
            $target->executeStatement('LOCK TABLE '.$name.' IN SHARE ROW EXCLUSIVE MODE');
            if ($target->fetchOne('SELECT 1 FROM '.$name.' LIMIT 1') !== false) {
                throw new \RuntimeException('Rehearsal target is no longer empty: '.$name);
            }
            $relations = 'WITH RECURSIVE targets(oid) AS (SELECT CAST(? AS regclass)::oid UNION SELECT i.inhrelid FROM pg_catalog.pg_inherits i JOIN targets t ON t.oid = i.inhparent)';
            if ((int) $target->fetchOne($relations.' SELECT count(*) FROM pg_catalog.pg_class c JOIN targets t ON t.oid = c.oid WHERE c.relrowsecurity', [$name]) !== 0) {
                throw new \RuntimeException('RLS-enabled rehearsal tables are unsupported: '.$name);
            }
            if ((int) $target->fetchOne($relations." SELECT count(*) FROM pg_catalog.pg_trigger g JOIN targets t ON t.oid = g.tgrelid WHERE g.tgenabled NOT IN ('O', 'A')", [$name]) !== 0) {
                throw new \RuntimeException('Disabled or replica-only triggers prevent a faithful rehearsal: '.$name);
            }
        }
    }
}
