# Database migrations

[Documentation](README.md) · [Fixtures](fixtures.md) · [Runnable migration/fixture example](../examples/migrations-fixtures/README.md)

Install `doctrine/migrations:^3.9` in the application. ModernORMBundle integrates the existing engine directly through DBAL; neither Doctrine ORM nor DoctrineMigrationsBundle is required.

```yaml
modernorm:
    managed_schema_filter: '~^modernorm_~'
    migrations:
        enabled: true
        paths:
            ModernORMMigrations: '%kernel.project_dir%/modernorm-migrations'
        table_name: modernorm_migration_versions
        all_or_nothing: true
```

Create the migration directory before generating a migration. Explicitly name mapped tables, join tables and named sequences within the chosen ownership scope. For a dedicated schema, use a schema-qualified filter such as `~^my_schema\.~`. Create that PostgreSQL schema beforehand if migration history is also stored there. History table names must be lowercase, unquoted identifiers, optionally schema-qualified.

```sh
bin/console modernorm:migrations:sync-metadata-storage
bin/console modernorm:migrations:generate
bin/console modernorm:migrations:diff
bin/console modernorm:migrations:status
bin/console modernorm:migrations:migrate --dry-run
bin/console modernorm:migrations:migrate
# After applying the generated migration, the diff should be empty:
bin/console modernorm:migrations:diff --allow-empty-diff
```

`managed_schema_filter` is mandatory when either schema integration is enabled. Diff compares only owned tables/sequences, excludes its history table, and rejects mapped assets outside the scope. This also allows removed owned tables to appear in a diff. It preserves existing PostgreSQL namespaces in both sides of the comparison, preventing unrelated schemas from leaking into generated up/down SQL. It uses isolated introspection connections, leaving the ORM connection's filter unchanged. Migration SQL uses dedicated connections and is not included in the original ORM connection's Statistics counters. Migration SQL and its version record commit through the same migration connection and standard Doctrine transaction machinery. Existing database `search_path` changes made manually on the ORM connection are not copied: use explicit schema names or connection parameters for session configuration.

Command-level connection, configuration and schema-filter overrides are rejected: configure them through the bundle. `all_or_nothing` and transactional migrations default to true. PostgreSQL operations such as `CREATE INDEX CONCURRENTLY` require an explicitly non-transactional migration and disabled all-or-nothing mode using the upstream contract. Transactional DDL is not a promise of zero downtime or reversible data loss.

Migration execution clears the ORM UnitOfWork and invalidates all mapped L2 entity classes before and after execution. Run it with a fresh EntityManager and outside ORM transactions. A cache failure after execution requires database/cache inspection before retrying. Deployments must restart workers and handle metadata/query-plan cache changes; migration commands do not refresh already running processes. Ownership filtering constrains generated diff and fixture purge, not arbitrary hand-written SQL inside trusted migrations or fixtures.
