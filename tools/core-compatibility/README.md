# Pinned core compatibility checks

These checks run outside the default suite so the bundle can retain its published minimum core constraint while separately verifying unreleased core behavior. CI checks out core **15db53058e10fa1fcd3ad7464c7531cb2a131600** (beta.5 candidate) and runs the complete bundle suite plus the focused Profiler and SDK tests on PHP 8.4 and 8.5 against PostgreSQL 17, 18 and 19rc1 (six required compatibility jobs). Update the pin and compatibility evidence together when adopting a newer revision.

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

## Stabilization candidate — 2026-11-08

The pin now targets core `710dd43`, including the UnitOfWork extractions and SchemaTool round-trip fixes. All 19 core CI jobs (quality plus 18 PHP/dependency/PostgreSQL combinations) passed in [core run 42454738734](https://github.com/andreybolonin/modernorm/actions/runs/42454738734). Bundle compatibility for the exact pin is verified by the workflow above; local PostgreSQL matrix runs are not required.

## FTS migration compatibility — 2026-11-15

This FTS integration originally pinned `7228821`, including DBAL 4.6, FTS helpers, JSONB queries, cursor pagination and reversible `SchemaTool::getSchemaMigrationSql()` plans. Bundle diff consumes this complete SQL plan; the published minimum core retains its existing ordinary schema path. Bundle connections require DBAL 4.6 and preserve the source type provider.

Real migration commands cover creation of generated vectors and GIN indexes, changed weights/configuration, down migrations, empty repeat diffs, preserved metadata and fixture purging. Local PostgreSQL 18 verification on PHP 8.4 and 8.5: full bundle suite **64 tests / 403 assertions**, compatibility suite **5 tests / 49 assertions**, without skips. The default suite also passes against the installed published core on PHP 8.5. PHPStan passes for both analysis configurations. PostgreSQL 17/18/19rc1 and dependency matrices remain CI checks; the new core commit must be pushed before its pinned checkout can run remotely.

## Association Query API compatibility — 2026-11-15

The active pin is `ad789bc`, adding immutable association queries and the EntityManager/decorator entry point. The bundle needs no production changes for this API. Its complete default suite passes against this revision on PHP 8.5: **64 tests / 403 assertions**, without skips. Core verification on PHP 8.4 and 8.5: **953 tests / 6929 assertions**, with 21 Redis tests skipped because Redis was not started. PHPStan and PSR-12 pass in core. The compatibility CI matrix verifies the new exact revision on both PHP versions and its configured PostgreSQL versions.


## Beta.5 candidate

Core beta.5 is pinned to `15db53058e10fa1fcd3ad7464c7531cb2a131600`. The bundle now requires `^0.1.0-beta.5`; historical results above describe earlier pins, not release evidence for this candidate. The complete compatibility CI must pass for the exact release commit before tagging.

Local candidate verification on PostgreSQL 18.7: PHP 8.4/8.5 each pass the full bundle suite (64 tests / 403 assertions, no skips); focused FTS/Profiler compatibility passes 5 tests / 49 assertions on PHP 8.5. Bundle and pinned-core PHPStan pass. These local results do not replace the exact-candidate CI gate.

## Advanced PostgreSQL schema migrations

The `advanced-schema-core` PHPUnit group requires core `05197a218a443842756dcd1327e01881c7e6b220` or later. CI runs this group against that exact revision across the core compatibility matrix. The published-core and beta.5 candidate suites exclude the group and retain `--fail-on-skipped`.
