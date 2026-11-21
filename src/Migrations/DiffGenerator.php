<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Migrations;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\SchemaDiff;
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
        $plan = $this->scope->supportsMigrationPlan()
            ? $this->scope->migrationPlan($this->connection, $fromEmptySchema)
            : $this->ordinaryPlan($fromEmptySchema);
        if ([] === $plan['up'] && [] === $plan['down']) {
            throw NoChangesDetected::new();
        }
        $up = $this->sqlGenerator->generate($plan['up'], $formatted, $nowdocOutput, $lineLength, $checkDbPlatform);
        $down = $this->sqlGenerator->generate($plan['down'], $formatted, $nowdocOutput, $lineLength, $checkDbPlatform);

        return $this->generator->generateMigration($fqcn, $up, $down);
    }

    private function ordinaryPlan(bool $fromEmptySchema): array
    {
        $manager = $this->connection->createSchemaManager();
        $from = $fromEmptySchema ? (new EmptySchemaProvider($manager))->createSchema() : $manager->introspectSchema();
        $to = (new SchemaProvider($this->scope))->createSchema();
        $comparator = $manager->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false));
        $up = $comparator->compareSchemas($from, $to);
        $down = $comparator->compareSchemas($to, $from);
        // Empty namespaces have no editor API. Keep existing namespaces in place by
        // omitting their drop (up) and redundant create (down) from the diffs.
        $up = $this->namespaceDiff($up, $up->getCreatedSchemas(), []);
        $down = $this->namespaceDiff($down, [], $down->getDroppedSchemas());
        $platform = $this->connection->getDatabasePlatform();

        return ['up' => $platform->getAlterSchemaSQL($up), 'down' => $platform->getAlterSchemaSQL($down)];
    }

    private function namespaceDiff(SchemaDiff $diff, array $created, array $dropped): SchemaDiff
    {
        return new SchemaDiff($created, $dropped, $diff->getCreatedTables(), $diff->getAlteredTables(), $diff->getDroppedTables(), $diff->getCreatedSequences(), $diff->getAlteredSequences(), $diff->getDroppedSequences());
    }

}
