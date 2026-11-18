<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;

/** Transaction-neutral loader shared by rehearsal and future transfer execution. */
final class Loader
{
    public function load(Connection $source, Connection $target, array $plan, int $maxExamples, ?TransformationRules $rules = null, int $maxChanges = 100, ?TransformationLog $log = null): array
    {
        $rules ??= TransformationRules::empty();
        $journal = new TransformationJournal($rules, 'load', $maxChanges, $log);
        $report = ['status' => 'loading', 'stage' => 'preflight', 'table' => null, 'batch' => 0, 'rows_loaded' => 0, 'tables' => [], 'validation' => null, 'constraints_checked' => false, 'sequences_reset' => [], 'target_data_committed' => false];
        try {
            if (!$source->isTransactionActive() || !$target->isTransactionActive()) {
                throw new \RuntimeException('Loading requires active source-snapshot and target transactions.');
            }
            (new RehearsalGuard())->lockTargets($target, $plan);
            $report['stage'] = 'validation';
            $report['validation'] = (new DataValidator())->validate($source, $target, $plan, $maxExamples, $rules, $maxChanges, $log);
            if ($report['validation']['error_count'] !== 0) {
                $report['status'] = 'validation_failed';
                $report['transformations'] = $journal->report();

                return $report;
            }
            $reader = new BatchReader();
            $values = new ValueValidator();
            $platform = $target->getDatabasePlatform();
            foreach ($plan['order'] as $name) {
                $table = $plan['tables'][$name];
                $description = (new TargetColumns())->describe($target, $table['target']);
                $report['stage'] = 'insert';
                $report['table'] = $name;
                $report['batch'] = 0;
                $report['tables'][$name] = ['source_rows' => 0, 'target_rows' => 0, 'keys_verified' => false];
                $key = $table['primary_key'][0];
                $keyType = $description['definitions'][$key]['sql_type'];
                $quotedKey = $platform->quoteSingleIdentifier($key);
                foreach ($reader->batches($source, $name, $table) as $rows) {
                    ++$report['batch'];
                    $parameters = $types = $tuples = $keyInputs = [];
                    foreach ($rows as $row) {
                        $row = $rules->applyRow($name, $row, [$key => $row[$key]], $journal);
                        $expressions = [];
                        $keyInputs[] = $values->input($row[$key]);
                        foreach ($table['columns'] as $entry) {
                            $column = $entry['name'];
                            $raw = $row[$column];
                            $binary = in_array($description['columns'][$column]->getTypeName(), ['binary', 'blob'], true);
                            $text = $raw === null ? null : $values->input($raw);
                            $parameters[] = $binary && $text !== null ? bin2hex($text) : $text;
                            $types[] = $text === null ? ParameterType::NULL : ParameterType::STRING;
                            $expressions[] = $binary ? 'decode(CAST(? AS text), \'hex\')' : 'CAST(? AS '.$description['definitions'][$column]['sql_type'].')';
                        }
                        $tuples[] = '('.implode(', ', $expressions).')';
                    }
                    $expected = $this->canonicalKeys($target, $keyInputs, $keyType);
                    $columns = implode(', ', array_map(static fn (array $entry): string => $platform->quoteSingleIdentifier($entry['name']), $table['columns']));
                    $inserted = $target->fetchFirstColumn('INSERT INTO '.$table['target'].' ('.$columns.') OVERRIDING SYSTEM VALUE VALUES '.implode(', ', $tuples).' RETURNING CAST('.$quotedKey.' AS text)', $parameters, $types);
                    sort($expected, SORT_STRING);
                    sort($inserted, SORT_STRING);
                    if ($inserted !== $expected) {
                        throw new \RuntimeException('A trigger suppressed rows or changed primary keys.');
                    }
                    $report['rows_loaded'] += count($rows);
                    $report['tables'][$name]['source_rows'] += count($rows);
                }
            }
            $report['stage'] = 'constraints';
            $report['table'] = null;
            $target->executeStatement('SET CONSTRAINTS ALL IMMEDIATE');
            foreach ($plan['order'] as $name) {
                $report['stage'] = 'verification';
                $report['table'] = $name;
                $table = $plan['tables'][$name];
                $count = (int) $target->fetchOne('SELECT count(*) FROM '.$table['target']);
                $report['tables'][$name]['target_rows'] = $count;
                if ($count !== $report['tables'][$name]['source_rows']) {
                    throw new \RuntimeException('Source/target row counts differ after triggers.');
                }
                $description = (new TargetColumns())->describe($target, $table['target']);
                $key = $table['primary_key'][0];
                $keyType = $description['definitions'][$key]['sql_type'];
                foreach ($reader->batches($source, $name, $table) as $rows) {
                    $parameters = array_map(static fn (array $row): string => $values->input($row[$key]), $rows);
                    $matches = (int) $target->fetchOne('SELECT count(*) FROM '.$table['target'].' WHERE '.$platform->quoteSingleIdentifier($key).' IN ('.implode(', ', array_fill(0, count($rows), 'CAST(? AS '.$keyType.')')).')', $parameters);
                    if ($matches !== count($rows)) {
                        throw new \RuntimeException('Source primary keys are missing after triggers.');
                    }
                }
                $report['tables'][$name]['keys_verified'] = true;
            }
            $report['constraints_checked'] = true;
            $report['stage'] = 'sequences';
            $report['table'] = null;
            $this->resetSequences($target, $plan, $report);
            $report['status'] = 'loaded';
            $report['transformations'] = $journal->report();

            return $report;
        } catch (\Throwable $error) {
            $report['status'] = 'failed';
            $report['transformations'] = $journal->report();
            $report['sqlstate'] = $error instanceof DriverException ? $error->getSQLState() : null;
            $report['failure_type'] = (new \ReflectionClass($error))->getShortName();
            $reason = $error instanceof \Doctrine\DBAL\Exception ? 'Database rejected rehearsal at stage '.$report['stage'].' (SQLSTATE '.($report['sqlstate'] ?? 'unknown').').' : $error->getMessage();
            throw new LoadFailure($report, $reason);
        }
    }

    private function canonicalKeys(Connection $target, array $values, string $type): array
    {
        return $target->fetchFirstColumn('SELECT CAST(CAST(value AS '.$type.') AS text) FROM (VALUES '.implode(', ', array_fill(0, count($values), '(CAST(? AS text))')).') AS keys(value)', $values);
    }

    private function resetSequences(Connection $target, array $plan, array &$report): void
    {
        $resets = [];
        foreach ($plan['tables'] as $table) {
            $sequence = $table['sequence_after_load'];
            if ($sequence === null) {
                continue;
            }
            $settings = $target->fetchAssociative('SELECT seqincrement, seqstart, seqmin, seqmax FROM pg_catalog.pg_sequence WHERE seqrelid = CAST(? AS regclass)', [$sequence]);
            if ($settings === false) {
                throw new \RuntimeException('Owned sequence disappeared before reset.');
            }
            $positive = (int) $settings['seqincrement'] > 0;
            $key = $target->getDatabasePlatform()->quoteSingleIdentifier($table['primary_key'][0]);
            $boundary = $target->fetchOne('SELECT '.($positive ? 'MAX' : 'MIN').'('.$key.') FROM '.$table['target']);
            $called = $boundary !== null;
            if ($called) {
                $valid = $target->fetchOne('SELECT CAST(? AS numeric) BETWEEN CAST(? AS numeric) AND CAST(? AS numeric)', [$boundary, $settings['seqmin'], $settings['seqmax']]);
                if (!$valid) {
                    if (!$target->fetchOne('SELECT CAST(? AS numeric) '.($positive ? '<' : '>').' CAST(? AS numeric)', [$boundary, $positive ? $settings['seqmin'] : $settings['seqmax']])) {
                        throw new \RuntimeException('Copied identifiers exceed the owned sequence range.');
                    }
                    $boundary = $settings['seqstart'];
                    $called = false;
                } elseif (!$target->fetchOne('SELECT CAST(? AS numeric) + CAST(? AS numeric) BETWEEN CAST(? AS numeric) AND CAST(? AS numeric)', [$boundary, $settings['seqincrement'], $settings['seqmin'], $settings['seqmax']])) {
                    throw new \RuntimeException('Owned sequence has no capacity after the copied identifiers.');
                }
            }
            $resets[] = [$sequence, $boundary ?? $settings['seqstart'], $called ? 'true' : 'false'];
        }
        foreach ($resets as $parameters) {
            $target->fetchOne('SELECT pg_catalog.setval(CAST(? AS regclass), CAST(? AS bigint), CAST(? AS boolean))', $parameters);
            $report['sequences_reset'][] = $parameters[0];
        }
    }
}
