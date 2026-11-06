# Contributing

Use PHP 8.4/8.5, Symfony 7.4/8.1 and PostgreSQL 17. Install dependencies with Composer; the published core package is resolved without a sibling checkout.

```sh
composer install
composer install --working-dir=examples/observability
composer quality
MODERNORM_TEST_URL='postgresql://user:password@127.0.0.1/modernorm_test' composer test -- --fail-on-skipped
composer validate --strict
```

Use a dedicated test database. Tests create temporary tables and random schemas. CI also runs the migration/fixture example and a runtime smoke after install --no-dev.

Provide focused regression coverage for behavior changes. Preserve separation between the ORM core and Symfony adapters, optional dependencies, bounded profiler history and worker reset. Describe transaction/cache outcomes for migration/fixture changes and preserve constructor-injected fixture dependencies.

Development against a local core checkout can use a separate Composer manifest/path repository. Do not commit machine-local paths to the public package manifest. Security reports follow [SECURITY.md](SECURITY.md). Community PRs are reviewed independently; no upstream synchronization or response SLA is promised.

Quality checks cover `src`, the main test suite, CI tools and runnable examples at PHPStan level 5, without a baseline. The optional example SDK is installed for analysis; it remains outside runtime dependencies. Run `composer cs:fix` to apply PSR-12 to all package PHP, tests, tools and examples; vendor/runtime directories are excluded. PHPStan does not assume stateful getters stay unchanged across fixture callbacks.

For unreleased APIs, `MODERNORM_CORE_PATH=/path/to/core composer analyse:core` adds the pinned-core compatibility tools to the same analysis. CI runs this after installing the optional SDK in the core compatibility jobs.
