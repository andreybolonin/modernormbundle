<?php

declare(strict_types=1);

namespace ModernORM\Bundle;

use Doctrine\DBAL\Connection;
use Doctrine\Common\EventManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Cache\Logging\CacheLogger;
use Psr\Cache\CacheItemPoolInterface;

final class EntityManagerFactory implements \Symfony\Contracts\Service\ResetInterface
{
    public static function configuration(array $paths, int $batchSize, CacheItemPoolInterface $metadata, CacheItemPoolInterface $query, ?CacheItemPoolInterface $l2, string $namespace, CacheLogger $logger): Configuration
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver($paths));
        $config->setLazyFetchBatchSize($batchSize);
        $config->setMetadataCache($metadata);
        $config->setQueryCache($query);
        if ($l2 !== null) {
            $config->setSecondLevelCache($l2, $namespace, $logger);
        }
        return $config;
    }

    private ?EntityManager $manager = null;

    public function __construct(private readonly Connection $connection, private readonly Configuration $configuration, private readonly EventManager $events) {}

    public function create(): EntityManager
    {
        return $this->manager ??= (new \ReflectionClass(EntityManager::class))->newLazyProxy($this->initializer());
    }

    private function initializer(): \Closure
    {
        return fn (): EntityManager => new EntityManager($this->connection, $this->configuration, $this->events);
    }

    public function reset(): void
    {
        if ($this->manager === null) { return; }
        $reflection = new \ReflectionClass(EntityManager::class);
        try {
            if (!$reflection->isUninitializedLazyObject($this->manager) && $this->manager->isOpen()) {
                $this->manager->clear();
            }
        } finally {
            $this->connection->close();
            // A second reset may arrive before another request/job initializes the proxy.
            if (!$reflection->isUninitializedLazyObject($this->manager)) {
                $reflection->resetAsLazyProxy($this->manager, $this->initializer());
            }
        }
    }
}
