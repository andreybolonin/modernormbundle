# ModernORMBundle

Symfony integration for **ModernORM**, the PostgreSQL-first ORM for PHP 8.4+.

Connect your EntityManager, attribute mappings, batch fetching, PSR-6 caches and Statistics API to Symfony. Inspect SQL operation counts and timings, flushes, batch groups, fallback decisions, optional SQL details and L2 cache activity directly in the debug toolbar and Profiler.

## Requirements

- PHP 8.4+, PostgreSQL and ModernORM.
- Symfony 7.4 or 8.x.
- TwigBundle and WebProfilerBundle for the optional debug interface.

## Installation

Standard installation requires Packagist registration of both packages. GitHub beta tags are available independently of that registry step.

The beta requires explicit stability flags for both packages in the application's root manifest:

```sh
composer require 'andreybolonin/modernorm:^0.1@beta' 'andreybolonin/modernorm-bundle:^0.1@beta'
```

For bundle development, clone this repository and run `composer install` followed by `composer test`. The core is resolved from its public GitHub beta tag through Composer; a sibling checkout is not required. The root-only public VCS repository bootstraps development/CI until Packagist registration is complete and can then be removed. Composer does not inherit repositories from dependencies, so ordinary application installation requires both packages in Packagist or explicit root VCS repositories. Joint development can use a separate local Composer manifest with a path repository.

Register the bundle in `config/bundles.php`:

```php
ModernORM\Bundle\ModernORMBundle::class => ['all' => true],
```

Enable Symfony's TwigBundle and WebProfilerBundle in your development application and import the standard WebProfiler routes. The ModernORM collector and its Twig template are registered automatically.

```yaml
# config/packages/modernorm.yaml
modernorm:
    url: '%env(resolve:DATABASE_URL)%'
    # Set this to your actual PostgreSQL version to avoid connecting for platform detection.
    server_version: '17'
    mapping_paths:
        - '%kernel.project_dir%/src/Entity'
    batch_size: 32
    metadata_cache_pool: cache.system
    query_cache_pool: cache.system
    l2_cache:
        pool: null # e.g. cache.modernorm_l2, a configured shared PSR-6 pool
        namespace: my_app
    statistics:
        enabled: '%kernel.debug%'
    profiler:
        enabled: true
        max_events: 200
        collect_sql: false # opt in in development to show SQL text
        max_queries: 200
        max_sql_length: 10000 # bytes per SQL entry
```

Only PostgreSQL URLs are accepted. Batch size defaults to 1; association attributes can override it. `query_cache_pool` caches query plans, not query results.

The bundle also exposes `ModernORM\Bundle\Registry`, a single-manager implementation of the Doctrine Persistence registry contract. Inject `Doctrine\Persistence\ManagerRegistry $modernormRegistry` when an interface is preferable. It does not replace another bundle's default registry.

Inject `ModernORM\EntityManagerInterface` or `ModernORM\EntityManager`, and `ModernORM\Statistics`. For direct DBAL use, inject `Doctrine\DBAL\Connection $modernormConnection`; this named alias avoids taking over DoctrineBundle's default connection. The corresponding service IDs are `modernorm.entity_manager`, `modernorm.connection`, `modernorm.configuration` and `modernorm.statistics`.

## Console

The Symfony console includes:

```text
modernorm:mapping:info
modernorm:mapping:describe
modernorm:schema:create
modernorm:schema:update
modernorm:schema:drop
modernorm:schema:validate
```

For example, `bin/console modernorm:schema:create --dump-sql` generates PostgreSQL DDL without applying it. Schema commands delegate to ModernORM's tools.

## Profiler

The toolbar shows SQL count/time, flush and batch counts, and L2 hits/misses. The panel includes errors, batch totals by association kind, selected group sizes, fallback reasons, global L2 counters and a bounded completion history.

History is recorded only during HTTP requests while Symfony's Profiler is enabled. The history limit does not limit aggregate counters. Core operation events contain no SQL text, bindings, identifiers or exception messages. SQL timing excludes fetching/hydration; batch and flush timings overlap SQL time. Bulk SQL is included in SQL totals; separate bulk, hydration and UnitOfWork metrics are not exposed by the current core API.

Enable `modernorm.profiler.collect_sql: true` for a query list containing SQL text, duration, driver method and error SQLSTATE. This is a separate DBAL middleware in the bundle; the core Statistics API remains unchanged. Collection requires an active profiled HTTP request and works independently of aggregate Statistics being enabled. With `collect_sql: false`, the SQL middleware is not installed.

SQL history is bounded separately by `max_queries` and `max_sql_length`. Entries beyond the limit are counted but not retained; aggregate counters remain complete. Repeated SQL groups use a SHA-256 fingerprint of the complete, exact query text, so queries with different bindings group together and different long queries sharing a truncated prefix stay distinct. Counts cover retained entries only; this is an aid for investigating N+1, not automatic proof of it.

Bindings and exception messages are never stored by this SQL collector. Literal values embedded directly in SQL remain visible. Prepared statements keep their SQL template for later execution; no bound values are retained by the wrapper. Successful preparation alone is not a query; failed preparation is one failed entry. Transaction driver API calls are excluded. Query timing excludes result fetching and hydration.

Statistics default to `kernel.debug`. In production they can be enabled independently of the Profiler. With statistics disabled, no operation events are produced. If L2 is configured its lightweight cache counters remain available independently.

## Workers and exporters

Statistics, L2 counters, SQL history and event history are registered with `kernel.reset`. The EntityManager factory clears and resets its lazy proxy and closes the connection, discarding unfinished transactions. Injected EntityManager references remain valid after a closed manager is reset. Standard Symfony/Messenger service resets must remain enabled. Custom worker loops must reset services between jobs.

Main HTTP requests start a fresh measurement scope; subrequests do not reset the parent counters. Profiles store value snapshots and remain readable after service reset.

Additional `ModernORM\Statistics\OperationObserver` services can be tagged `modernorm.statistics_observer`. They receive completion events independently of the Profiler, providing an integration point for separate Prometheus/OpenTelemetry adapters. This package does not implement those exporters or active tracing spans.


## Migrations (optional)

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

## Fixtures (optional)

Install `doctrine/data-fixtures:^2.2` as an application development dependency and enable:

```yaml
modernorm:
    managed_schema_filter: '~^modernorm_~'
    fixtures:
        enabled: true
```

Extend `ModernORM\Bundle\Fixtures\Fixture` and implement `load(Doctrine\Persistence\ObjectManager $manager)`. Autoconfigured services receive the `modernorm.fixture` tag. Existing Doctrine fixtures are not automatically selected. You may explicitly tag other `doctrine/data-fixtures` FixtureInterface implementations if they accept the ModernORM manager.

Use upstream `DependentFixtureInterface` **or** `OrderedFixtureInterface` for ordering, and `addReference()` / `getReference($name, Entity::class)` for relationships. Generated identifiers are captured after flush before clear; references added both before and after flush work. Dependencies must be registered services, so constructor injection is preserved. Implement `ModernORM\Bundle\Fixtures\FixtureGroupInterface::getGroups(): array` for groups; class names and short class names can also select fixtures. Selecting a group includes its dependency closure.

```sh
bin/console modernorm:fixtures:load --group=demo
bin/console modernorm:fixtures:load --append --group=demo
```

A load uses one transaction and a separate ModernORM EntityManager with L2 disabled, so cacheable entity inserts work without weakening the normal external-transaction contract. Each fixture is flushed automatically and followed by clear; the caller's UnitOfWork is cleared as well. Fixtures may call flush, but must not commit/roll back or replace the transaction boundary. Keep external effects (HTTP calls, files, emails) out of fixture execution: they cannot be rolled back with PostgreSQL.

Unless `--append` is supplied, all mapped ModernORM tables are truncated together with `RESTART IDENTITY`, without CASCADE. This includes association tables but excludes migration history. External FK dependencies cause an error rather than cascading into other applications' tables. Group selection controls fixtures, not the purge scope. Purge and all fixture writes roll back together on failure. L2 classes are invalidated before and after the load; no uncommitted fixture state is published to L2. `FixtureLoadException::databaseCommitted` distinguishes rollback (`false`), committed cleanup failure (`true`) and an unknown outcome (`null`). Do not blindly retry after a committed/unknown failure.

CLI purge asks for confirmation in interactive mode. `--no-interaction` is suitable for explicitly scripted test setup, and `--force` is required in production. Keep fixture services and optional packages registered only in development/test environments if installing them as dev dependencies. Production initial/reference data should generally use versioned data migrations.

A runnable [empty-schema example](examples/migrations-fixtures/README.md) demonstrates the full migration + fixtures cycle against PostgreSQL.

## Scope

One default PostgreSQL ORM connection and EntityManager, attribute mapping, native PHP lazy objects, optional entity L2 cache and Symfony Profiler integration. Migration services use dedicated connections. DQL, other database platforms, XML/YAML mapping and a Doctrine ORM dependency are outside the package scope.

## Validation

GitHub Actions runs PHP 8.4/8.5 against locked dependencies and Symfony 7.4/8.1 with PostgreSQL 17. The core is installed from its public beta tag through Composer, using the VCS bootstrap while registry setup is pending. Integration tests fail if skipped, the migration/fixture example runs, and a final smoke test boots the bundle after `composer install --no-dev`, without Twig, WebProfiler, Doctrine ORM or the optional migration/fixture libraries.

Run `composer test` for functional kernel, persisted profile, Twig rendering, console, subrequest, observer and worker-reset checks. To include PostgreSQL connection and transaction cleanup checks:

```sh
MODERNORM_TEST_URL='postgresql://user:password@127.0.0.1/modernorm_test' composer test
```

PostgreSQL tests execute real ORM INSERT/UPDATE/DELETE, association loading for all three batch kinds, entity L2 miss/put/hit and UPDATE invalidation, and a failed flush followed by Messenger reset. They use connection-local temporary tables and isolated random schemas in the designated test database. Existing application tables are not changed.

Functional tests boot a standalone Symfony kernel and verify persisted profiles, rendered panels and worker reset. The [migration/fixture example](examples/migrations-fixtures/README.md) exercises an empty PostgreSQL schema end to end.
