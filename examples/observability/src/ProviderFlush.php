<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Example\Observability;

use OpenTelemetry\SDK\Metrics\MeterProviderInterface;

final class ProviderFlush
{
    public function __construct(private readonly MeterProviderInterface $provider)
    {
    }
    public function __invoke(): bool
    {
        return $this->provider->forceFlush();
    }
}
