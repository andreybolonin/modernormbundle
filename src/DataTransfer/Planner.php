<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Column;
use ModernORM\Bundle\ReverseEngineering\MySqlSchemaReader;
use ModernORM\Mapping\ClassMetadata;

final class Planner
{
    /** @param list<ClassMetadata> $metadata */
    public function plan(Connection $source, Connection $target, array $metadata, int $batchSize): array
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be positive.');
        }
        $sourcePlatform = $source->getDatabasePlatform();
        $platform = $target->getDatabasePlatform();
        if (!$sourcePlatform instanceof AbstractMySQLPlatform || $sourcePlatform instanceof MariaDBPlatform || !$platform instanceof PostgreSQLPlatform) {
            throw new \InvalidArgumentException('Transfer planning requires MySQL as source and PostgreSQL as target.');
        }
        $database = $source->getDatabase();
        if ($database === null || $database === '') {
            throw new \InvalidArgumentException('Source URL must select a MySQL database.');
        }
        $errors = $warnings = $tables = $dependencies = $mapped = $destinations = [];
        $names = $source->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$database]);
        $snapshot = (new MySqlSchemaReader($source))->read($database, []);
        $warnings = $snapshot['warnings'];
        foreach ($metadata as $class) {
            if ($class->isMappedSuperclass || $class->isEmbeddedClass) {
                continue;
            }
            $name = $class->getTableName();
            if (isset($mapped[$name])) {
                $errors[] = $name.': multiple entities map this source table name; explicit renaming is unsupported.';
                continue;
            }
            $mapped[$name] = $class;
        }
        foreach ($names as $name) {
            if (!isset($mapped[$name])) {
                $errors[] = $name.': source table has no entity mapping; its data would be omitted.';
            }
            if (!isset($snapshot['tables'][$name])) {
                $errors[] = $name.': source table was skipped by schema introspection; manual mapping is required.';
            }
        }
        foreach ($mapped as $name => $class) {
            if (!in_array($name, $names, true)) {
                $errors[] = $name.': mapped table is missing from the MySQL source.';
                continue;
            }
            if (!isset($snapshot['tables'][$name])) {
                continue;
            }
            $from = $snapshot['tables'][$name];
            $schema = $class->table['schema'] ?? 'public';
            $destination = $platform->quoteSingleIdentifier($schema).'.'.$platform->quoteSingleIdentifier($name);
            $destinations[$destination] = $name;
            $dependencies[$name] = [];
            if (!$target->fetchOne("SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ? AND c.relkind IN ('r', 'p')", [$schema, $name])) {
                $errors[] = $name.': target table '.$destination.' does not exist. Create/review the PostgreSQL schema first.';
                continue;
            }
            $to = $target->createSchemaManager()->introspectTableByQuotedName($name, $schema);
            if ($target->fetchOne('SELECT 1 FROM '.$destination.' LIMIT 1') !== false) {
                $errors[] = $name.': target table is not empty.';
            }
            $sourceKey = $this->primaryColumns($from);
            $targetKey = $this->primaryColumns($to);
            if (count($sourceKey) !== 1 || $targetKey !== $sourceKey || $sourceKey !== $class->getIdentifierColumnNames()) {
                $errors[] = $name.': requires matching single-column primary keys in source, mapping and target.';
            }
            $columnNames = $class->getColumnNames();
            foreach ($class->fieldMappings as $field) {
                if ($to->hasColumn($field->columnName)) {
                    $check = (new TypeCompatibility())->compare(new Column($field->columnName, $field->type), $to->getColumn($field->columnName));
                    if ($check['error'] !== null) {
                        $errors[] = $name.'.'.$field->columnName.': entity mapping does not match target type. '.$check['error'];
                    }
                }
            }
            foreach ($class->associationMappings as $association) {
                if ($association->isToOneOwningSide()) {
                    foreach ($association->joinColumns as $join) {
                        $columnNames[] = $join->name;
                    }
                } elseif ($association->isManyToMany()) {
                    $errors[] = $name.': ManyToMany join-table transfer is not supported in this first version.';
                }
            }
            $columnNames = array_values(array_unique($columnNames));
            $columns = [];
            foreach ($from->getColumns() as $column) {
                $columnName = $column->getObjectName()->getIdentifier()->getValue();
                if (!in_array($columnName, $columnNames, true)) {
                    $errors[] = $name.'.'.$columnName.': source column has no mapping; its data would be omitted.';
                }
            }
            foreach ($columnNames as $columnName) {
                if (!$from->hasColumn($columnName) || !$to->hasColumn($columnName)) {
                    $errors[] = $name.'.'.$columnName.': mapped column is missing from source or target.';
                    continue;
                }
                $check = (new TypeCompatibility())->compare($from->getColumn($columnName), $to->getColumn($columnName));
                $columns[] = ['name' => $columnName, 'conversion' => $check['conversion'], 'source_type' => $from->getColumn($columnName)->getTypeName(), 'target_type' => $to->getColumn($columnName)->getTypeName()];
                if ($check['error'] !== null) {
                    $errors[] = $name.'.'.$columnName.': '.$check['error'];
                }
                if ($check['warning'] !== null) {
                    $warnings[] = $name.'.'.$columnName.': '.$check['warning'];
                }
            }
            $generatedColumns = $target->fetchAllAssociative('SELECT column_name, is_generated, is_identity FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$schema, $name]);
            $serverGenerated = [];
            foreach ($generatedColumns as $column) {
                if ($column['is_generated'] !== 'NEVER' || $column['is_identity'] === 'YES') {
                    $serverGenerated[] = $column['column_name'];
                }
                if ($column['is_generated'] !== 'NEVER' && in_array($column['column_name'], $columnNames, true)) {
                    $errors[] = $name.'.'.$column['column_name'].': target generated columns cannot receive copied values.';
                }
            }
            foreach ($to->getColumns() as $column) {
                $columnName = $column->getObjectName()->getIdentifier()->getValue();
                if (!in_array($columnName, $columnNames, true) && !in_array($columnName, $serverGenerated, true) && $column->getNotnull() && $column->getDefault() === null && !$column->getAutoincrement()) {
                    $errors[] = $name.'.'.$columnName.': required target column has no source mapping or default.';
                }
            }
            $sequence = count($targetKey) === 1 ? $target->fetchOne('SELECT pg_catalog.pg_get_serial_sequence(?, ?)', [$destination, $targetKey[0]]) : null;
            $effectiveBatchSize = min($batchSize, max(1, intdiv(65535, max(1, count($columns)))));
            $tables[$name] = ['source' => $database.'.'.$name, 'target' => $destination, 'entity' => $class->name, 'primary_key' => $sourceKey, 'protected_columns' => $sourceKey, 'columns' => $columns, 'batch_size' => $effectiveBatchSize, 'sequence_after_load' => $sequence ?: null];
        }
        foreach ($tables as $name => $table) {
            $class = $mapped[$name];
            $schema = $class->table['schema'] ?? 'public';
            $to = $target->createSchemaManager()->introspectTableByQuotedName($name, $schema);
            foreach ($to->getForeignKeys() as $fk) {
                $parent = $fk->getReferencedTableName();
                $qualified = $platform->quoteSingleIdentifier($parent->getQualifier()?->getValue() ?? $schema).'.'.$platform->quoteSingleIdentifier($parent->getUnqualifiedName()->getValue());
                if (!isset($destinations[$qualified], $tables[$destinations[$qualified]])) {
                    $errors[] = $name.': foreign key references a target outside the plan: '.$qualified;
                    continue;
                }
                $dependencies[$name][] = $destinations[$qualified];
                foreach ($fk->getReferencingColumnNames() as $column) {
                    $tables[$name]['protected_columns'][] = $column->getIdentifier()->getValue();
                }
                foreach ($fk->getReferencedColumnNames() as $column) {
                    $tables[$destinations[$qualified]]['protected_columns'][] = $column->getIdentifier()->getValue();
                }
            }
            foreach ($snapshot['tables'][$name]->getForeignKeys() as $fk) {
                $parent = $fk->getReferencedTableName();
                $parentName = $parent->getUnqualifiedName()->getValue();
                if (($parent->getQualifier()?->getValue() ?? $database) !== $database || !isset($tables[$parentName])) {
                    $errors[] = $name.': source FK references a table outside the plan: '.$parent->toString();
                    continue;
                }
                $dependencies[$name][] = $parentName;
                foreach ($fk->getReferencingColumnNames() as $column) {
                    $tables[$name]['protected_columns'][] = $column->getIdentifier()->getValue();
                }
                foreach ($fk->getReferencedColumnNames() as $column) {
                    $tables[$parentName]['protected_columns'][] = $column->getIdentifier()->getValue();
                }
            }
        }
        $sorted = (new DependencyOrder())->sort($dependencies);
        if ($sorted['blocked'] !== []) {
            $errors[] = 'Cyclic or unresolved foreign-key dependencies: '.implode(', ', $sorted['blocked']).'.';
        }
        if ($names === [] || $mapped === []) {
            $errors[] = 'Source database or entity mapping is empty; no transfer can be planned.';
        }

        return ['mode' => 'dry-run', 'data_scanned' => false, 'requirements_for_future_load' => ['Stop source writes.', 'Recheck that target tables are empty.', 'Validate every value, counts and referential integrity.', 'Preserve IDs and reset owned sequences after loading.', 'Partial-transfer resume is unsupported.'], 'tables' => $tables, 'order' => $sorted['order'], 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /** @return list<string> */
    private function primaryColumns(Table $table): array
    {
        return array_map(static fn ($column): string => $column->getIdentifier()->getValue(), $table->getPrimaryKeyConstraint()?->getColumnNames() ?? []);
    }
}
