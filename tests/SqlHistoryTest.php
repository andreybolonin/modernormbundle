<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use ModernORM\Bundle\Profiler\SqlHistory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;

final class SqlHistoryTest extends TestCase
{
    private function history(int $limit = 2, int $maxLength = 128): SqlHistory
    {
        $profiler = new Profiler(new FileProfilerStorage('file:'.sys_get_temp_dir().'/modernorm-sql-history'));
        return new SqlHistory(true, $limit, $maxLength, static fn () => $profiler);
    }
    public function testBoundedUtf8TextAndDistinctFullSqlFingerprints(): void
    {
        $history = $this->history(2, 128);
        self::assertNull($history->start());
        $history->startRequest();
        $prefix = 'SELECT '.str_repeat('я', 100);
        $history->record($history->start(), $prefix.' first', 'query', null);
        $history->record($history->start(), $prefix.' second', 'query', null);
        self::assertSame(1, preg_match('//u', $history->queries()[0]['sql']));
        self::assertLessThanOrEqual(128, strlen($history->queries()[0]['sql']));
        self::assertTrue($history->queries()[0]['truncated']);
        self::assertSame($history->queries()[0]['sql'], $history->queries()[1]['sql']);
        self::assertNotSame($history->queries()[0]['fingerprint'], $history->queries()[1]['fingerprint']);
        $history->record($history->start(), 'SELECT 3', 'query', null);
        self::assertCount(2, $history->queries());
        self::assertSame(1, $history->dropped());
    }
    public function testResetDiscardsInFlightQueriesAndStopsHistory(): void
    {
        $history = $this->history();
        $history->startRequest();
        $measurement = $history->start();
        $history->reset();
        $history->startRequest();
        $history->record($measurement, 'SELECT 1', 'query', null);
        self::assertSame([], $history->queries());
        $history->stopRequest();
        self::assertNull($history->start());
    }
    public function testDisabledCollectionDoesNotResolveProfilerOrStartClock(): void
    {
        $history = new SqlHistory(false, 2, 128, static fn () => throw new \LogicException('Should not resolve profiler'));
        $history->startRequest();
        self::assertNull($history->start());
    }
}
