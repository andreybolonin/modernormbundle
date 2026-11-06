# Development and validation

[Documentation](README.md) · [Contributing](../CONTRIBUTING.md) · [Pinned core compatibility](../tools/core-compatibility/README.md)

For bundle development, clone this repository and run `composer install`. The core is resolved from Packagist; a sibling checkout is not required. Keep machine-local Composer paths out of the public manifest. For joint development, use the explicit override bootstrap described in the compatibility guide or a separate local Composer manifest with a path repository.

## Test matrix

GitHub Actions runs PHP 8.4/8.5 against locked dependencies and Symfony 7.4/8.1 with PostgreSQL 17. The core is installed from Packagist through Composer. Integration tests fail if skipped, the migration/fixture example runs, and a final smoke test boots the bundle after `composer install --no-dev`, without Twig, WebProfiler, Doctrine ORM or the optional migration/fixture libraries.

Run `composer test` for functional kernel, persisted profile, Twig rendering, console, subrequest, observer and worker-reset checks. To include PostgreSQL connection and transaction cleanup checks:

```sh
MODERNORM_TEST_URL='postgresql://user:password@127.0.0.1/modernorm_test' composer test
```

PostgreSQL tests execute real ORM INSERT/UPDATE/DELETE, association loading for all three batch kinds, entity L2 miss/put/hit and UPDATE invalidation, and a failed flush followed by Messenger reset. They use connection-local temporary tables and isolated random schemas in the designated test database. Existing application tables are not changed.

Functional tests boot a standalone Symfony kernel and verify persisted profiles, rendered panels and worker reset. The [migration/fixture example](../examples/migrations-fixtures/README.md) exercises an empty PostgreSQL schema end to end.

## Core compatibility

The normal dependency matrix tests the published core constraint. A separate [pinned-core compatibility matrix](../tools/core-compatibility/README.md) runs the full bundle suite and real bulk SoftDelete/restore checks for saved Profiler views and OpenTelemetry metrics on PHP 8.4/8.5, without requiring an unreleased core for ordinary installations.

## Static analysis and code style

```sh
composer install
composer install --working-dir=examples/observability
composer quality
# Apply formatting, then verify again:
composer cs:fix
composer quality
```

`composer analyse` runs PHPStan level 5 over production code, tests, CI tools and examples. The example owns its SDK dependencies; the root requires only the Metrics API as a development dependency for optional-adapter analysis. PHP-CS-Fixer enforces PSR-12 across all shipped PHP, including pinned-core tools. Neither tool uses a baseline or per-line suppressions.

The `PHPStan and PSR-12` CI job runs `composer quality` on PHP 8.4. Core compatibility jobs additionally run `composer analyse:core` with the pinned checkout, covering APIs not yet present in the published minimum core. Request state and database nesting are checked at runtime; analysis does not cache potentially stateful getter results across callbacks.
