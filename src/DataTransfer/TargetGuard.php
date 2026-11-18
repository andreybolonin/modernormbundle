<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;

final class TargetGuard
{
    public function checkDatabase(Connection $target): void
    {
        if (in_array($target->fetchOne('SELECT current_database()'), ['template0', 'template1'], true)) {
            throw new \InvalidArgumentException('Template databases cannot be transfer targets.');
        }
        if ($target->fetchOne('SHOW session_replication_role') !== 'origin') {
            throw new \InvalidArgumentException('Transfer requires session_replication_role=origin so constraints and triggers run.');
        }
    }

    public function lockTargets(Connection $target, array $plan): void
    {
        $names = array_column($plan['tables'], 'target');
        sort($names);
        foreach ($names as $name) {
            $target->executeStatement('LOCK TABLE '.$name.' IN SHARE ROW EXCLUSIVE MODE');
            if ($target->fetchOne('SELECT 1 FROM '.$name.' LIMIT 1') !== false) {
                throw new \RuntimeException('Transfer target is no longer empty: '.$name);
            }
            $relations = 'WITH RECURSIVE targets(oid) AS (SELECT CAST(? AS regclass)::oid UNION SELECT i.inhrelid FROM pg_catalog.pg_inherits i JOIN targets t ON t.oid = i.inhparent)';
            if ((int) $target->fetchOne($relations.' SELECT count(*) FROM pg_catalog.pg_class c JOIN targets t ON t.oid = c.oid WHERE c.relrowsecurity', [$name]) !== 0) {
                throw new \RuntimeException('RLS-enabled transfer tables are unsupported: '.$name);
            }
            if ((int) $target->fetchOne($relations." SELECT count(*) FROM pg_catalog.pg_trigger g JOIN targets t ON t.oid = g.tgrelid WHERE g.tgenabled NOT IN ('O', 'A')", [$name]) !== 0) {
                throw new \RuntimeException('Disabled or replica-only triggers prevent a faithful transfer: '.$name);
            }
        }
    }
}
