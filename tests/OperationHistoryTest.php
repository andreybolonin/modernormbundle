<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use ModernORM\Bundle\Profiler\OperationHistory;
use ModernORM\Statistics;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationObserver;
use PHPUnit\Framework\TestCase;

final class OperationHistoryTest extends TestCase
{
    public function testExporterWorksWithoutAnHttpProfilerAndFailureIsIsolated(): void
    {
        $exporter = new class () implements OperationObserver {
            public int $calls = 0;
            public function completed(OperationEvent $event): void
            {
                ++$this->calls;
            }
        };
        $broken = new class () implements OperationObserver {
            public function completed(OperationEvent $event): void
            {
                throw new \RuntimeException('exporter unavailable');
            }
        };
        $history = new OperationHistory(2, null, [$broken, $exporter]);
        $statistics = new Statistics(true, $history);
        $statistics->recordSql($statistics->start(), false);
        self::assertSame(1, $exporter->calls);
        self::assertSame(1, $statistics->snapshot()->observerErrors);
        self::assertSame(1, $statistics->snapshot()->sqlCount);
        self::assertSame([], $history->events());
        self::assertSame(0, $history->dropped());
    }
}
