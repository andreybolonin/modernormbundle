<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;

final class ForeignKeyRemapper
{
    public function remap(Connection $target, array $plan, array $stages): void
    {
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($plan['foreign_keys'] as $edge) {
            if ($edge['classification'] !== 'remap') {
                continue;
            }
            $child = $stages[$edge['child']];
            $parent = $stages[$edge['parent']];
            $parentTable = $plan['tables'][$edge['parent']];
            $join = $this->join($target, $edge, 'c', 'p');
            $count = (int) $target->fetchOne('SELECT count(*) FROM '.$child['table'].' c JOIN '.$parent['table'].' p ON '.$join);
            if ($count !== $child['rows']) {
                throw new \RuntimeException($edge['child'].'.'.$edge['name'].': missing or ambiguous source parent mapping.');
            }
            $target->executeStatement('UPDATE '.$child['table'].' c SET '.$quote($edge['target_columns'][0]).' = p.'.$quote($parentTable['target_key'][0]).' FROM '.$parent['table'].' p WHERE '.$join);
        }
    }

    public function join(Connection $target, array $edge, string $childAlias, string $parentAlias): string
    {
        $parts = [];
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($edge['source_columns'] as $index => $column) {
            $parts[] = $childAlias.'.'.$quote($column).' = '.$parentAlias.'.'.$quote($edge['referenced_columns'][$index]);
        }

        return implode(' AND ', $parts);
    }
}
