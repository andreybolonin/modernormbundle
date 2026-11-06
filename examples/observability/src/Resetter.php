<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Example\Observability;

use ModernORM\Bundle\EntityManagerFactory;
use ModernORM\Statistics;
use ModernORM\Cache\Logging\StatisticsCacheLogger;

/** Job boundaries reset request statistics/context, while the MeterProvider stays alive. */
final class Resetter
{
    public function __construct(private readonly EntityManagerFactory $manager, private readonly Statistics $statistics, private readonly StatisticsCacheLogger $cache)
    {
    }
    public function reset(): void
    {
        $this->manager->reset();
        $this->statistics->reset();
        $this->cache->reset();
    }
}
