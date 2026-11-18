<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;

final class DataValidator
{
    public function validate(Connection $source, Connection $target, array $plan, int $maxExamples): array
    {
        $report = ['status' => 'complete', 'rows_scanned' => 0, 'invalid_rows' => 0, 'error_count' => 0, 'examples_truncated' => false, 'error_examples' => [], 'tables' => [], 'constraints_checked' => false, 'source_session_timezone' => $source->fetchOne('SELECT @@session.time_zone'), 'target_session_timezone' => $target->fetchOne('SHOW TimeZone')];
        if ($maxExamples < 0) {
            throw new \InvalidArgumentException('Maximum error examples cannot be negative.');
        }
        if ($target->fetchOne('SHOW server_encoding') !== 'UTF8') {
            throw new \RuntimeException('Data validation currently requires a UTF8 PostgreSQL database.');
        }
        $validator = new ValueValidator();
        $database = $source->getDatabase();
        if ($database === null || $database === '') {
            throw new \InvalidArgumentException('A MySQL source database is required.');
        }
        $sourcePlatform = $source->getDatabasePlatform();
        foreach ($plan['tables'] as $name => $table) {
            if (count($table['primary_key']) !== 1) {
                throw new \RuntimeException('Data validation requires a single-column primary key.');
            }
            $engine = $source->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $name]);
            if ($engine !== 'InnoDB') {
                throw new \RuntimeException($name.': a consistent data scan requires an InnoDB source table.');
            }
            $from = $source->createSchemaManager()->introspectTableByQuotedName($name);
            $targetColumns = $target->createSchemaManager()->introspectTable($table['target'])->getColumns();
            $definitions = $target->fetchAllAssociative('SELECT a.attname AS name, pg_catalog.format_type(a.atttypid, a.atttypmod) AS sql_type, c.datetime_precision FROM pg_catalog.pg_attribute a LEFT JOIN information_schema.columns c ON c.table_schema = (SELECT n.nspname FROM pg_catalog.pg_namespace n JOIN pg_catalog.pg_class r ON r.relnamespace = n.oid WHERE r.oid = a.attrelid) AND c.table_name = (SELECT r.relname FROM pg_catalog.pg_class r WHERE r.oid = a.attrelid) AND c.column_name = a.attname WHERE a.attrelid = CAST(? AS regclass) AND a.attnum > 0 AND NOT a.attisdropped', [$table['target']]);
            $specifications = [];
            foreach ($definitions as $definition) {
                $specifications[$definition['name']] = $definition;
            }
            // Index by raw names: DBAL's legacy getColumns() keys can contain quotes.
            $columns = [];
            foreach ($targetColumns as $column) {
                $columns[$column->getObjectName()->getIdentifier()->getValue()] = $column;
            }
            $key = $table['primary_key'][0];
            $quotedKey = $sourcePlatform->quoteSingleIdentifier($key);
            $keyColumn = $from->getColumn($key);
            $parameter = in_array($keyColumn->getTypeName(), ['smallint', 'integer', 'bigint'], true) ? 'CAST(? AS '.($keyColumn->getUnsigned() ? 'UNSIGNED' : 'SIGNED').')' : '?';
            $projection = implode(', ', array_map(static fn (array $column): string => $sourcePlatform->quoteSingleIdentifier($column['name']), $table['columns']));
            $sourceTable = $sourcePlatform->quoteSingleIdentifier($database).'.'.$sourcePlatform->quoteSingleIdentifier($name);
            $last = null;
            $report['tables'][$name] = ['rows_scanned' => 0, 'invalid_rows' => 0, 'error_count' => 0];
            do {
                $rows = $source->fetchAllAssociative('SELECT '.$projection.' FROM '.$sourceTable.($last === null ? '' : ' WHERE '.$quotedKey.' > '.$parameter).' ORDER BY '.$quotedKey.' LIMIT '.$table['batch_size'], $last === null ? [] : [$last]);
                $pending = $failedRows = [];
                foreach ($rows as $rowIndex => $row) {
                    ++$report['rows_scanned'];
                    ++$report['tables'][$name]['rows_scanned'];
                    foreach ($table['columns'] as $entry) {
                        $columnName = $entry['name'];
                        $value = $row[$columnName];
                        $column = $columns[$columnName];
                        $definition = $specifications[$columnName];
                        $error = $validator->check($value, $column, $definition['datetime_precision'] === null ? null : (int) $definition['datetime_precision']);
                        $cell = ['row' => $rowIndex, 'primary_key' => [$key => $this->displayKey($row[$key])], 'column' => $columnName];
                        if ($error !== null) {
                            $this->record($report, $failedRows, $name, $cell, $error, $maxExamples);
                        } elseif ($value !== null && !in_array($column->getTypeName(), ['binary', 'blob'], true)) {
                            $pending[] = $cell + ['value' => $validator->input($value), 'sql_type' => $definition['sql_type']];
                            if (count($pending) === 1000) {
                                $this->checkInputs($target, $pending, $report, $failedRows, $name, $maxExamples);
                                $pending = [];
                            }
                        }
                    }
                }
                $this->checkInputs($target, $pending, $report, $failedRows, $name, $maxExamples);
                $report['invalid_rows'] += count($failedRows);
                $report['tables'][$name]['invalid_rows'] += count($failedRows);
                if ($rows !== []) {
                    $last = $rows[array_key_last($rows)][$key];
                }
            } while ($rows !== []);
        }
        $report['examples_truncated'] = $report['error_count'] > count($report['error_examples']);

        return $report;
    }

    private function checkInputs(Connection $target, array $pending, array &$report, array &$failedRows, string $table, int $maxExamples): void
    {
        if ($pending === []) {
            return;
        }
        $parameters = $values = [];
        foreach ($pending as $index => $cell) {
            $values[] = '('.$index.', CAST(? AS text), CAST(? AS text))';
            $parameters[] = $cell['value'];
            $parameters[] = $cell['sql_type'];
        }
        $invalid = $target->fetchFirstColumn('SELECT id FROM (VALUES '.implode(', ', $values).') AS input(id, value, type) WHERE NOT pg_catalog.pg_input_is_valid(value, type) ORDER BY id', $parameters);
        foreach ($invalid as $index) {
            $this->record($report, $failedRows, $table, $pending[$index], 'Value is not valid for PostgreSQL '.$pending[$index]['sql_type'].'.', $maxExamples);
        }
    }

    private function record(array &$report, array &$failedRows, string $table, array $cell, string $error, int $maxExamples): void
    {
        ++$report['error_count'];
        ++$report['tables'][$table]['error_count'];
        $failedRows[$cell['row']] = true;
        if (count($report['error_examples']) < $maxExamples) {
            $report['error_examples'][] = ['table' => $table, 'primary_key' => $cell['primary_key'], 'column' => $cell['column'], 'error' => $error];
        }
    }

    private function displayKey(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('//u', $text) === 1 && strlen($text) <= 200 ? $text : 'base64:'.base64_encode(substr($text, 0, 150));
    }
}
