<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;

final class SurrogateIdAllocator
{
    public function allocate(Connection $target, array $plan, array $stages): void
    {
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($plan['tables'] as $name => $table) {
            if ($table['policy'] !== 'surrogate') {
                continue;
            }
            $stage = $stages[$name];
            $id = $quote($table['target_key'][0]);
            // Ordinals were assigned in the source's exact PK order, including unsigned BIGINT.
            $target->executeStatement('UPDATE '.$stage['table'].' SET '.$id.' = '.$quote($stage['ordinal']));
            $target->executeStatement('CREATE UNIQUE INDEX ON '.$stage['table'].' ('.$id.')');
            if ((int) $target->fetchOne('SELECT count(*) FROM '.$stage['table'].' WHERE '.$id.' IS NULL OR '.$id.' <> '.$quote($stage['ordinal'])) !== 0) {
                throw new \RuntimeException($name.': incomplete or inconsistent surrogate mapping.');
            }
        }
    }

    public function synchronize(Connection $target, array $plan): array
    {
        $resets = [];
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($plan['tables'] as $name => $table) {
            foreach ($table['sequences'] as $column => $sequence) {
                $settings = $target->fetchAssociative('SELECT seqtypid::regtype::text AS type, seqstart::text, seqincrement::text, seqmin::text, seqmax::text, seqcycle FROM pg_catalog.pg_sequence WHERE seqrelid=CAST(? AS regclass)', [$sequence['name']]);
                if ($settings === false || $settings !== $sequence['settings']) {
                    throw new \RuntimeException($name.': owned sequence settings changed during transfer.');
                }
                $positive = (int) $settings['seqincrement'] > 0;
                $boundary = $target->fetchOne('SELECT '.($positive ? 'MAX' : 'MIN').'('.$quote($column).')::text FROM '.$table['target']);
                $called = $boundary !== null;
                $value = $boundary ?? $settings['seqstart'];
                if (!$target->fetchOne('SELECT CAST(? AS numeric) BETWEEN CAST(? AS numeric) AND CAST(? AS numeric) AND (NOT CAST(? AS boolean) OR CAST(? AS numeric) + CAST(? AS numeric) BETWEEN CAST(? AS numeric) AND CAST(? AS numeric))', [$value, $settings['seqmin'], $settings['seqmax'], $called ? 'true' : 'false', $value, $settings['seqincrement'], $settings['seqmin'], $settings['seqmax']])) {
                    throw new \RuntimeException($name.': owned sequence cannot represent IDs or has no remaining capacity.');
                }
                $resets[] = [$sequence['name'], $value, $called ? 'true' : 'false'];
            }
        }
        // Validate every sequence before making any non-transactional sequence update.
        foreach ($resets as $reset) {
            $target->fetchOne('SELECT pg_catalog.setval(CAST(? AS regclass), CAST(? AS bigint), CAST(? AS boolean))', $reset);
        }

        return array_column($resets, 0);
    }
}
