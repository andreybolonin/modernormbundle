<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Migrations;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use ModernORM\Bundle\Schema\SchemaScope;

final class DependencyFactoryFactory
{
    public static function create(Connection $source, SchemaScope $scope, array $paths, string $table, bool $allOrNothing): DependencyFactory
    {
        // A separate connection keeps diff's schema filter off normal ORM/schema commands.
        $dbal = new DbalConfiguration();
        $dbal->setSchemaAssetsFilter(static fn (string $name): bool => $scope->isMetadataTable($name) || $scope->owns($name));
        $connection = DriverManager::getConnection($source->getParams(), $dbal);
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \LogicException('ModernORM migrations require PostgreSQL.');
        }
        $configuration = new Configuration();
        foreach ($paths as $namespace => $path) {
            $configuration->addMigrationsDirectory($namespace, $path);
        }
        $storage = new TableMetadataStorageConfiguration();
        $storage->setTableName($table);
        $configuration->setMetadataStorageConfiguration($storage);
        $configuration->setAllOrNothing($allOrNothing);
        $configuration->setTransactional(true);
        $configuration->setCheckDatabasePlatform(true);
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($connection));
        $factory->setService(\Doctrine\Migrations\Provider\SchemaProvider::class, new SchemaProvider($scope, $connection));
        // History must remain visible to storage and commit on the same connection as migration SQL.
        // Diff introspection gets its own filter, excluding history without hiding it from tablesExist().
        $diffConfiguration = new DbalConfiguration();
        $diffConfiguration->setSchemaAssetsFilter(static fn (string $name): bool => $scope->owns($name));
        $diffConnection = DriverManager::getConnection($source->getParams(), $diffConfiguration);
        $factory->setDefinition(\Doctrine\Migrations\Generator\DiffGenerator::class, static fn () => new \Doctrine\Migrations\Generator\DiffGenerator(
            $diffConfiguration,
            $diffConnection->createSchemaManager(),
            $factory->getSchemaProvider(),
            $diffConnection->getDatabasePlatform(),
            $factory->getMigrationGenerator(),
            $factory->getMigrationSqlGenerator(),
            new \Doctrine\Migrations\Provider\EmptySchemaProvider($diffConnection->createSchemaManager()),
        ));

        return $factory;
    }
}
