<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;

/** No reconnect or best-effort fallback: loss of this session loses the fence. */
final class SourceFence
{
    public function acquire(Connection $source): string
    {
        $source->executeStatement('SET SESSION lock_wait_timeout = 30');
        $source->executeStatement('FLUSH TABLES WITH READ LOCK');

        return (string) $source->fetchOne('SELECT CONNECTION_ID()');
    }

    public function assertHeld(Connection $source, string $session): void
    {
        if (!$source->isTransactionActive() || (string) $source->fetchOne('SELECT CONNECTION_ID()') !== $session) {
            throw new \RuntimeException('Source fence/snapshot was lost; inspect both databases before retrying.');
        }
    }

    public function release(Connection $source): void
    {
        $source->executeStatement('UNLOCK TABLES');
    }
}
