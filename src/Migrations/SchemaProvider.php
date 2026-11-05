<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use ModernORM\Bundle\Schema\SchemaScope;

final class SchemaProvider implements \Doctrine\Migrations\Provider\SchemaProvider
{
    public function __construct(private readonly SchemaScope $scope, private readonly \Doctrine\DBAL\Connection $connection)
    {
    }

    public function createSchema(): Schema
    {
        $schema = $this->scope->schema();
        // Asset filters do not filter namespaces. Preserve all existing namespaces on both sides,
        // so unrelated empty schemas cannot leak into generated up/down SQL.
        $manager = $this->connection->createSchemaManager();
        foreach ($manager->listSchemaNames() as $namespace) {
            // DiffGenerator itself adds the default namespace for this DBAL API variant.
            if (!method_exists($manager, 'getSchemaSearchPaths') && $namespace === $schema->getName()) {
                continue;
            }
            if (!$schema->hasNamespace($namespace)) {
                $schema->createNamespace($namespace);
            }
        }

        return $schema;
    }
}
