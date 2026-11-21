<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Name\Parsers;

final class TargetColumns
{
    public function describe(Connection $target, string $table): array
    {
        $columns = $definitions = [];
        $name = Parsers::getOptionallyQualifiedNameParser()->parse($table);
        $physical = $target->createSchemaManager()->introspectTableByQuotedName($name->getUnqualifiedName()->getValue(), $name->getQualifier()?->getValue());
        foreach ($physical->getColumns() as $column) {
            $columns[$column->getObjectName()->getIdentifier()->getValue()] = $column;
        }
        $rows = $target->fetchAllAssociative('SELECT a.attname AS name, pg_catalog.format_type(a.atttypid, a.atttypmod) AS sql_type, c.datetime_precision FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_class r ON r.oid = a.attrelid JOIN pg_catalog.pg_namespace n ON n.oid = r.relnamespace LEFT JOIN information_schema.columns c ON c.table_schema = n.nspname AND c.table_name = r.relname AND c.column_name = a.attname WHERE a.attrelid = CAST(? AS regclass) AND a.attnum > 0 AND NOT a.attisdropped', [$table]);
        foreach ($rows as $row) {
            $definitions[$row['name']] = $row;
        }

        return ['columns' => $columns, 'definitions' => $definitions];
    }
}
