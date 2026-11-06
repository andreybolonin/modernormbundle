# Installation and configuration

[Documentation](README.md) · [Profiler and Advisor](profiler.md) · [Monitoring and workers](observability.md)

## Requirements

- PHP 8.4+, PostgreSQL and ModernORM `^0.1.0-beta.3`.
- Symfony 7.4 or 8.x.
- TwigBundle and WebProfilerBundle for the optional debug interface.

## Install

The beta requires explicit stability flags for both packages in the application's root manifest:

```sh
composer require 'andreybolonin/modernorm:^0.1.0-beta.3' 'andreybolonin/modernorm-bundle:^0.1.0-beta.3'
```

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

## Console commands

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

## Supported scope

One default PostgreSQL ORM connection and EntityManager, attribute mapping, native PHP lazy objects, optional entity L2 cache and Symfony Profiler integration. Migration services use dedicated connections. DQL, other database platforms, XML/YAML mapping and a Doctrine ORM dependency are outside the package scope.

For tests, local core overrides and compatibility checks, see [development and validation](testing.md).
