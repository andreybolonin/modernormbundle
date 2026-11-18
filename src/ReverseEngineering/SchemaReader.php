<?php

declare(strict_types=1);

namespace ModernORM\Bundle\ReverseEngineering;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Table;

final class SchemaReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $selected
     * @return array{tables: array<string, Table>, sequences: array<string, array<string, string>>, warnings: list<string>}
     */
    public function read(string $schema, array $selected): array
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform && !$platform instanceof MariaDBPlatform) {
            return (new MySqlSchemaReader($this->connection))->read($schema, $selected);
        }
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \LogicException('Reverse engineering supports PostgreSQL and MySQL; MariaDB is not supported.');
        }
        if (!$this->connection->fetchOne('SELECT 1 FROM pg_catalog.pg_namespace WHERE nspname = ?', [$schema])) {
            throw new \InvalidArgumentException('Unknown schema: '.$schema);
        }
        $names = $this->connection->fetchFirstColumn("SELECT c.relname FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relkind IN ('r', 'p') AND NOT c.relispartition ORDER BY c.relname", [$schema]);
        foreach ($selected as $name) {
            if (!in_array($name, $names, true)) {
                throw new \InvalidArgumentException('Unknown table: '.$schema.'.'.$name);
            }
        }
        $manager = $this->connection->createSchemaManager();
        $tables = [];
        $sequences = [];
        $warnings = [];
        foreach ($names as $name) {
            if ($selected !== [] && !in_array($name, $selected, true)) {
                continue;
            }
            if (str_contains($name, '`') || str_contains($schema, '`')) {
                throw new \RuntimeException('Identifiers containing backticks require manual mapping.');
            }
            $generated = $this->connection->fetchOne("SELECT count(*) FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_class c ON c.oid = a.attrelid JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ? AND a.attgenerated <> '' AND NOT a.attisdropped", [$schema, $name]);
            if ((int) $generated > 0) {
                $warnings[] = $name.': skipped: generated columns require manual mapping.';
                continue;
            }
            $tables[$name] = $manager->introspectTableByQuotedName($name, $schema);
            // DBAL synthesizes indexes for foreign keys even when PostgreSQL has none.
            $realIndexes = $this->connection->fetchFirstColumn('SELECT indexname FROM pg_catalog.pg_indexes WHERE schemaname = ? AND tablename = ?', [$schema, $name]);
            foreach ($tables[$name]->getIndexes() as $index) {
                $indexName = $index->getObjectName()->getIdentifier()->getValue();
                if (!$index->isPrimary() && !in_array($indexName, $realIndexes, true)) {
                    $tables[$name]->dropIndex($index->getName());
                }
            }
            $columns = $this->connection->fetchAllAssociative("SELECT a.attname, a.attidentity, pg_catalog.pg_get_serial_sequence(pg_catalog.quote_ident(n.nspname) || '.' || pg_catalog.quote_ident(c.relname), a.attname) AS sequence_name FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_class c ON c.oid = a.attrelid JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ? AND a.attnum > 0 AND NOT a.attisdropped", [$schema, $name]);
            foreach ($columns as $column) {
                if (str_contains($column['attname'], '`')) {
                    throw new \RuntimeException('Identifiers containing backticks require manual mapping.');
                }
                if ($column['attidentity'] !== '') {
                    $tables[$name]->getColumn($column['attname'])->setAutoincrement(true);
                } elseif ($column['sequence_name'] !== null && count($tables[$name]->getPrimaryKeyConstraint()?->getColumnNames() ?? []) === 1 && $tables[$name]->getPrimaryKeyConstraint()->getColumnNames()[0]->getIdentifier()->getValue() === $column['attname']) {
                    $sequence = $this->connection->fetchAssociative('SELECT seqincrement, seqmin FROM pg_catalog.pg_sequence WHERE seqrelid = CAST(? AS regclass)', [$column['sequence_name']]);
                    if ($sequence === false || (int) $sequence['seqincrement'] !== 1 || (int) $sequence['seqmin'] !== 1) {
                        $warnings[] = $name.': skipped: nonstandard sequence increment/minimum requires manual mapping.';
                        unset($tables[$name], $sequences[$name]);
                        break;
                    }
                    $sequences[$name][$column['attname']] = $column['sequence_name'];
                }
            }
        }
        return ['tables' => $tables, 'sequences' => $sequences, 'warnings' => $warnings];
    }
}
