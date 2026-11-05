# Local prototype validation

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
