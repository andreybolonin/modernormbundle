<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;
use ModernORM\Bundle\DataTransfer\ValueValidator;

final class SourceReader
{
    public function batches(Connection $source, string $name, array $table, int $batchSize): \Generator
    {
        $platform = $source->getDatabasePlatform();
        $quote = $platform->quoteSingleIdentifier(...);
        $columns = array_column($table['columns'], 'name');
        $keys = $table['source_key'];
        $parameters = [];
        foreach ($keys as $key) {
            $column = $table['source_structure']['columns'][$key];
            $parameters[] = in_array($column['type'], ['smallint', 'integer', 'bigint'], true) ? 'CAST(? AS '.($column['unsigned'] ? 'UNSIGNED' : 'SIGNED').')' : '?';
        }
        $qualified = $quote($source->getDatabase()).'.'.$quote($name);
        $last = null;
        while (true) {
            $cursor = $last === null ? '' : ' WHERE ('.implode(', ', array_map($quote, $keys)).') > ('.implode(', ', $parameters).')';
            $rows = $source->fetchAllAssociative('SELECT '.implode(', ', array_map($quote, $columns)).' FROM '.$qualified.$cursor.' ORDER BY '.implode(', ', array_map($quote, $keys)).' LIMIT '.$batchSize, $last ?? []);
            if ($rows === []) {
                return;
            }
            $values = new ValueValidator();
            foreach ($rows as &$row) {
                foreach ($row as &$value) {
                    $value = $value === null ? null : $values->input($value);
                }
                unset($value);
            }
            unset($row);
            yield $rows;
            $tail = $rows[array_key_last($rows)];
            $last = array_map(static fn (string $key): mixed => $tail[$key], $keys);
        }
    }

    public static function raw(array $row): string
    {
        return json_encode(array_map(static fn (?string $value): ?string => $value === null ? null : base64_encode($value), $row), JSON_THROW_ON_ERROR);
    }
}
