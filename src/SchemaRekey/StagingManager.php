<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ModernORM\Bundle\DataTransfer\TargetColumns;
use ModernORM\Bundle\DataTransfer\ValueValidator;
use ModernORM\Bundle\DataTransfer\TransformationRules;
use ModernORM\Bundle\DataTransfer\TransformationJournal;

final class StagingManager
{
    public function createAndCopy(Connection $source, Connection $target, array $plan, int $batchSize, TransformationRules $rules, TransformationJournal $journal): array
    {
        $stages = [];
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($plan['tables'] as $name => $table) {
            $token = bin2hex(random_bytes(8));
            $stage = ['table' => 'pg_temp.'.$quote('mr_stage_'.$token), 'raw' => 'mr_raw_'.$token, 'ordinal' => 'mr_row_'.$token, 'rows' => 0];
            $definitions = [];
            foreach ($table['definitions'] as $column => $definition) {
                $definitions[] = $quote($column).' '.$definition['sql_type'];
            }
            $definitions[] = $quote($stage['raw']).' jsonb NOT NULL';
            $definitions[] = $quote($stage['ordinal']).' bigint PRIMARY KEY';
            $definitions[] = 'UNIQUE ('.implode(', ', array_map($quote, $table['source_key'])).')';
            $target->executeStatement('CREATE TEMP TABLE '.$stage['table'].' ('.implode(', ', $definitions).') ON COMMIT DROP');
            $description = (new TargetColumns())->describe($target, $table['target']);
            $limit = min($batchSize, max(1, intdiv(65535, count($table['columns']) + 2)));
            foreach ((new SourceReader())->batches($source, $name, $table, $limit) as $rows) {
                $parameters = $types = $tuples = [];
                foreach ($rows as $row) {
                    $original = $row;
                    $row = $rules->applyRow($name, $row, array_intersect_key($row, array_flip($table['source_key'])), $journal);
                    $expressions = [];
                    foreach ($table['columns'] as $entry) {
                        $column = $entry['name'];
                        $value = $row[$column];
                        $definition = $table['definitions'][$column];
                        $error = (new ValueValidator())->check($value, $description['columns'][$column], $definition['datetime_precision'] === null ? null : (int) $definition['datetime_precision']);
                        if ($error !== null) {
                            throw new \RuntimeException($name.'.'.$column.': '.$error);
                        }
                        $binary = in_array($entry['target_type'], ['binary', 'blob'], true);
                        $parameters[] = $value === null ? null : ($binary ? bin2hex((new ValueValidator())->input($value)) : (new ValueValidator())->input($value));
                        $types[] = $value === null ? ParameterType::NULL : ParameterType::STRING;
                        $expressions[] = $binary ? "decode(CAST(? AS text), 'hex')" : 'CAST(? AS '.$definition['sql_type'].')';
                    }
                    if ($stage['rows'] === PHP_INT_MAX) {
                        throw new \RuntimeException('BIGINT staging ordinal range exhausted.');
                    }
                    ++$stage['rows'];
                    $parameters[] = SourceReader::raw($original);
                    $types[] = ParameterType::STRING;
                    $expressions[] = 'CAST(? AS jsonb)';
                    $parameters[] = (string) $stage['rows'];
                    $types[] = ParameterType::STRING;
                    $expressions[] = 'CAST(? AS bigint)';
                    $tuples[] = '('.implode(', ', $expressions).')';
                }
                $columns = array_merge(array_column($table['columns'], 'name'), [$stage['raw'], $stage['ordinal']]);
                $target->executeStatement('INSERT INTO '.$stage['table'].' ('.implode(', ', array_map($quote, $columns)).') VALUES '.implode(', ', $tuples), $parameters, $types);
            }
            $stages[$name] = $stage;
        }

        return $stages;
    }

    public function load(Connection $target, array $plan, array $stages): array
    {
        $counts = [];
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($plan['load_order'] as $name) {
            $table = $plan['tables'][$name];
            $columns = implode(', ', array_map($quote, array_keys($table['definitions'])));
            $counts[$name] = $target->executeStatement('INSERT INTO '.$table['target'].' ('.$columns.') OVERRIDING SYSTEM VALUE SELECT '.$columns.' FROM '.$stages[$name]['table'].' ORDER BY '.$quote($stages[$name]['ordinal']));
            if ($counts[$name] !== $stages[$name]['rows']) {
                throw new \RuntimeException($name.': final INSERT row count differs from staging.');
            }
        }

        return $counts;
    }
}
