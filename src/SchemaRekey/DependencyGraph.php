<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use ModernORM\Bundle\DataTransfer\DependencyOrder;

final class DependencyGraph
{
    public function order(array $tables, array $edges): array
    {
        $dependencies = array_fill_keys(array_keys($tables), []);
        $blockers = [];
        foreach ($edges as $edge) {
            if ($edge['classification'] === 'blocked' || !isset($tables[$edge['child']], $tables[$edge['parent']])) {
                $blockers[] = $edge['child'].'.'.$edge['name'].': blocked FK edge to '.$edge['parent'].': '.$edge['reason'];
                continue;
            }
            // Staging resolves self-edges before the table's single final INSERT.
            if ($edge['child'] !== $edge['parent']) {
                $dependencies[$edge['child']][] = $edge['parent'];
            }
        }
        $sorted = (new DependencyOrder())->sort($dependencies);
        if ($sorted['blocked'] !== []) {
            $blockers[] = 'Cyclic or unresolved FK dependencies: '.implode(', ', $sorted['blocked']).'.';
        }

        return ['order' => $sorted['order'], 'blockers' => $blockers];
    }
}
