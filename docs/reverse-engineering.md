# Generate entities from an existing database

`modernorm:mapping:import` reads PostgreSQL or MySQL schema metadata and creates new PHP
entities with `ModernORM\Mapping` attributes. It uses the bundle connection
directly: existing entity mappings are not required and no database DDL or DML
is executed by the command.

To read a separate source, provide the **name** of an environment variable holding
its URL. Symfony Dotenv values in `$_SERVER`/`$_ENV` and process environment
variables are supported. The source URL is not printed. Without this option the
bundle connection remains the source.

```bash
# MYSQL_DATABASE_URL=mysql://user:password@127.0.0.1:3306/legacy_app
php bin/console modernorm:mapping:import \
    --source-url-env=MYSQL_DATABASE_URL --dry-run
php bin/console modernorm:mapping:import \
    --source-url-env=MYSQL_DATABASE_URL --table=users --table=orders
```

MySQL source imports need PHP `pdo_mysql`. MySQL 8.4 is covered by integration
tests; MariaDB is explicitly unsupported. This adds schema import only: the
ModernORM runtime and the bundle application connection still require PostgreSQL.
Reading a separate MySQL source does not initialize the application connection
or EntityManager. Separate source connections are closed after generation.

```bash
php bin/console modernorm:mapping:import --schema=public --dry-run
php bin/console modernorm:mapping:import --schema=public \
    --namespace='App\Entity' --output=src/Entity \
    --table=users --table=orders
```

The namespace defaults to `App\Entity`, PostgreSQL schema to `public`, and output to
`src/Entity` relative to the Symfony project directory. `--table` selects exact
table names, can be repeated, and fails on unknown tables. Without it all ordinary
and partitioned parent tables in the selected schema are considered. Views and
partition children are excluded. Names retain plural forms (`users` → `Users`);
underscores become CamelCase and foreign-key properties drop a trailing `_id`.

For MySQL, `--schema` selects a **database**, defaulting to the database in the
source URL. An explicit database override uses an isolated connection and does
not change the application connection's selected database. MySQL views are
excluded; partitioned tables are imported as whole tables.

The generated Table attribute retains that database as its schema. When targeting
PostgreSQL, change it to the intended PostgreSQL schema and review MySQL-specific
options/defaults. This command does not copy data or automatically translate a
MySQL schema into a PostgreSQL migration.

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

For MySQL, AUTO_INCREMENT becomes IDENTITY. Integer unsigned options, column
charset/collation and ENUM values are retained. ENUM becomes DBAL `enum` with a
PHP string; SET becomes `simple_array` with an explicit warning that allowed
values are not reconstructed. Ordinary TINYINT becomes `smallint`/PHP int;
TINYINT(1) becomes boolean with a warning to verify stored values are 0/1.
Unsigned BIGINT properties use `int|string` (plus null for generated/nullable
columns), matching DBAL's possible return values beyond PHP_INT_MAX.

SQL expression default objects, such as CURRENT_TIMESTAMP, are reported and
omitted instead of emitting non-executable PHP object exports. Literal defaults
are retained as options. ON UPDATE expressions, date/time semantics, fractional
seconds and unsigned ranges receive manual-review warnings. Prefix indexes,
FULLTEXT and other specialized indexes are omitted with warnings. Generated
columns cause the entire table to be skipped. SQL modes, collations, zero dates,
timezone conversion and unsigned ranges must be reviewed before using the mapping,
especially with PostgreSQL.

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
