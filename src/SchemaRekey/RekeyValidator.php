<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;
use ModernORM\Bundle\DataTransfer\TransformationRules;
use ModernORM\Bundle\DataTransfer\TransformationJournal;
use ModernORM\Bundle\DataTransfer\ValueValidator;

final class RekeyValidator
{
    public function source(Connection $source, Connection $target, array $plan, array $stages, int $batchSize, TransformationRules $rules): array
    {
        $counts = [];
        $journal = new TransformationJournal($rules, 'verification', 0);
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($plan['tables'] as $name => $table) {
            $stage = $stages[$name];
            $ordinal = 0;
            foreach ((new SourceReader())->batches($source, $name, $table, min($batchSize, max(1, intdiv(65535, count($table['columns']) + 1)))) as $rows) {
                $stored = $target->fetchFirstColumn('SELECT '.$quote($stage['raw']).'::text FROM '.$stage['table'].' WHERE '.$quote($stage['ordinal']).' > ? AND '.$quote($stage['ordinal']).' <= ? ORDER BY '.$quote($stage['ordinal']), [$ordinal, $ordinal + count($rows)]);
                if (count($stored) !== count($rows)) {
                    throw new \RuntimeException($name.': source/staging coverage mismatch.');
                }
                foreach ($rows as $index => $row) {
                    $expected = json_decode(SourceReader::raw($row), true, flags: JSON_THROW_ON_ERROR);
                    $actual = json_decode($stored[$index], true, flags: JSON_THROW_ON_ERROR);
                    ksort($expected);
                    ksort($actual);
                    if ($expected !== $actual) {
                        throw new \RuntimeException($name.': source/staging raw values differ.');
                    }
                }
                $parameters = $tuples = $comparisons = [];
                foreach ($rows as $index => $row) {
                    $row = $rules->applyRow($name, $row, array_intersect_key($row, array_flip($table['source_key'])), $journal);
                    $parameters[] = (string) ($ordinal + $index + 1);
                    $expressions = ['CAST(? AS bigint)'];
                    foreach ($table['columns'] as $entry) {
                        $column = $entry['name'];
                        $value = $row[$column] === null ? null : (new ValueValidator())->input($row[$column]);
                        $binary = in_array($entry['target_type'], ['binary', 'blob'], true);
                        $parameters[] = $value === null || !$binary ? $value : bin2hex($value);
                        $expressions[] = $binary ? "decode(CAST(? AS text), 'hex')" : 'CAST(? AS '.$table['definitions'][$column]['sql_type'].')';
                    }
                    $tuples[] = '('.implode(', ', $expressions).')';
                }
                $aliases = ['row_id'];
                foreach ($table['columns'] as $index => $entry) {
                    $alias = 'v'.$index;
                    $aliases[] = $alias;
                    $left = 's.'.$quote($entry['name']);
                    $right = 'expected.'.$alias;
                    if (in_array($entry['target_type'], ['string', 'ascii_string', 'text', 'enum', 'guid'], true)) {
                        $left = "convert_to(".$left."::text, 'UTF8')";
                        $right = "convert_to(".$right."::text, 'UTF8')";
                    } elseif (in_array($entry['target_type'], ['json', 'jsonb', 'json_object', 'jsonb_object'], true)) {
                        $left .= '::text';
                        $right .= '::text';
                    }
                    $comparisons[] = $left.' IS NOT DISTINCT FROM '.$right;
                }
                $matches = (int) $target->fetchOne('SELECT count(*) FROM (VALUES '.implode(', ', $tuples).') expected('.implode(', ', $aliases).') JOIN '.$stage['table'].' s ON s.'.$quote($stage['ordinal']).' = expected.row_id WHERE '.implode(' AND ', $comparisons), $parameters);
                if ($matches !== count($rows)) {
                    throw new \RuntimeException($name.': staging values differ from source with the planned rules applied.');
                }
                $ordinal += count($rows);
            }
            if ($ordinal !== $stage['rows'] || (int) $target->fetchOne('SELECT count(*) FROM '.$stage['table']) !== $ordinal) {
                throw new \RuntimeException($name.': missing or extra source/staging rows.');
            }
            $counts[$name] = $ordinal;
        }

        return $counts;
    }

    public function final(Connection $target, array $plan, array $stages): array
    {
        $report = ['tables' => [], 'edges' => [], 'mismatch_count' => 0, 'validation_complete' => false];
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($plan['tables'] as $name => $table) {
            $stage = $stages[$name];
            $keyParts = [];
            foreach ($table['target_key'] as $key) {
                $keyParts[] = 's.'.$quote($key).' = f.'.$quote($key);
            }
            $join = implode(' AND ', $keyParts);
            $same = [];
            foreach ($table['target_structure']['columns'] as $column => $definition) {
                $left = 's.'.$quote($column);
                $right = 'f.'.$quote($column);
                if (in_array($definition['type'], ['string', 'ascii_string', 'text', 'enum', 'guid'], true)) {
                    $left = "convert_to(".$left."::text, 'UTF8')";
                    $right = "convert_to(".$right."::text, 'UTF8')";
                } elseif (in_array($definition['type'], ['json', 'jsonb', 'json_object', 'jsonb_object'], true)) {
                    $left .= '::text';
                    $right .= '::text';
                }
                $same[] = $left.' IS NOT DISTINCT FROM '.$right;
            }
            $missing = (int) $target->fetchOne('SELECT count(*) FROM '.$stage['table'].' s WHERE NOT EXISTS (SELECT 1 FROM '.$table['target'].' f WHERE '.$join.' AND '.implode(' AND ', $same).')');
            $extra = (int) $target->fetchOne('SELECT count(*) FROM '.$table['target'].' f WHERE NOT EXISTS (SELECT 1 FROM '.$stage['table'].' s WHERE '.$join.')');
            $count = (int) $target->fetchOne('SELECT count(*) FROM '.$table['target']);
            if ($missing !== 0 || $extra !== 0 || $count !== $stage['rows']) {
                throw new \RuntimeException($name.': final values, full keys or counts differ from expected projection.');
            }
            if ($table['policy'] === 'surrogate') {
                $id = $quote($table['target_key'][0]);
                if ((int) $target->fetchOne('SELECT count(*) FROM '.$stage['table'].' WHERE '.$id.' IS NULL OR '.$id.' <> '.$quote($stage['ordinal'])) !== 0 || (int) $target->fetchOne('SELECT count(DISTINCT '.$id.') FROM '.$stage['table']) !== $stage['rows']) {
                    throw new \RuntimeException($name.': mapping is not a complete bijection.');
                }
            }
            $report['tables'][$name] = ['source_rows' => $stage['rows'], 'target_rows' => $count, 'raw_verified' => true, 'keys_verified' => true, 'values_verified' => true, 'mapping_verified' => true];
        }
        foreach ($plan['foreign_keys'] as $edge) {
            $child = $plan['tables'][$edge['child']];
            $parent = $plan['tables'][$edge['parent']];
            if ($edge['classification'] === 'remap') {
                $join = (new ForeignKeyRemapper())->join($target, $edge, 'c', 'p');
                $expectedId = 'p.'.$quote($parent['target_key'][0]);
                $actualId = 'c.'.$quote($edge['target_columns'][0]);
                $mismatch = (int) $target->fetchOne('SELECT count(*) FROM '.$child['target'].' c LEFT JOIN '.$parent['target'].' p ON '.$join.' WHERE '.$expectedId.' IS NULL OR '.$actualId.' IS DISTINCT FROM '.$expectedId);
                if ($mismatch !== 0) {
                    throw new \RuntimeException($edge['child'].'.'.$edge['name'].': final FK points to a wrong source parent.');
                }
            }
            $report['edges'][] = ['child' => $edge['child'], 'constraint' => $edge['name'], 'classification' => $edge['classification'], 'verified' => true];
        }
        $target->executeStatement('SET CONSTRAINTS ALL IMMEDIATE');
        $report['validation_complete'] = true;

        return $report;
    }
}
