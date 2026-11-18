# Changelog

## Unreleased

- Support MySQL schema sources in `modernorm:mapping:import` via `--source-url-env`, with AUTO_INCREMENT, unsigned types, ENUM/SET, platform-aware indexes, explicit manual-review warnings and MySQL 8.4 CI coverage. Application persistence remains PostgreSQL-only.

- Add `modernorm:mapping:import` to generate new attribute entities from existing PostgreSQL tables, with schema/table selection, dry-run, simple owning associations, identity/serial IDs and explicit unsupported-mapping reports.

## 0.1.0-beta.5 — 2026-11-17

- Require core beta.5 and advance the compatibility pin to `15db53058e10fa1fcd3ad7464c7531cb2a131600`, including scalar dirty checking, hydration/UoW extraction and flush/COMMIT contract regressions.

- Advance the compatibility core pin to `ad789bc`, covering the new EntityManager Association Query API without bundle production changes.

- Pin core compatibility to `7228821`; integrate reversible FTS migration plans with ordinary diff changes and require DBAL 4.6. Preserve source type providers on isolated connections and FTS metadata during ownership checks and fixture purge.

- Show request-wide executed DML instead of planned queues on the Profiler summary; add DML statement/row/duration OTLP instruments and Grafana panels, preserving unavailable states for older cores/profiles.

- Add PHPStan level 5 and PHP-CS-Fixer PSR-12 Composer/CI checks for source, tests, tools and examples; fix type contracts and format existing PHP without a baseline.

- Shorten the README into a feature overview and quick start; move detailed setup, Profiler/Advisor, monitoring, migrations, fixtures and validation contracts into linked documentation.

- Verify real core soft_delete/restore events in saved Profiler views and OpenTelemetry counters/histograms, including zero-row/failure outcomes. Add a pinned-core PHP 8.4/8.5 CI matrix and correct development core-override autoloader precedence.

- Add configurable Advisor hints for repeated successful flushes and association/target-specific size-one fallbacks and one-item batches, with explicit retained-event coverage and aggregate fallback behavior.

- Add optional global L2 OpenTelemetry counters through tagged cache loggers, independently of Statistics/Profiler resets; isolate logger callback failures and bound entity labels with an explicit allowlist. Extend the OTLP example, Grafana panels and lifecycle/SDK verification.

- Rank Performance Advisor findings by severity and measured duration, disclose incomplete SQL samples, and add configurable hydration time/entity-count diagnostics with focused regression coverage.

## 0.1.0-beta.3 — 2026-11-05

- Make repeated worker resets safe when the EntityManager lazy proxy has not been initialized since the preceding reset.
- Add eight saved-profile tabs, request dashboard, configurable Performance Advisor and native Symfony light/dark themes.
- Display extended bulk, hydration and UnitOfWork events from ModernORM beta.3; update the README with actual screenshots.
- Add opt-in OpenTelemetry Metrics API observer and HTTP/console/Messenger flush boundaries, independent of Profiler history and request Statistics resets.
- Add an isolated OTLP/HTTP → Collector → VictoriaMetrics → Grafana prototype with pinned dependencies, dashboard provisioning and real PHP-FPM/concurrent-writer/restart verification. Delivery remains best effort; no core monitoring dependencies or automatic SDK setup.

## 0.1.0-beta.1 — 2026-11-05

First public beta, with a bounded integration contract.

- PostgreSQL Connection, native-lazy EntityManager, Registry, attribute mappings and PSR-6 cache integration.
- Connection Statistics, debug toolbar, saved Profiler panel, bounded opt-in SQL history and exact repeated-query groups.
- Reset of counters/history and closed EntityManager recovery at HTTP/Messenger boundaries.
- Optional Doctrine Migrations integration with an owned schema filter, entity SchemaProvider and namespaced commands.
- Optional Data Fixtures integration with dependency/group selection, references/generated IDs, atomic loading and L2 invalidation.
- PHP 8.4/8.5 and Symfony 7.4/8.1 CI, PostgreSQL integration tests and no-dev runtime smoke.

Prometheus/OpenTelemetry exporters, EXPLAIN/advisor panels and standalone hydration/DML counters are not part of this beta. Existing Doctrine ORM application code is not migrated automatically.
