<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;

/** Shared, bounded and exact keyset iteration for validation and loading. */
final class BatchReader
{
    public function batches(Connection $source, string $name, array $table): \Generator
    {
        $database = $source->getDatabase();
        if ($database === null || $database === '' || $table['primary_key'] === [] || $table['batch_size'] < 1) {
            throw new \InvalidArgumentException('A source database, nonempty primary key and positive batch size are required.');
        }
        if ($source->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $name]) !== 'InnoDB') {
            throw new \RuntimeException($name.': a consistent data scan requires an InnoDB source table.');
        }
        $platform = $source->getDatabasePlatform();
        $keys = $table['primary_key'];
        $parameters = $quotedKeys = [];
        $description = $source->createSchemaManager()->introspectTableByQuotedName($name);
        foreach ($keys as $key) {
            $column = $description->getColumn($key);
            $parameters[] = in_array($column->getTypeName(), ['smallint', 'integer', 'bigint'], true) ? 'CAST(? AS '.($column->getUnsigned() ? 'UNSIGNED' : 'SIGNED').')' : '?';
            $quotedKeys[] = $platform->quoteSingleIdentifier($key);
        }
        $projection = implode(', ', array_map(static fn (array $entry): string => $platform->quoteSingleIdentifier($entry['name']), $table['columns']));
        $qualified = $platform->quoteSingleIdentifier($database).'.'.$platform->quoteSingleIdentifier($name);
        $quotedKey = '('.implode(', ', $quotedKeys).')';
        $parameter = '('.implode(', ', $parameters).')';
        $last = null;
        while (true) {
            $rows = $source->fetchAllAssociative('SELECT '.$projection.' FROM '.$qualified.($last === null ? '' : ' WHERE '.$quotedKey.' > '.$parameter).' ORDER BY '.implode(', ', $quotedKeys).' LIMIT '.$table['batch_size'], $last ?? []);
            if ($rows === []) {
                return;
            }
            yield $rows;
            $lastRow = $rows[array_key_last($rows)];
            $last = array_map(static fn (string $key): mixed => $lastRow[$key], $keys);
        }
    }
}
