<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Fixtures;

use Doctrine\Common\DataFixtures\SharedFixtureInterface;
use ModernORM\Bundle\Schema\CacheInvalidator;
use ModernORM\Bundle\Schema\SchemaScope;
use ModernORM\EntityManager;
use Doctrine\Common\EventManager;
use ModernORM\EntityManagerInterface;

final class Runner
{
    private readonly array $fixtures;

    public function __construct(private readonly EntityManagerInterface $manager, private readonly SchemaScope $scope, private readonly CacheInvalidator $cache, iterable $fixtures)
    {
        $this->fixtures = is_array($fixtures) ? array_values($fixtures) : iterator_to_array($fixtures, false);
    }

    public function select(array $groups = []): array
    {
        return (new Loader($this->fixtures))->select($groups);
    }

    public function load(array $groups = [], bool $append = false): void
    {
        $fixtures = $this->select($groups); // Validate dependencies/groups before any writes or purge.
        $schema = $this->scope->schema();
        $connection = $this->manager->getConnection();
        if ($connection->isTransactionActive() || !$connection->isAutoCommit()) {
            throw new \LogicException('Fixture loading requires an idle, auto-commit connection.');
        }
        $events = $this->manager->getEventManager();
        if (!$events instanceof EventManager) {
            throw new \LogicException('Fixture loading requires the concrete EventManager accepted by ModernORM.');
        }
        $this->manager->clear();
        $config = clone $this->manager->getConfiguration();
        $config->setSecondLevelCacheEnabled(false);
        $working = new EntityManager($connection, $config, $events);
        $references = new ReferenceRepository($working);
        $this->cache->invalidate();
        $committed = false;
        $error = null;
        $began = false;
        try {
            $connection->beginTransaction();
            $began = true;
            if (!$append && [] !== $schema->getTables()) {
                // All managed tables together handle internal FK cycles, without CASCADE into foreign tables.
                $tables = array_map(static fn ($table): string => $table->getQuotedName($connection->getDatabasePlatform()), $schema->getTables());
                $connection->executeStatement('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY');
            }
            foreach ($fixtures as $fixture) {
                if ($fixture instanceof SharedFixtureInterface) {
                    $fixture->setReferenceRepository($references);
                }
                $fixture->load($working);
                if (1 !== $connection->getTransactionNestingLevel()) {
                    $committed = null;
                    throw new \LogicException('Fixtures must not change the runner transaction boundary.');
                }
                $working->flush();
                $references->captureIdentities();
                $working->clear();
                if (1 !== $connection->getTransactionNestingLevel()) {
                    throw new \LogicException('Fixtures must not change the runner transaction boundary.');
                }
            }
            $committed = null; // COMMIT can fail with an ambiguous outcome.
            $connection->commit();
            $committed = true;
        } catch (\Throwable $failure) {
            $error = $failure;
            if ($began && 1 !== $connection->getTransactionNestingLevel()) {
                $committed = null;
            }
            if ($connection->isTransactionActive()) {
                try {
                    $connection->rollBack();
                } catch (\Throwable) {
                    $committed = null;
                }
            }
            if (null === $committed) {
                $connection->close();
                try {
                    $this->manager->close();
                } catch (\Throwable) {
                    // Preserve the original load/commit failure.
                }
            }
        } finally {
            $cacheFailure = null;
            try {
                $this->cache->invalidate();
            } catch (\Throwable $failure) {
                $cacheFailure = $failure;
            }
            try {
                $working->close();
            } catch (\Throwable $failure) {
                $error ??= $failure;
            }
            try {
                $this->manager->clear();
            } catch (\Throwable $failure) {
                $error ??= $failure;
            }
        }
        if (null !== $error || null !== $cacheFailure) {
            throw new FixtureLoadException($error ?? $cacheFailure, $committed, $cacheFailure);
        }
    }
}
