<?php

declare(strict_types=1);

namespace ModernORM\Bundle;

use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\DBAL\Connection;

/** A single-manager registry, independent of Symfony's Doctrine ORM bridge. */
final class Registry extends AbstractManagerRegistry
{
    public function __construct(private readonly EntityManagerFactory $factory, private readonly Connection $connection)
    {
        parent::__construct('ModernORM', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default');
    }
    protected function getService(string $name): object
    {
        return match ($name) {
            'connection' => $this->connection,
            'manager' => $this->factory->create(),
            default => throw new \InvalidArgumentException('Unknown ModernORM service: '.$name),
        };
    }
    protected function resetService(string $name): void
    {
        if ($name !== 'manager') { throw new \InvalidArgumentException('Unknown ModernORM manager: '.$name); }
        $this->factory->reset();
    }
}
