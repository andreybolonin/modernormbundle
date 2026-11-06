# Local prototype validation

## Global L2 extension — 2026-11-06

Validated with PHP CLI/FPM 8.5.12, local PostgreSQL, OpenTelemetry PHP SDK 1.16.0 / Metrics API 1.11.0, and the pinned Collector → VictoriaMetrics → Grafana example stack. Symfony Profiler was disabled during end-to-end checks.

| Scenario | Result |
| --- | --- |
| Three CLI jobs and eight requests across four FPM workers | 11 scopes, five writers, 278 SQL operations, 11 SQL errors, 33 bulk calls |
| Global L2 hit/miss/put/invalidation totals | Exact match with per-scope StatisticsCacheLogger at Collector and VictoriaMetrics across job/request resets |
| Collector unavailable | Job completed; export returned false; observed local scope duration 0.088 s |
| Collector restarted with the same writers | Two jobs and four FPM requests; 151 SQL operations, six SQL errors, 18 bulk calls; exact SQL/L2 match |
| Shutdown after a successful flush | No duplicate exported counts |
| Grafana provisioning | Eleven panels, including L2 operations, hit ratio by entity alias and invalidations |
| Labels | Only explicit `product` alias on L2; no raw entity classes, SQL, association names, identifiers or exception text |

Counts depend on metadata warmup and describe this run, not a benchmark. The outage interval is intentionally lost, not queued or replayed. Collector accumulation restarts; the verification proves recovery and absence of duplicate delivery, not durable continuity.

The SDK integration test separately verifies ordinary cached `find()`, mixed hit/miss ManyToOne batch loading, transaction bypass, UPDATE invalidation, bulk UPDATE/DELETE, absent-row misses without puts, and explicit key/class invalidation. It runs with Statistics disabled and compares each exported delta to local cache counters. Only PostgreSQL temporary tables are created. Unit tests cover allowlisted/unknown labels, resets before/after export, SDK shutdown, Messenger success/failure flushes and isolation of logger callback failures.

Final checks: **50 bundle tests / 345 assertions** and **12 optional SDK tests / 132 assertions**, all passing without skips. All three new dashboard expressions returned populated series from VictoriaMetrics; the hit ratio was within [0, 1]. PHP/Python syntax, dashboard JSON and `git diff --check` passed.

`verify.py` gives each run its own Symfony cache directory and writer prefix. It starts and stops owned FPM/CLI processes and briefly restarts only the example Collector. The Docker stack remains running for inspection. Backend Redis TTL/memory-pressure metrics and durable telemetry delivery remain outside this contract.

## Original operation-metrics slice — 2026-11-05

Validated on 2026-11-05 using PHP CLI/FPM 8.4.27, PostgreSQL 17, OpenTelemetry PHP SDK 1.16.0, Metrics API 1.11.0, OTLP exporter 1.5.0, Collector 0.164.0, VictoriaMetrics 1.155.0 and Grafana 13.3.0. The bundle used the local core with extended operation events. Symfony Profiler was disabled.

| Scenario | Result |
| --- | --- |
| 3 jobs in one process + 8 requests across 4 FPM workers | 11 scopes, 5 writers, 279 SQL operations, 11 SQL errors, 33 bulk calls |
| Collector / VictoriaMetrics SQL comparison | Exact match with per-scope Statistics; counts can vary with metadata cache warmup |
| Flush/batch/bulk counters, affected rows and SQL histogram count | Exact match at the Collector |
| Collector unavailable | ORM job completed; export returned false; observed scope duration 0.047 s on local connection refusal |
| Collector restarted, same writers | 2 jobs + 4 FPM requests: 150 SQL operations, 6 SQL errors, 18 bulk calls; exact Collector/VM match |
| Worker shutdown after flushing | No duplicate operation counts |
| Grafana dashboard API | Provisioned dashboard found |
| Sensitive/unbounded business labels | No SQL, entity/association names, request/job IDs or exceptions |

The job during the outage was intentionally not replayed. Collector counters restarted from subsequent deltas. This proves the documented best-effort failure contract, not durable delivery or lossless cumulative continuity. Timings describe this local run and are not a benchmark.

`verify.py` creates isolated FPM/CLI processes and a test-run instance prefix. It leaves the Docker stack running for inspection and prints its temporary process-log directory. VictoriaMetrics verification bypasses query caching and requests a 1 ms latency offset so recently ingested samples can be checked without changing server-wide query behavior.

Adapter tests: **6 tests, 33 assertions**, including real Messenger handled/failed event dispatch. Existing bundle suite with its original dependency set: **32 tests, 255 assertions**. Composer manifests validate; 15 added PHP files pass syntax checks. The Grafana dashboard was also opened in headless Chrome and visually inspected with populated panels.

The original visual check above covered the eight-panel operation-metrics slice. New L2 panels have provisioning and live query checks; no new visual audit is claimed.
