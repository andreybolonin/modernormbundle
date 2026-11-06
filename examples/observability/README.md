# OpenTelemetry metrics prototype

A runnable vertical slice: **ModernORM operation events and L2 logger → official OpenTelemetry PHP SDK → OTLP/HTTP → OpenTelemetry Collector → VictoriaMetrics → Grafana**. The Collector also exposes a Prometheus-compatible endpoint. No shared PHP memory, Redis or application `/metrics` route is required.

This is an opt-in prototype, not an automatically enabled production integration. The example owns its SDK/exporter dependencies. The bundle adapter depends on the OpenTelemetry Metrics API; the ORM core has no OpenTelemetry dependency.

## Run locally

Requirements: PHP 8.4+, `pdo_pgsql`, Python 3, Docker Compose, a PHP-FPM binary, the bundle's root Composer dependencies, and a dedicated PostgreSQL test database. Use ModernORM 0.1.0-beta.3 or newer with the extended bulk/hydration/UnitOfWork operation events. The example fails explicitly on an older core.

From this directory:

```bash
composer install
export MODERNORM_TEST_URL='postgresql://YOUR_USER@127.0.0.1/modernorm_test'
docker compose up -d
php worker.php 30 1000
```

For unreleased core development only, optionally set `MODERNORM_CORE_PATH` to a compatible core checkout; normal installation uses the Composer-installed package.

The last command runs 30 real ORM jobs, one second apart. The workload creates PostgreSQL temporary tables, persists and loads entities, exercises ManyToOne/OneToMany batching and entity L2, performs bulk INSERT/UPDATE/DELETE, and catches one deliberate SQL division-by-zero error outside a transaction. It does not modify existing application tables.

Open:

- [Grafana dashboard](http://127.0.0.1:13000/d/modernorm-operations/modernorm-operations) — provisioned VictoriaMetrics datasource and eleven panels, including L2 operations, per-entity hit ratio and invalidations.
- [Collector Prometheus endpoint](http://127.0.0.1:18889/metrics).
- [VictoriaMetrics](http://127.0.0.1:18428/vmui/).

All published Docker ports bind to loopback. Grafana's anonymous viewer and local development credentials are for this local example. The Compose stack uses pinned versions: Collector 0.164.0, VictoriaMetrics 1.155.0 and Grafana 13.3.0; Composer locks the PHP dependencies.

To stop the example, run `docker compose down`. The metrics volume is retained. Remove this example's volume only when you intentionally want to discard its history.

## Verify PHP-FPM and job boundaries

```bash
python3 verify.py
```

If your CLI and FPM binaries are not on PATH, set `PHP_BIN` and `PHP_FPM_BIN` explicitly. Both must be PHP 8.4+ and have `pdo_pgsql`. The verifier starts an isolated four-process FPM pool, sends real concurrent FastCGI requests and stops the pool on exit. It also starts one persistent CLI worker.

The verifier checks:

1. Three jobs reset request Statistics while retaining the same MeterProvider.
2. Eight concurrent requests are handled by four distinct FPM writers.
3. Per-writer SQL counters, error counters, flush/batch/bulk counts, affected rows and histogram counts match the workload's Statistics and the Collector. SQL and global L2 counts are also checked in VictoriaMetrics. L2 hit/miss/put/invalidation totals match request-local cache counters across resets.
4. A job still completes while the Collector is stopped; its export reports failure.
5. After restarting the same Collector, two more jobs and four FPM requests are counted correctly under the same writer identities.
6. Closing the worker does not repeat its already exported delta.
7. Grafana's dashboard is provisioned and metric labels contain no SQL, raw entity classes, associations, request IDs, job IDs or exception text. L2 alone includes the explicitly configured `product` entity alias.

The verifier deliberately stops/restarts **this example's Collector**, so run it against an isolated local stack. It uses a distinct deployment prefix for each test run to separate assertions from existing data. Application tables are untouched. See [validation results](VALIDATION.md).

Adapter tests use the official SDK's in-memory exporter; the PostgreSQL integration test also requires `MODERNORM_TEST_URL`:

```bash
composer test
```

## Adapter and lifecycle

`OperationMetrics` implements the existing `OperationObserver` contract and is tagged `modernorm.statistics_observer` in the [example kernel](src/Kernel.php). Its constructor receives an application-provided `MeterInterface`. It records instruments locally, without network I/O or event history. Collection works with the Symfony Profiler disabled.

`FlushMetrics` accepts an application-provided flush callable. In this example it calls the official MeterProvider's `forceFlush()`:

- HTTP: at `kernel.terminate`, after the response has been sent.
- Messenger: on handled/failed message events, before the usual service reset.
- Console: at command termination.
- Custom CLI loops: explicitly after each job. The example worker exercises this path; a unit test checks the real Messenger handled/failed event dispatch paths.

The MeterProvider and adapters are **not** tagged `kernel.reset`. Request Statistics, profiler history and ORM context may reset independently. The example worker resets its ORM context and request counters between jobs while keeping the provider alive. There is no timer thread: long-running jobs must flush at suitable checkpoints if metrics are needed before the job ends.

## Global entity L2 metrics

`CacheMetrics` implements the existing core `CacheLogger` interface. Register it alongside the operation observer, using the same application-provided MeterInterface:

```php
use ModernORM\Bundle\Observability\OpenTelemetry\CacheMetrics;

$services->set(CacheMetrics::class)
    ->args([new Reference('prototype.meter'), [Entity\Product::class => 'product']])
    ->tag('modernorm.cache_logger');
```

The bundle's dispatcher always updates its existing `StatisticsCacheLogger`, then calls tagged cache loggers. Existing profiler counters and their reset behavior remain unchanged. Tagged loggers run independently of the Statistics enabled flag and HTTP Profiler, covering ordinary cached finds, cached ManyToOne batch loading and invalidation paths. There is no snapshot polling or replay on reset. Do not tag a logger/SDK provider with `kernel.reset`; it must retain its instruments across jobs. The same flush boundaries as operation metrics apply.

Entity labels use an explicit class-to-alias allowlist, fixed for the provider lifetime. Unlisted classes share `entity="other"`; the default empty map exports only this aggregate. The reserved `other` alias cannot be configured. Aliases may group several classes. Never generate aliases from identifiers, tenants, requests or jobs. Only the four fixed `operation` values and configured aliases are exported; no new label values are retained from event input.

`modernorm.l2.operations` counts logger notifications:

- `hit`: a usable cached entity entry was found.
- `miss`: an L2 lookup did not produce a usable entry, including invalid entries or an absent database row. No negative entry is published.
- `put`: a successful entity payload publication, not every SQL read.
- `invalidation`: a successful ORM key/class invalidation call. Pre-write and post-commit invalidations are separate events; a class invalidation counts once, not once per affected row. SoftDelete/restore use these existing invalidation paths on compatible cores.

Identity Map hits, transaction cache bypasses, repository queries that bypass L2 and raw PSR-6 operations are not additional hits/misses. Redis TTL expiry and memory-pressure evictions are not observable through this API and need backend monitoring. Invalidation failures do not produce a successful notification. A put event does not prove that a concurrent invalidation left the payload reachable.

Logger callback exceptions are isolated from ORM/cache work and remaining loggers. `CacheLoggerDispatcher::failures()` counts failed callbacks over its service lifetime; it does not contain exception text. Instrument construction/configuration errors still fail service creation. Network export failures use `FlushMetrics`' existing best-effort contract. Counters are telemetry, not a durable audit log.

## Metrics

| OTLP instrument | Type | Meaning |
| --- | --- | --- |
| `modernorm.operations` | Counter | Completed attempts by operation and success/error outcome |
| `modernorm.operation.duration` | Histogram, seconds | Duration of SQL, flush, batch, bulk, hydration and UnitOfWork phases |
| `modernorm.batch.size` | Histogram | Selected references or collections per batch |
| `modernorm.batch.fallbacks` | Counter | Loader fallback decisions by finite reason enum |
| `modernorm.bulk.rows` | Counter | Known input/affected/returned rows, by bulk operation and policy |
| `modernorm.hydration.rows` | Counter | Consumed rows, including explicit incomplete-scope status |
| `modernorm.hydration.entities` | Counter | Newly created entities |
| `modernorm.l2.operations` | Counter | Global L2 hit/miss/put/invalidation notifications by explicit entity alias |
| `modernorm.unit_of_work.managed` | Histogram | Managed context sizes observed at phase boundaries |

Labels are limited to operation, outcome, association kind, bulk operation/policy, phase, row kind, completion status and fallback reason; L2 adds only the configured entity alias. Resource labels identify service, deployment, runtime and writer. Histogram boundaries are fixed. Unknown affected/returned row counts are omitted, not converted to zero. Fallbacks are separate decisions, not additional SQL operations. Overlapping scopes must not have their durations or errors added together.

The Collector translates names to Prometheus conventions, for example `modernorm_operations_total` and `modernorm_operation_duration_seconds_bucket`. Dashboard rates are calculated **per writer before summing**. The cumulative count panel is diagnostic, not an all-time business total. The first observed delta is counted by the Collector, but rate calculations cannot infer a pre-observation sample.

The Collector translates the L2 counter to `modernorm_l2_operations_total`. The dashboard computes per-writer rates before aggregation and uses `hits / (hits + misses)` by entity, including a zero numerator for miss-only series; no-lookup intervals have no defined ratio. First observations may need a second scrape before a rate exists. Traces, exemplars, backend Redis metrics, host metrics and request-duration metrics remain outside this prototype.

## Worker identity and temporality

The SDK uses delta temporality. Each FPM request creates its own SDK state and flushes its interval before that state disappears. Sequential jobs use the same provider and export successive deltas. Concurrent workers remain distinct metric streams using `service.instance.id`; the example uses an instance prefix plus PID. Do not remove that resource identity before accumulation.

For deployment, set `MODERNORM_INSTANCE_ID` to a unique host/container instance prefix. The prototype's PID identity does not encode process birth time; a production integration must define process-generation identity and PID reuse behavior. Do not use request/job UUIDs as stream identities: that creates a new timeseries for every scope.

The single Collector accumulates deltas in memory with bounded stream count and staleness. Aggregation across workers happens in PromQL/MetricsQL. Multiple Collectors require stream-affine routing or another explicitly validated accumulation strategy; a random load balancer is insufficient. This prototype intentionally runs one Collector.

## Failure contract and remaining work

The PHP transport makes one HTTP attempt per flush, with a 250 ms configured timeout and no retries. Export failure is caught and reported without throwing into application code. End-of-request flushing can still occupy an FPM worker after the response is sent. Aborted/killed processes may lose their last unexported interval.

Delivery is **best effort**. A failed delta is not durably queued or replayed. OTLP acknowledgement does not imply durable storage in VictoriaMetrics. Collector accumulation is in memory: restarting or expiring a stream resets its cumulative history; backend history remains stored. The restart check validates recovery and absence of replay, not lossless continuity. Remote-write queues are not a substitute for durable end-to-end delivery.

Before production use, resolve deployment/process-generation identity, Collector HA/routing, retry/queue/deduplication policy, failure monitoring and hosting-specific lifecycle behavior. Test long-job checkpoints, process recycling and shutdown paths under the intended hosting environment. The selected Collector's delta-to-cumulative processor is currently classified alpha; the pinned configuration is a validated prototype choice, not a stability guarantee.

References: [OpenTelemetry metric stream model](https://opentelemetry.io/docs/specs/otel/metrics/data-model/), [PHP SDK](https://opentelemetry.io/docs/languages/php/), [delta-to-cumulative processor](https://github.com/open-telemetry/opentelemetry-collector-contrib/tree/v0.164.0/processor/deltatocumulativeprocessor).
