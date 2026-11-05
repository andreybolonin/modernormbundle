# Changelog

## 0.1.0-beta.1 — 2026-11-05

First public beta, with a bounded integration contract.

- PostgreSQL Connection, native-lazy EntityManager, Registry, attribute mappings and PSR-6 cache integration.
- Connection Statistics, debug toolbar, saved Profiler panel, bounded opt-in SQL history and exact repeated-query groups.
- Reset of counters/history and closed EntityManager recovery at HTTP/Messenger boundaries.
- Optional Doctrine Migrations integration with an owned schema filter, entity SchemaProvider and namespaced commands.
- Optional Data Fixtures integration with dependency/group selection, references/generated IDs, atomic loading and L2 invalidation.
- PHP 8.4/8.5 and Symfony 7.4/8.1 CI, PostgreSQL integration tests and no-dev runtime smoke.

Prometheus/OpenTelemetry exporters, EXPLAIN/advisor panels and standalone hydration/DML counters are not part of this beta. Existing Doctrine ORM application code is not migrated automatically.
