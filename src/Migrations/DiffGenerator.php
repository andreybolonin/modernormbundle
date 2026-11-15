<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Migrations;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Generator\DiffGenerator as BaseDiffGenerator;
use Doctrine\Migrations\Generator\Exception\NoChangesDetected;
use Doctrine\Migrations\Generator\Generator;
use Doctrine\Migrations\Generator\SqlGenerator;
use Doctrine\Migrations\Provider\EmptySchemaProvider;
use ModernORM\Bundle\Schema\SchemaScope;

/** Use the core's complete SQL plan when available; keep the published core's ordinary diff path. */
final class DiffGenerator extends BaseDiffGenerator
{
    private readonly Generator $generator;
    private readonly SqlGenerator $sqlGenerator;

    public function __construct(DependencyFactory $factory, Configuration $configuration, private readonly Connection $connection, private readonly SchemaScope $scope)
    {
        $this->generator = $factory->getMigrationGenerator();
        $this->sqlGenerator = $factory->getMigrationSqlGenerator();
        $manager = $connection->createSchemaManager();
        parent::__construct($configuration, $manager, $factory->getSchemaProvider(), $connection->getDatabasePlatform(), $this->generator, $this->sqlGenerator, new EmptySchemaProvider($manager));
    }

    public function generate(string $fqcn, ?string $filterExpression, bool $formatted = false, ?bool $nowdocOutput = null, int $lineLength = 120, bool $checkDbPlatform = true, bool $fromEmptySchema = false): string
    {
        if (null !== $filterExpression) {
            throw new \InvalidArgumentException('Configure schema ownership through managed_schema_filter, not a diff filter override.');
        }
        if (!$this->scope->supportsMigrationPlan()) {
            return parent::generate($fqcn, null, $formatted, $nowdocOutput, $lineLength, $checkDbPlatform, $fromEmptySchema);
        }
        $plan = $this->scope->migrationPlan($this->connection, $fromEmptySchema);
        if ([] === $plan['up'] && [] === $plan['down']) {
            throw NoChangesDetected::new();
        }
        $up = $this->sqlGenerator->generate($plan['up'], $formatted, $nowdocOutput, $lineLength, $checkDbPlatform);
        $down = $this->sqlGenerator->generate($plan['down'], $formatted, $nowdocOutput, $lineLength, $checkDbPlatform);

        return $this->generator->generateMigration($fqcn, $up, $down);
    }
}
