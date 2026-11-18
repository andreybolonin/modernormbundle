<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;

final class DataValidator
{
    public function validate(Connection $source, Connection $target, array $plan, int $maxExamples, ?TransformationRules $rules = null, int $maxChanges = 100, ?TransformationLog $log = null, ?RuleSuggestions $suggestions = null): array
    {
        $rules ??= TransformationRules::empty();
        $ruleErrors = $rules->errorsForPlan($plan);
        if ($ruleErrors !== []) {
            throw new \InvalidArgumentException(implode(' ', $ruleErrors));
        }
        $journal = new TransformationJournal($rules, 'validation', $maxChanges, $log);
        $report = ['status' => 'complete', 'rows_scanned' => 0, 'invalid_rows' => 0, 'error_count' => 0, 'examples_truncated' => false, 'error_examples' => [], 'tables' => [], 'constraints_checked' => false, 'source_session_timezone' => $source->fetchOne('SELECT @@session.time_zone'), 'target_session_timezone' => $target->fetchOne('SHOW TimeZone')];
        if ($maxExamples < 0) {
            throw new \InvalidArgumentException('Maximum error examples cannot be negative.');
        }
        if ($target->fetchOne('SHOW server_encoding') !== 'UTF8') {
            throw new \RuntimeException('Data validation currently requires a UTF8 PostgreSQL database.');
        }
        $validator = new ValueValidator();
        foreach ($plan['tables'] as $name => $table) {
            $description = (new TargetColumns())->describe($target, $table['target']);
            $columns = $description['columns'];
            $specifications = $description['definitions'];
            $key = $table['primary_key'][0];
            $report['tables'][$name] = ['rows_scanned' => 0, 'invalid_rows' => 0, 'error_count' => 0];
            foreach ((new BatchReader())->batches($source, $name, $table) as $rows) {
                $pending = $failedRows = [];
                foreach ($rows as $rowIndex => $row) {
                    $row = $rules->applyRow($name, $row, [$key => $row[$key]], $journal);
                    ++$report['rows_scanned'];
                    ++$report['tables'][$name]['rows_scanned'];
                    foreach ($table['columns'] as $entry) {
                        $columnName = $entry['name'];
                        $value = $row[$columnName];
                        $column = $columns[$columnName];
                        $definition = $specifications[$columnName];
                        $suggestions?->observe($name, $columnName, $value, $column, in_array($columnName, array_merge($table['primary_key'], $table['protected_columns'] ?? []), true));
                        $error = $validator->check($value, $column, $definition['datetime_precision'] === null ? null : (int) $definition['datetime_precision']);
                        $cell = ['row' => $rowIndex, 'primary_key' => [$key => $this->displayKey($row[$key])], 'column' => $columnName];
                        if ($error !== null) {
                            $this->record($report, $failedRows, $name, $cell, $error, $maxExamples, $suggestions);
                        } elseif ($value !== null && !in_array($column->getTypeName(), ['binary', 'blob'], true)) {
                            $pending[] = $cell + ['value' => $validator->input($value), 'sql_type' => $definition['sql_type']];
                            if (count($pending) === 1000) {
                                $this->checkInputs($target, $pending, $report, $failedRows, $name, $maxExamples, $suggestions);
                                $pending = [];
                            }
                        }
                    }
                }
                $this->checkInputs($target, $pending, $report, $failedRows, $name, $maxExamples, $suggestions);
                $report['invalid_rows'] += count($failedRows);
                $report['tables'][$name]['invalid_rows'] += count($failedRows);
            }
        }
        $report['examples_truncated'] = $report['error_count'] > count($report['error_examples']);
        $report['transformations'] = $journal->report();

        return $report;
    }

    private function checkInputs(Connection $target, array $pending, array &$report, array &$failedRows, string $table, int $maxExamples, ?RuleSuggestions $suggestions): void
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
            $this->record($report, $failedRows, $table, $pending[$index], 'Value is not valid for PostgreSQL '.$pending[$index]['sql_type'].'.', $maxExamples, $suggestions);
        }
    }

    private function record(array &$report, array &$failedRows, string $table, array $cell, string $error, int $maxExamples, ?RuleSuggestions $suggestions): void
    {
        $suggestions?->error($table, $cell['column'], $error);
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
