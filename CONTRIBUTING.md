# Contributing

Use PHP 8.4/8.5, Symfony 7.4/8.1 and PostgreSQL 17. Install dependencies with Composer; the published core package is resolved without a sibling checkout.

```sh
composer install
MODERNORM_TEST_URL='postgresql://user:password@127.0.0.1/modernorm_test' composer test -- --fail-on-skipped
composer validate --strict
```

Use a dedicated test database. Tests create temporary tables and random schemas. CI also runs the migration/fixture example and a runtime smoke after install --no-dev.

Provide focused regression coverage for behavior changes. Preserve separation between the ORM core and Symfony adapters, optional dependencies, bounded profiler history and worker reset. Describe transaction/cache outcomes for migration/fixture changes and preserve constructor-injected fixture dependencies.

Development against a local core checkout can use a separate Composer manifest/path repository. Do not commit machine-local paths to the public package manifest. Security reports follow [SECURITY.md](SECURITY.md). Community PRs are reviewed independently; no upstream synchronization or response SLA is promised.
