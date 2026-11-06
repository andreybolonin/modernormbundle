<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Example\Observability;

use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;
use OpenTelemetry\SDK\Resource\ResourceInfo;

final class MetricsProvider
{
    public static function create(): MeterProviderInterface
    {
        // Stable across sequential requests in one FPM process, distinct across concurrent writers.
        // Override the deployment instance prefix when deploying multiple hosts/containers.
        $instance = (getenv('MODERNORM_INSTANCE_ID') ?: gethostname()).':'.getmypid();
        $resource = ResourceInfo::create(Attributes::create([
            'service.name' => 'modernorm-otel-prototype',
            'service.instance.id' => $instance,
            'deployment.environment.name' => 'local',
            'modernorm.runtime' => PHP_SAPI === 'fpm-fcgi' ? 'fpm' : 'worker',
        ]));
        // No retries in this small prototype: at most one bounded HTTP attempt per flush.
        $transport = (new PsrTransportFactory())->create(
            getenv('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') ?: 'http://127.0.0.1:14318/v1/metrics',
            'application/x-protobuf',
            timeout: 0.25,
            maxRetries: 0,
        );
        $exporter = new MetricExporter($transport, TemporalityPreference::delta());
        return MeterProvider::builder()->setResource($resource)->addReader(new ExportingReader($exporter))->build();
    }
}
