# OpenTelemetry, Grafana and worker lifecycle

[Documentation](README.md) · [Profiler](profiler.md) · [Runnable monitoring stack](../examples/observability/README.md)

## From a request to application metrics

The optional OpenTelemetry adapters record ORM operation and global entity L2 metrics into an application-provided MeterProvider. Use the request Profiler to inspect an individual execution and the monitoring stack to follow rates, latency distributions and cache reuse across HTTP processes and workers.

```text
ModernORM → OpenTelemetry PHP SDK → OTLP/HTTP → Collector → VictoriaMetrics → Grafana
```

The [runnable example](../examples/observability/README.md) includes pinned SDK/Collector/backend dependencies, a provisioned datasource and eleven Grafana panels. It also exposes a Prometheus-compatible Collector endpoint. It is a local prototype with documented best-effort delivery, not an automatically configured production monitoring service.

| Adapter | Provides | Service tag |
| --- | --- | --- |
| `OperationMetrics` | SQL, flush, batch, bulk, hydration and UnitOfWork counts/durations, row counters and fallback decisions | `modernorm.statistics_observer` |
| `CacheMetrics` | Global entity L2 hits, misses, puts and ORM invalidations with explicit entity aliases | `modernorm.cache_logger` |
| `FlushMetrics` | SDK flush boundaries for HTTP termination, console and Messenger success/failure | `kernel.event_subscriber` |

The ORM core has no OpenTelemetry dependency. The application supplies the MeterProvider; SDK/exporter packages belong to the application or example. Metrics adapters record locally and export at lifecycle boundaries. Global L2 metrics remain independent of Statistics/Profiler resets. Backend Redis memory-pressure eviction and TTL metrics require separate backend monitoring.

For setup, instrument names, bounded labels, entity aliases, process identity, temporality and failure behavior, use the [OpenTelemetry guide](../examples/observability/README.md). See [local validation evidence](../examples/observability/VALIDATION.md) and [core compatibility checks](../tools/core-compatibility/README.md).

## Request and worker lifecycle

Statistics, L2 counters, SQL history and event history are registered with `kernel.reset`. The EntityManager factory clears and resets its lazy proxy and closes the connection, discarding unfinished transactions. Injected EntityManager references remain valid after a closed manager is reset. Standard Symfony/Messenger service resets must remain enabled. Custom worker loops must reset services between jobs.

Main HTTP requests start a fresh measurement scope; subrequests do not reset the parent counters. Profiles store value snapshots and remain readable after service reset.

Additional `ModernORM\Statistics\OperationObserver` services can be tagged `modernorm.statistics_observer`. They receive completion events independently of the Profiler, providing an integration point for separate Prometheus/OpenTelemetry adapters. An opt-in [OpenTelemetry metrics prototype](../examples/observability/README.md) now connects the operation observer to an application-provided MeterProvider, with a runnable Collector → VictoriaMetrics → Grafana stack and a Prometheus-compatible Collector endpoint. The SDK/exporter dependencies live in the example; nothing is enabled automatically. Global L2 metrics use the separate optional `CacheMetrics` logger tagged `modernorm.cache_logger`. It records hit/miss/put/invalidation events independently of Statistics/Profiler enablement and resets, with explicit class-to-label mappings and an `other` fallback. Active tracing spans are not included.
