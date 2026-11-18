# Generate entities from an existing database

`modernorm:mapping:import` reads PostgreSQL schema metadata and creates new PHP
entities with `ModernORM\Mapping` attributes. It uses the bundle connection
directly: existing entity mappings are not required and no database DDL or DML
is executed by the command.

```bash
php bin/console modernorm:mapping:import --schema=public --dry-run
php bin/console modernorm:mapping:import --schema=public \
    --namespace='App\Entity' --output=src/Entity \
    --table=users --table=orders
```

The namespace defaults to `App\Entity`, schema to `public`, and output to
`src/Entity` relative to the Symfony project directory. `--table` selects exact
table names, can be repeated, and fails on unknown tables. Without it all ordinary
and partitioned parent tables in the selected schema are considered. Views and
partition children are excluded. Names retain plural forms (`users` → `Users`);
underscores become CamelCase and foreign-key properties drop a trailing `_id`.

Dry run prints PHP and warnings without creating directories or files. Existing
destination files, including symlinks, are rejected before writing any entities.
Class/property naming collisions and unknown column types fail generation.
Generated PHP is parsed before writing. Files are created exclusively, so an
existing file cannot be overwritten even if it appears after the preflight check.
Filesystem failures can leave some newly generated files; inspect the output
directory before retrying. Existing entity classes are never merged or updated.

## Supported first version

- One scalar primary key, assigned IDs, PostgreSQL identity and owned serial PK
  sequences (allocation size 1).
- Column types, nullability, string length, decimal precision/scale, comments,
  fixed strings, DBAL platform options and SQL defaults.
- Simple indexes and unique constraints.
- Owning unidirectional ManyToOne associations for single-column foreign keys
  referencing a selected entity's PK in the same schema, with nullable/onDelete.

PHP properties are public. Nullable columns and identity IDs start as `null`;
other properties must be assigned before persistence. SQL defaults remain mapping
options, not PHP property initializers. Decimal maps to `string`, DBAL 4 bigint
to `int`, binary/blob to `mixed`, and mutable temporal types to `DateTime`.

Tables without a PK, with composite PKs or generated columns are skipped with
warnings. Owned serial sequences with a nonstandard increment or minimum are
also skipped for manual mapping. Foreign keys to unselected tables, cross-schema targets, non-PK targets,
composite FKs and association IDs remain scalar fields with a manual-mapping
warning. FK names, deferrability, MATCH rules and on-update behavior are not
reconstructed. Defaults on association columns need manual mapping. A unique FK
retains its unique constraint but is represented as ManyToOne.

Inverse collections, ManyToMany, business validation, cascade operations, enums,
optimistic versions, lifecycle hooks and soft delete are not inferred. Partial,
expression and specialized indexes need review; flagged/option-bearing indexes
are reported and omitted. CHECK/exclusion constraints, triggers, privileges,
partition definitions and other database assets are outside the generated mapping.
Database introspection can itself reject unsupported PostgreSQL types.

Review the report and generated code, ensure the output directory is autoloaded
and included in `modernorm.mapping_paths`, then run `modernorm:schema:validate`.
Review schema/migration diffs before applying them: this is a starting mapping,
not a promise to reconstruct every database asset. DBAL synthesizes FK indexes;
the importer excludes those absent from PostgreSQL. Schema comparison can still
report a synthetic-index rename for a FK with no actual supporting index.

## Doctrine provenance

This follows the former Doctrine workflow: `doctrine:mapping:import` read schema
metadata through `DatabaseDriver`, while `doctrine:generate:entities` used
`EntityGenerator` to write PHP classes. Reviewed sources:

- [DatabaseDriver (Doctrine ORM 2.20)](https://github.com/doctrine/orm/blob/2.20.x/src/Mapping/Driver/DatabaseDriver.php)
- [EntityGenerator (Doctrine ORM 2.20)](https://github.com/doctrine/orm/blob/2.20.x/src/Tools/EntityGenerator.php)

Column/default/index reconstruction and FK `_id` naming follow that approach.
This implementation uses DBAL 4.6 introspection and emits ModernORM attributes;
it does not restore the runtime database mapping driver, annotation generation,
existing-file editing, inheritance or composite entity IDs. Doctrine's MIT
license is retained in `licenses/doctrine-orm.txt` for attribution.
