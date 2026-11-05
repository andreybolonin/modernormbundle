<?php

declare(strict_types=1);
namespace ModernORM\Bundle\Example\Observability;

use OpenTelemetry\SDK\Metrics\MeterProvider;

final class ProviderFlush
{
    public function __construct(private readonly MeterProvider $provider) {}
    public function __invoke(): bool { return $this->provider->forceFlush(); }
}
