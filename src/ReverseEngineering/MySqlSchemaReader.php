<?php

declare(strict_types=1);

namespace ModernORM\Bundle\ReverseEngineering;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\DefaultExpression;

final class MySqlSchemaReader
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
        if (!$this->connection->fetchOne('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$schema])) {
            throw new \InvalidArgumentException('Unknown MySQL database: '.$schema);
        }
        if ($this->connection->getDatabase() !== $schema) {
            $connection = DriverManager::getConnection(array_replace($this->connection->getParams(), ['dbname' => $schema]), $this->connection->getConfiguration());
            try {
                return (new self($connection))->read($schema, $selected);
            } finally {
                $connection->close();
            }
        }
        $names = $this->connection->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$schema]);
        foreach ($selected as $name) {
            if (!in_array($name, $names, true)) {
                throw new \InvalidArgumentException('Unknown table: '.$schema.'.'.$name);
            }
        }
        $tables = $warnings = [];
        $manager = $this->connection->createSchemaManager();
        foreach ($names as $name) {
            if ($selected !== [] && !in_array($name, $selected, true)) {
                continue;
            }
            $columns = $this->connection->fetchAllAssociative('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, EXTRA, GENERATION_EXPRESSION, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$schema, $name]);
            foreach ($columns as $column) {
                if (str_contains($schema, '`') || str_contains($name, '`') || str_contains($column['COLUMN_NAME'], '`')) {
                    throw new \RuntimeException('Identifiers containing backticks require manual mapping.');
                }
                if ($column['GENERATION_EXPRESSION'] !== '' && $column['GENERATION_EXPRESSION'] !== null) {
                    $warnings[] = $name.': skipped: generated columns require manual mapping.';
                    continue 2;
                }
            }
            $table = $manager->introspectTableByQuotedName($name);
            foreach ($columns as $column) {
                $field = $table->getColumn($column['COLUMN_NAME']);
                $label = $name.'.'.$column['COLUMN_NAME'];
                if ($column['DATA_TYPE'] === 'tinyint') {
                    if (preg_match('/^tinyint\(1\)(?: unsigned)?$/iD', $column['COLUMN_TYPE']) === 1) {
                        $warnings[] = $label.': tinyint(1) mapped as boolean; verify values are 0/1.';
                    } else {
                        $field->setTypeName('smallint');
                    }
                }
                if ($field->getUnsigned()) {
                    $warnings[] = $label.': unsigned range requires review when targeting PostgreSQL.';
                }
                if ($column['DATA_TYPE'] === 'set') {
                    $warnings[] = $label.': SET mapped as simple_array; allowed values require manual validation.';
                }
                if (str_contains(strtolower($column['EXTRA']), 'default_generated') && $field->getDefault() !== null && !$field->getDefault() instanceof DefaultExpression) {
                    $warnings[] = $label.': SQL expression default requires manual mapping and was omitted.';
                    $table = $table->edit()->modifyColumnByUnquotedName($column['COLUMN_NAME'], static function ($editor): void {
                        $editor->setDefaultValue(null);
                    })->create();
                }
                if (str_contains(strtolower($column['EXTRA']), 'on update')) {
                    $warnings[] = $label.': ON UPDATE expression requires manual mapping.';
                }
                if (in_array($column['DATA_TYPE'], ['date', 'datetime', 'timestamp', 'time'], true)) {
                    $warnings[] = $label.': review zero dates, timezone and MySQL TIME duration semantics before use.';
                    if ((int) $column['DATETIME_PRECISION'] > 0) {
                        $warnings[] = $label.': fractional-second precision requires manual review.';
                    }
                }
            }
            $tables[$name] = $table;
        }

        return ['tables' => $tables, 'sequences' => [], 'warnings' => $warnings];
    }
}
