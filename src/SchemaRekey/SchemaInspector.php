<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Index\IndexType;
use ModernORM\Bundle\DataTransfer\TargetColumns;
use ModernORM\Bundle\ReverseEngineering\MySqlSchemaReader;

/** Physical catalog inventory, independent of ORM entity metadata. */
final class SchemaInspector
{
    public function inspect(Connection $source, Connection $target, string $schema): array
    {
        $database = $source->getDatabase();
        if (!$database || in_array($database, ['mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
            throw new \InvalidArgumentException('Rekey requires an explicit non-system MySQL database.');
        }
        if (str_contains(strtolower((string) $source->fetchOne('SELECT VERSION()')), 'mariadb')) {
            throw new \InvalidArgumentException('Rekey supports MySQL, not MariaDB.');
        }
        $inventory = $source->fetchAllAssociative("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$database]);
        $snapshot = (new MySqlSchemaReader($source))->read($database, []);
        $tables = $blockers = [];
        $grants = implode("\n", $source->fetchFirstColumn('SHOW GRANTS FOR CURRENT_USER'));
        if (preg_match('/GRANT (?:ALL PRIVILEGES|[^\n]*\bSELECT\b[^\n]*) ON \*\.\*/', $grants) !== 1 || preg_match('/\bREVOKE\b/', $grants) === 1) {
            $blockers[] = 'Global SELECT is required to inventory incoming cross-database foreign keys.';
        }
        $external = $source->fetchAllAssociative('SELECT CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = ? AND TABLE_SCHEMA <> ? ORDER BY CONSTRAINT_SCHEMA,TABLE_NAME,CONSTRAINT_NAME', [$database, $database]);
        if ($external !== []) {
            $blockers[] = 'Incoming foreign keys from outside the source database are unsupported.';
        }
        foreach ($inventory as $entry) {
            $name = $entry['TABLE_NAME'];
            if ($entry['ENGINE'] !== 'InnoDB') {
                $blockers[] = $name.': source must be InnoDB.';
            }
            if (!isset($snapshot['tables'][$name])) {
                $blockers[] = $name.': unsupported source table or generated column.';
                continue;
            }
            $from = $snapshot['tables'][$name];
            $sourceColumns = $source->fetchAllAssociative('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, CHARACTER_SET_NAME, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$database, $name]);
            $qualified = $target->getDatabasePlatform()->quoteSingleIdentifier($schema).'.'.$target->getDatabasePlatform()->quoteSingleIdentifier($name);
            $relation = $target->fetchAssociative('SELECT c.relkind, c.relrowsecurity, c.relforcerowsecurity, c.relispartition FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=? AND c.relname=?', [$schema, $name]);
            if ($relation === false || !in_array($relation['relkind'], ['r', 'p'], true)) {
                $blockers[] = $name.': target table is missing.';
                continue;
            }
            $to = $target->createSchemaManager()->introspectTableByQuotedName($name, $schema);
            $description = (new TargetColumns())->describe($target, $qualified);
            $constraints = $target->fetchAllAssociative("SELECT conname, contype, convalidated, COALESCE((pg_catalog.to_jsonb(c)->>'conenforced')::boolean, true) AS enforced, pg_catalog.pg_get_constraintdef(oid) AS definition FROM pg_catalog.pg_constraint c WHERE conrelid=CAST(? AS regclass) ORDER BY conname", [$qualified]);
            $triggers = $target->fetchAllAssociative('SELECT tgname, tgenabled, tgisinternal, pg_catalog.pg_get_triggerdef(oid) AS definition FROM pg_catalog.pg_trigger WHERE tgrelid=CAST(? AS regclass) ORDER BY tgname', [$qualified]);
            $indexes = $target->fetchAllAssociative('SELECT ci.relname, i.indisvalid, i.indisready, pg_catalog.pg_get_indexdef(i.indexrelid) AS definition FROM pg_catalog.pg_index i JOIN pg_catalog.pg_class ci ON ci.oid=i.indexrelid WHERE i.indrelid=CAST(? AS regclass) ORDER BY ci.relname', [$qualified]);
            $rules = $target->fetchAllAssociative('SELECT rulename, pg_catalog.pg_get_ruledef(oid) AS definition FROM pg_catalog.pg_rewrite WHERE ev_class=CAST(? AS regclass) ORDER BY rulename', [$qualified]);
            if ($rules !== []) {
                $blockers[] = $name.': target rewrite rules are unsupported.';
            }
            foreach ($indexes as $index) {
                if (!$index['indisvalid'] || !$index['indisready']) {
                    $blockers[] = $name.': invalid or incomplete target index.';
                }
            }
            $inheritance = $target->fetchOne('SELECT count(*) FROM pg_catalog.pg_inherits WHERE inhrelid=CAST(? AS regclass) OR inhparent=CAST(? AS regclass)', [$qualified, $qualified]);
            if ($relation['relkind'] !== 'r' || $relation['relrowsecurity'] || $relation['relforcerowsecurity'] || $relation['relispartition'] || (int) $inheritance !== 0) {
                $blockers[] = $name.': RLS, partitioning and inheritance are unsupported.';
            }
            foreach ($triggers as $trigger) {
                if (!$trigger['tgisinternal'] || !in_array($trigger['tgenabled'], ['O', 'A'], true)) {
                    $blockers[] = $name.': user, disabled or replica-only triggers are unsupported.';
                }
            }
            foreach ($constraints as $constraint) {
                if (!$constraint['convalidated'] || !$constraint['enforced']) {
                    $blockers[] = $name.': target constraint is not validated or enforced.';
                }
            }
            $generated = $target->fetchAllAssociative('SELECT column_name, is_generated, is_identity, column_default, identity_generation, identity_start, identity_increment, identity_minimum, identity_maximum, identity_cycle, collation_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', [$schema, $name]);
            $sequences = [];
            foreach ($to->getColumns() as $column) {
                $columnName = $column->getObjectName()->getIdentifier()->getValue();
                $sequence = $target->fetchOne('SELECT pg_catalog.pg_get_serial_sequence(?, ?)', [$qualified, $columnName]);
                if ($sequence) {
                    $sequences[$columnName] = ['name' => $sequence, 'settings' => $target->fetchAssociative('SELECT seqtypid::regtype::text AS type, seqstart::text, seqincrement::text, seqmin::text, seqmax::text, seqcycle FROM pg_catalog.pg_sequence WHERE seqrelid=CAST(? AS regclass)', [$sequence])];
                }
            }
            $tables[$name] = ['source_table' => $from, 'target_table' => $to, 'source_catalog' => $sourceColumns, 'target_catalog' => $generated, 'definitions' => $description['definitions'], 'sequences' => $sequences, 'source' => $database.'.'.$name, 'target' => $qualified, 'schema' => $schema, 'source_structure' => $this->describe($from), 'target_structure' => $this->describe($to), 'constraints' => $constraints, 'index_catalog' => $indexes, 'rewrite_rules' => $rules, 'triggers' => $triggers, 'relation' => $relation];
        }
        $sourceIdentity = ['instance' => (string) $source->fetchOne('SELECT @@server_uuid'), 'database' => $database];
        $targetIdentity = ['instance' => (string) $target->fetchOne('SELECT system_identifier::text FROM pg_catalog.pg_control_system()'), 'database' => (string) $target->fetchOne('SELECT current_database()'), 'oid' => (string) $target->fetchOne('SELECT oid::text FROM pg_catalog.pg_database WHERE datname=current_database()')];
        $session = $source->fetchAssociative('SELECT @@session.sql_mode AS sql_mode, @@session.time_zone AS timezone, @@session.character_set_connection AS charset, @@session.collation_connection AS collation');

        return ['tables' => $tables, 'inventory' => $inventory, 'blockers' => $blockers, 'external' => $external, 'endpoint_identity' => ['source' => $sourceIdentity, 'target' => $targetIdentity], 'source_session' => $session];
    }

    public function describe(Table $table): array
    {
        $columns = $indexes = $foreignKeys = [];
        foreach ($table->getColumns() as $column) {
            $columns[$column->getObjectName()->getIdentifier()->getValue()] = ['type' => $column->getTypeName(), 'unsigned' => $column->getUnsigned(), 'nullable' => !$column->getNotnull(), 'length' => $column->getLength(), 'precision' => $column->getPrecision(), 'scale' => $column->getScale(), 'default' => $column->getDefault() === null ? null : (string) $column->getDefault(), 'autoincrement' => $column->getAutoincrement()];
        }
        foreach ($table->getIndexes() as $index) {
            $indexes[$index->getObjectName()->toString()] = ['columns' => array_map(static fn ($column): string => $column->getColumnName()->getIdentifier()->getValue(), $index->getIndexedColumns()), 'unique' => $index->getType() === IndexType::UNIQUE, 'type' => $index->getType()->name, 'clustered' => $index->isClustered(), 'predicate' => $index->getPredicate(), 'lengths' => array_map(static fn ($column): ?int => $column->getLength(), $index->getIndexedColumns())];
        }
        foreach ($table->getForeignKeys() as $fk) {
            $foreignKeys[$fk->getObjectName()?->toString() ?? 'unnamed'] = ['columns' => array_map(static fn ($column): string => $column->getIdentifier()->getValue(), $fk->getReferencingColumnNames()), 'parent' => $fk->getReferencedTableName()->getUnqualifiedName()->getValue(), 'parent_schema' => $fk->getReferencedTableName()->getQualifier()?->getValue(), 'referenced_columns' => array_map(static fn ($column): string => $column->getIdentifier()->getValue(), $fk->getReferencedColumnNames()), 'on_update' => $fk->getOnUpdateAction()->value, 'on_delete' => $fk->getOnDeleteAction()->value];
        }
        ksort($indexes);
        ksort($foreignKeys);

        return ['columns' => $columns, 'primary_key' => array_map(static fn ($column): string => $column->getIdentifier()->getValue(), $table->getPrimaryKeyConstraint()?->getColumnNames() ?? []), 'indexes' => $indexes, 'foreign_keys' => $foreignKeys];
    }
}
