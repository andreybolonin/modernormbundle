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
        (new TargetGuard())->lockTargets($target, $plan);
    }
}
