# Pinned core compatibility checks

These checks run outside the default suite so the bundle can retain its published minimum core constraint while separately verifying unreleased core behavior. CI checks out core **618407b8128dd83b0aa706fa83831837cca90bef** and runs the complete bundle suite plus the focused Profiler and SDK tests on PHP 8.4 and 8.5. Update the pin and compatibility evidence together when adopting a newer revision.

With the root and observability example dependencies installed:

```bash
export MODERNORM_CORE_PATH=/absolute/path/to/modernorm
export MODERNORM_TEST_URL=postgresql://YOUR_USER@127.0.0.1/modernorm_test
php vendor/bin/phpunit --bootstrap tools/core-compatibility/bootstrap.php --fail-on-skipped
php vendor/bin/phpunit --bootstrap tools/core-compatibility/bootstrap.php tools/core-compatibility/tests --fail-on-skipped
php vendor/bin/phpunit --bootstrap examples/observability/bootstrap.php examples/observability/tests tools/core-compatibility/otel --fail-on-skipped
```

Both development bootstraps prepend the chosen core loader **after** Composer loaders are registered. Focused tests verify the actual BulkExecutor source file, so a green run cannot accidentally certify the older installed core.

The tests execute real softDelete/restore SQL, a zero-row restore and a failed atomic update using PostgreSQL temporary tables. The saved Profiler must retain SOFT_DELETE/RESTORE, policy, row counts, success/error and known/unknown commit outcomes after collector serialization and Statistics reset. The SDK must export distinct `bulk_operation=soft_delete|restore` streams, row counts and errors without an extra UPDATE operation or replay on reset/flush. Labels and saved events must not expose row values or predicates. The existing generic Profiler renderer and OpenTelemetry adapter already support the new enum values; no production adapter branches are needed.

These SDK tests use the official in-memory exporter. They do not claim a new Collector outage test or a new Grafana visual audit; the existing end-to-end stack verifier remains documented under `examples/observability`.

## Local verification — 2026-11-06

Against core `72eb812`: full bundle suite **64 tests / 403 assertions**; saved Profiler check **1 test / 15 assertions** on PHP 8.4 and 8.5; optional SDK suite plus new state-transition check **13 tests / 161 assertions** on PHP 8.5. All passed without skips.

Core Redis verification used isolated Redis 8.10.3 processes and PostgreSQL: **555 tests without skips** on PHP 8.4.27 (ext-redis 6.2.0) and PHP 8.5.12 (ext-redis 6.3.0). Local PHP 8.5's extension was built with phpize and enabled via `conf.d/20-redis.ini`. These local results complement the core's successful Linux PHP 8.4/8.5 locked/latest/lowest matrix on the same revision.

## Executed DML compatibility

Core `618407b` adds request-wide INSERT/UPDATE/DELETE execution aggregates and optional DML details on SQL events. Compatibility checks now verify saved DML tables, rollback/zero-row semantics, absence of planned fallbacks, old serialized snapshots/collectors and distinct DML OTLP counters/rows. Default bundle tests still support the published minimum core, where DML is explicitly unavailable. The new core revision must be available remotely before running its pinned CI job.
