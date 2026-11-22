<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Schema;

use Doctrine\DBAL\Schema\Schema;
use ModernORM\EntityManagerInterface;
use ModernORM\Tools\SchemaTool;

/** An explicit application-owned table/sequence namespace, shared by diff and fixture purge. */
final class SchemaScope
{
    public function __construct(private readonly EntityManagerInterface $manager, private readonly string $filter, private readonly string $metadataTable)
    {
        if (!preg_match('~\A[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?\z~', $metadataTable)) {
            throw new \InvalidArgumentException('Migration history requires a lowercase, unquoted table name, optionally schema-qualified.');
        }
        if (false === @preg_match($filter, '')) {
            throw new \InvalidArgumentException('managed_schema_filter must be a valid regular expression.');
        }
    }

    public function isMetadataTable(string $name): bool
    {
        $normalize = static fn (string $value): string => str_starts_with($value, 'public.') ? substr($value, 7) : $value;

        return $normalize($name) === $normalize($this->metadataTable);
    }

    public function owns(string $name): bool
    {
        return !$this->isMetadataTable($name) && 1 === preg_match($this->filter, $name);
    }

    public function schema(): Schema
    {
        $classes = [];
        foreach ($this->manager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $metadata->assertSupportedMapping();
            $copy = clone $metadata;
            // This ownership/purge view needs the ordinary mapped tables and sequences.
            foreach (['fullTextIndexes', 'checks', 'triggers', 'exclusions'] as $property) {
                if (array_key_exists($property, get_object_vars($copy))) {
                    (new \ReflectionProperty($copy, $property))->setValue($copy, []);
                }
            }
            $classes[] = $copy;
        }
        $schema = (new SchemaTool($this->manager))->getSchemaFromMetadata($classes);
        foreach ([...$schema->getTables(), ...$schema->getSequences()] as $asset) {
            $objectName = $asset->getObjectName();
            $name = ($objectName->getQualifier() === null ? '' : $objectName->getQualifier()->getValue().'.').$objectName->getUnqualifiedName()->getValue();
            if (!$this->owns($name)) {
                throw new \LogicException('Mapped schema asset lies outside managed_schema_filter: '.$name);
            }
        }

        return $schema;
    }

    public function hasFullText(): bool
    {
        foreach ($this->manager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ([] !== (get_object_vars($metadata)['fullTextIndexes'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    public function hasPostgreSqlTableObjects(): bool
    {
        foreach ($this->manager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $properties = get_object_vars($metadata);
            if ([] !== ($properties['checks'] ?? []) || [] !== ($properties['triggers'] ?? []) || [] !== ($properties['exclusions'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    public function supportsMigrationPlan(): bool
    {
        return (new \ReflectionClass(SchemaTool::class))->hasMethod('getSchemaMigrationSql');
    }

    /** @return array{up: list<string>, down: list<string>} */
    public function migrationPlan(\Doctrine\DBAL\Connection $connection, bool $fromEmptySchema): array
    {
        $this->schema(); // Validate ownership before the core can whitelist mapped assets.
        $manager = new class ($this->manager, $connection) extends \ModernORM\Decorator\EntityManagerDecorator {
            public function __construct(EntityManagerInterface $wrapped, private readonly \Doctrine\DBAL\Connection $connection)
            {
                parent::__construct($wrapped);
            }

            public function getConnection(): \Doctrine\DBAL\Connection
            {
                return $this->connection;
            }
        };
        $tool = new SchemaTool($manager);
        $generate = new \ReflectionMethod($tool, 'getSchemaMigrationSql');

        return $generate->invoke($tool, $this->manager->getMetadataFactory()->getAllMetadata(), $fromEmptySchema);
    }

    public function cachedClasses(): array
    {
        return array_values(array_map(static fn ($metadata): string => $metadata->name, array_filter(
            $this->manager->getMetadataFactory()->getAllMetadata(),
            static fn ($metadata): bool => null !== $metadata->cache,
        )));
    }
}
