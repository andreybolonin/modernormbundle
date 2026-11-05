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
        $schema = (new SchemaTool($this->manager))->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata());
        foreach ([...$schema->getTables(), ...$schema->getSequences()] as $asset) {
            $name = $asset->getName();
            if (!$this->owns($name)) {
                throw new \LogicException('Mapped schema asset lies outside managed_schema_filter: '.$name);
            }
        }

        return $schema;
    }

    public function cachedClasses(): array
    {
        return array_values(array_map(static fn ($metadata): string => $metadata->name, array_filter(
            $this->manager->getMetadataFactory()->getAllMetadata(),
            static fn ($metadata): bool => null !== $metadata->cache,
        )));
    }
}
