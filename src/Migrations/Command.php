<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Migrations;

use ModernORM\Bundle\Schema\CacheInvalidator;
use ModernORM\Bundle\Schema\SchemaScope;
use ModernORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Delegate migration execution/versioning to Doctrine; keep ownership and connection fixed. */
final class Command extends BaseCommand
{
    public function __construct(private readonly BaseCommand $inner, private readonly SchemaScope $scope, private readonly EntityManagerInterface $manager, private readonly CacheInvalidator $cache, private readonly bool $writes)
    {
        parent::__construct('modernorm:'.$inner->getName());
        $this->setDefinition(clone $inner->getDefinition());
        $this->setDescription($inner->getDescription());
        $this->setHelp($inner->getHelp());
        $inner->setAliases([]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (['configuration', 'em', 'conn', 'filter-expression'] as $option) {
            if ($input->hasOption($option) && null !== $input->getOption($option)) {
                throw new \InvalidArgumentException('Configure '.$option.' through modernorm configuration; per-command overrides are unsupported.');
            }
        }
        $this->inner->setApplication($this->getApplication());
        $this->scope->schema();
        $writing = $this->writes && !$input->getOption('dry-run') && false === $input->getOption('write-sql');
        if (!$writing) {
            return $this->inner->run($input, $output);
        }
        if ($this->manager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Run migrations outside ORM transactions.');
        }
        $this->manager->clear();
        $this->cache->invalidate();
        $error = null;
        try {
            return $this->inner->run($input, $output);
        } catch (\Throwable $failure) {
            $error = $failure;
            throw $failure;
        } finally {
            try {
                $this->cache->invalidate();
            } catch (\Throwable $cacheFailure) {
                throw new \RuntimeException('Migration database outcome must be inspected; L2 invalidation failed. Do not blindly retry migrations.', 0, $error ?? $cacheFailure);
            } finally {
                $this->manager->clear();
            }
        }
    }
}
