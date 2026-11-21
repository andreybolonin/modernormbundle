<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Index\IndexType;
use ModernORM\Bundle\DataTransfer\TypeCompatibility;

final class RekeyPlanner
{
    public function plan(Connection $source, Connection $target, string $schema, array $connections): array
    {
        return $this->fromInventory((new SchemaInspector())->inspect($source, $target, $schema), $schema, $connections);
    }

    public function fromInventory(array $inventory, string $schema, array $connections): array
    {
        $tables = $edges = [];
        $blockers = $inventory['blockers'];
        $incoming = [];
        foreach ($inventory['tables'] as $entry) {
            foreach ($entry['source_structure']['foreign_keys'] as $fk) {
                $incoming[$fk['parent']][] = $fk['referenced_columns'];
            }
        }
        foreach ($inventory['tables'] as $name => $entry) {
            $from = $entry['source_table'];
            $to = $entry['target_table'];
            $sourceKey = $this->primaryKey($from);
            $targetKey = $this->primaryKey($to);
            $sourceColumns = array_keys($entry['source_structure']['columns']);
            $foreignKeys = $entry['source_structure']['foreign_keys'];
            $joinColumns = array_merge(...array_values(array_map(static fn (array $fk): array => $fk['columns'], $foreignKeys)));
            $isJoin = count($foreignKeys) === 2 && count($sourceKey) > 1 && array_diff($sourceColumns, $sourceKey) === [] && count($joinColumns) === count(array_unique($joinColumns)) && array_diff($sourceKey, $joinColumns) === [] && array_diff($joinColumns, $sourceKey) === [];
            $policy = count($sourceKey) > 1 && !$isJoin ? 'surrogate' : 'preserve';
            if ($sourceKey === [] || $targetKey === []) {
                $blockers[] = $name.': source and target primary keys are required.';
            }
            if ($policy === 'surrogate') {
                if (count($targetKey) !== 1 || in_array($targetKey[0], $sourceColumns, true)) {
                    $blockers[] = $name.': target must introduce one new surrogate PK column.';
                }
                if (!$this->hasUnique($to, $sourceKey)) {
                    $blockers[] = $name.': target must retain UNIQUE on the full source PK.';
                }
                foreach ($sourceKey as $column) {
                    if (!in_array($from->getColumn($column)->getTypeName(), ['smallint', 'integer', 'bigint'], true)) {
                        $blockers[] = $name.': surrogate source PK components must be integers.';
                    }
                }
                $id = $targetKey[0] ?? '';
                $sequence = $entry['sequences'][$id] ?? null;
                $settings = $sequence['settings'] ?? [];
                $identity = array_values(array_filter($entry['target_catalog'], static fn (array $c): bool => $c['column_name'] === $id && $c['is_identity'] === 'YES'));
                if ($identity === [] || !$to->hasColumn($id) || $to->getColumn($id)->getTypeName() !== 'bigint' || ($settings['type'] ?? null) !== 'bigint' || ($settings['seqstart'] ?? null) !== '1' || ($settings['seqmin'] ?? null) !== '1' || ($settings['seqincrement'] ?? null) !== '1' || ($settings['seqcycle'] ?? true)) {
                    $blockers[] = $name.': surrogate PK requires owned BIGINT identity, START/MINVALUE 1, INCREMENT 1, NO CYCLE.';
                }
            } elseif (!$isJoin && $targetKey !== $sourceKey) {
                $blockers[] = $name.': preserve entity requires matching single-column PKs.';
            }
            if ($isJoin && isset($incoming[$name])) {
                $blockers[] = $name.': join tables with incoming foreign keys are unsupported.';
            }
            $columns = [];
            foreach ($sourceColumns as $column) {
                if (!$to->hasColumn($column)) {
                    $blockers[] = $name.'.'.$column.': every source column must be retained in target.';
                    continue;
                }
                $check = (new TypeCompatibility())->compare($from->getColumn($column), $to->getColumn($column));
                $integerToDecimal = in_array($from->getColumn($column)->getTypeName(), ['smallint', 'integer', 'bigint'], true) && $to->getColumn($column)->getTypeName() === 'decimal' && $to->getColumn($column)->getScale() === 0;
                if ($check['error'] !== null && !$integerToDecimal) {
                    $blockers[] = $name.'.'.$column.': '.$check['error'];
                }
                if (in_array($column, $sourceKey, true) && !$to->getColumn($column)->getNotnull()) {
                    $blockers[] = $name.'.'.$column.': original PK must remain NOT NULL.';
                }
                $columns[] = ['name' => $column, 'source_type' => $from->getColumn($column)->getTypeName(), 'target_type' => $to->getColumn($column)->getTypeName(), 'conversion' => $integerToDecimal ? 'integer_to_decimal' : $check['conversion']];
            }
            $tables[$name] = ['source' => $entry['source'], 'target' => $entry['target'], 'schema' => $schema, 'policy' => $policy, 'kind' => $isJoin ? 'join_table' : 'entity', 'source_key' => $sourceKey, 'target_key' => $targetKey, 'columns' => $columns, 'definitions' => $entry['definitions'], 'source_structure' => $entry['source_structure'], 'target_structure' => $entry['target_structure'], 'source_catalog' => $entry['source_catalog'], 'target_catalog' => $entry['target_catalog'], 'sequences' => $entry['sequences'], 'constraints' => $entry['constraints'], 'index_catalog' => $entry['index_catalog'], 'rewrite_rules' => $entry['rewrite_rules'], 'triggers' => $entry['triggers'], 'relation' => $entry['relation']];
        }
        $used = [];
        foreach ($tables as $name => $table) {
            foreach ($table['source_structure']['foreign_keys'] as $fkName => $fk) {
                $parent = $tables[$fk['parent']] ?? null;
                $reason = null;
                $remap = $parent !== null && $parent['policy'] === 'surrogate';
                if ($parent === null || ($fk['parent_schema'] ?? $inventory['endpoint_identity']['source']['database']) !== $inventory['endpoint_identity']['source']['database']) {
                    $reason = 'reference outside the source plan';
                } elseif ($fk['referenced_columns'] !== $parent['source_key']) {
                    $reason = 'only full parent primary-key references are supported';
                } elseif ($remap) {
                    foreach ($fk['columns'] as $column) {
                        if ($table['source_structure']['columns'][$column]['nullable'] || !in_array($table['source_structure']['columns'][$column]['type'], ['smallint', 'integer', 'bigint'], true)) {
                            $reason = 'remapped FK components must be non-nullable integers';
                        }
                        if ($table['kind'] !== 'join_table' && in_array($column, $table['source_key'], true)) {
                            $reason = 'remapped FK participates in child entity identity';
                        }
                        foreach ($incoming[$name] ?? [] as $referenced) {
                            if (in_array($column, $referenced, true)) {
                                $reason = 'remapped FK participates in a key with incoming references';
                            }
                        }
                    }
                }
                if (count($fk['columns']) > 1) {
                    foreach ($fk['columns'] as $column) {
                        if ($table['source_structure']['columns'][$column]['nullable']) {
                            $reason = 'nullable composite FK is unsupported';
                        }
                    }
                }
                $candidates = [];
                foreach ($table['target_structure']['foreign_keys'] as $targetName => $targetFk) {
                    if ($parent !== null && $targetFk['parent'] === $fk['parent'] && ($targetFk['parent_schema'] ?? $schema) === $schema && $targetFk['referenced_columns'] === ($remap ? $parent['target_key'] : $fk['referenced_columns']) && ($remap ? count($targetFk['columns']) === 1 : $targetFk['columns'] === $fk['columns'])) {
                        $candidates[$targetName] = $targetFk;
                    }
                }
                $targetFk = count($candidates) === 1 ? reset($candidates) : null;
                $targetName = count($candidates) === 1 ? array_key_first($candidates) : null;
                if ($targetFk === null || isset($used[$name][$targetName])) {
                    $reason = 'missing or ambiguous target FK; each source edge needs one distinct target edge';
                } else {
                    $used[$name][$targetName] = true;
                    foreach ($targetFk['columns'] as $column) {
                        if ($remap && (isset($table['source_structure']['columns'][$column]) || $table['target_structure']['columns'][$column]['type'] !== 'bigint' || $table['target_structure']['columns'][$column]['nullable'])) {
                            $reason = 'remap requires a new NOT NULL BIGINT FK column';
                        }
                    }
                }
                $edges[] = ['name' => $fkName, 'child' => $name, 'parent' => $fk['parent'], 'source_columns' => $fk['columns'], 'referenced_columns' => $fk['referenced_columns'], 'target_columns' => $targetFk['columns'] ?? [], 'target_constraint' => $targetName, 'source_actions' => [$fk['on_update'], $fk['on_delete']], 'target_actions' => $targetFk === null ? [] : [$targetFk['on_update'], $targetFk['on_delete']], 'classification' => $reason !== null ? 'blocked' : ($remap ? 'remap' : 'preserve'), 'reason' => $reason];
            }
        }
        foreach ($tables as $name => $table) {
            $expected = array_column($table['columns'], 'name');
            if ($table['policy'] === 'surrogate') {
                $expected = array_merge($expected, $table['target_key']);
            }
            $joinKey = [];
            foreach ($edges as $edge) {
                if ($edge['child'] === $name) {
                    $expected = array_merge($expected, $edge['target_columns']);
                    $joinKey = array_merge($joinKey, $edge['target_columns']);
                }
            }
            foreach ($table['target_catalog'] as $column) {
                if (!in_array($column['column_name'], $expected, true) || $column['is_generated'] !== 'NEVER' || ($column['is_identity'] === 'YES' && !in_array($column['column_name'], $table['target_key'], true))) {
                    $blockers[] = $name.'.'.$column['column_name'].': unsupported extra/generated target projection.';
                }
            }
            foreach ($table['target_structure']['foreign_keys'] as $fkName => $fk) {
                if (!isset($used[$name][$fkName])) {
                    $blockers[] = $name.'.'.$fkName.': unaccounted target foreign key.';
                }
            }
            if ($table['kind'] === 'join_table' && (count($table['target_key']) !== count($joinKey) || array_diff($table['target_key'], $joinKey) !== [])) {
                $blockers[] = $name.': join-table target PK must contain exactly the resulting FK columns.';
            }
        }
        $graph = (new DependencyGraph())->order($tables, $edges);
        $blockers = array_values(array_unique(array_merge($blockers, $graph['blockers'])));
        if ($tables === []) {
            $blockers[] = 'No source tables can be planned.';
        }
        $sourceFingerprint = array_map(static fn (array $t): array => [$t['source_structure'], $t['source_catalog']], $tables);
        $targetFingerprint = array_map(static fn (array $t): array => [$t['target_structure'], $t['target_catalog'], $t['sequences'], $t['constraints'], $t['index_catalog'], $t['rewrite_rules'], $t['triggers'], $t['relation']], $tables);

        return RekeyPlan::seal(['version' => 1, 'command' => 'modernorm:data:transfer', 'schema_rekey' => true, 'execution' => 'offline_transactional', 'connections' => $connections, 'target_schema' => $schema, 'source_fence' => ['strategy' => 'mysql_global_read_lock', 'timeout_seconds' => 30], 'id_strategy' => ['type' => 'bigint', 'allocation' => 'source_pk_order'], 'endpoint_identity' => $inventory['endpoint_identity'], 'source_session' => $inventory['source_session'], 'fingerprints' => ['source_schema' => RekeyPlan::hash([$inventory['inventory'], $sourceFingerprint, $inventory['external']]), 'target_schema' => RekeyPlan::hash($targetFingerprint)], 'tables' => $tables, 'foreign_keys' => $edges, 'load_order' => $graph['order'], 'blockers' => $blockers]);
    }

    private function primaryKey(Table $table): array
    {
        return array_map(static fn ($column): string => $column->getIdentifier()->getValue(), $table->getPrimaryKeyConstraint()?->getColumnNames() ?? []);
    }

    private function hasUnique(Table $table, array $columns): bool
    {
        foreach ($table->getIndexes() as $index) {
            if ($index->getType() === IndexType::UNIQUE && array_map(static fn ($column): string => $column->getColumnName()->getIdentifier()->getValue(), $index->getIndexedColumns()) === $columns && $index->getPredicate() === null) {
                return true;
            }
        }

        return false;
    }
}
