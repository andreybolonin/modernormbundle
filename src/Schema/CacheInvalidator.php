<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Schema;

use ModernORM\EntityManagerInterface;

final class CacheInvalidator
{
    public function __construct(private readonly EntityManagerInterface $manager, private readonly SchemaScope $scope)
    {
    }

    public function invalidate(): void
    {
        $cache = $this->manager->getCache();
        if (null === $cache) {
            return;
        }
        $first = null;
        foreach ($this->scope->cachedClasses() as $class) {
            try {
                $cache->evictAll($class);
            } catch (\Throwable $error) {
                $first ??= $error;
            }
        }
        if (null !== $first) {
            throw $first;
        }
    }
}
