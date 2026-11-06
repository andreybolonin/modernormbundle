# Changelog

## Unreleased

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
