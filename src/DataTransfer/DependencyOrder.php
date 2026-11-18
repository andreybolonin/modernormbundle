<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

final class DependencyOrder
{
    /** @param array<string, list<string>> $dependencies
     *  @return array{order: list<string>, blocked: list<string>}
     */
    public function sort(array $dependencies): array
    {
        ksort($dependencies);
        $order = [];
        do {
            $progress = false;
            foreach ($dependencies as $table => $parents) {
                if (array_diff($parents, $order) !== []) {
                    continue;
                }
                $order[] = $table;
                unset($dependencies[$table]);
                $progress = true;
            }
        } while ($progress);

        return ['order' => $order, 'blocked' => array_keys($dependencies)];
    }
}
