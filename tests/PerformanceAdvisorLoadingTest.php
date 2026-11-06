<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use ModernORM\Bundle\DependencyInjection\Configuration;
use ModernORM\Bundle\Profiler\DataCollector;
use ModernORM\Bundle\Profiler\OperationHistory;
use ModernORM\Bundle\Profiler\PerformanceAdvisor;
use ModernORM\Bundle\Profiler\SqlHistory;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use ModernORM\Statistics;
use ModernORM\Statistics\BatchFallbackReason;
use ModernORM\Statistics\BatchKind;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;

final class PerformanceAdvisorLoadingTest extends TestCase
{
    private function analyze(array $events = [], array $metrics = [], array $options = [], int $dropped = 0): array
    {
        return (new PerformanceAdvisor($options))->analyze($metrics + ['unitOfWork' => null], [], ['enabled' => false, 'hits' => 0, 'misses' => 0], 0, $events, $dropped);
    }

    private function batch(?string $association = 'Order::lines', string $entity = 'Line', int $size = 32, int $selected = 1, bool $failed = false, BatchKind $kind = BatchKind::ONE_TO_MANY, int $duration = 2_000_000): OperationEvent
    {
        return new OperationEvent(OperationKind::BATCH, 1, $duration, $failed, $kind, $entity, $association, $size,
            selectedReferences: $kind === BatchKind::MANY_TO_ONE ? $selected : 0,
            selectedCollections: $kind === BatchKind::MANY_TO_ONE ? 0 : $selected);
    }

    private function fallback(?string $association = 'Order::lines', string $entity = 'Line', BatchKind $kind = BatchKind::ONE_TO_MANY): OperationEvent
    {
        return new OperationEvent(OperationKind::BATCH_FALLBACK, 1, 0, false, $kind, $entity, $association, 1, fallbackReason: BatchFallbackReason::SIZE_ONE);
    }

    public function testFlushUsesCompleteSuccessfulAggregateAndMeasuredTime(): void
    {
        $findings = $this->analyze(metrics: ['flush' => ['count' => 7, 'errors' => 2, 'timeMs' => 80.0]], dropped: 100);
        self::assertCount(1, $findings);
        self::assertSame('repeated_flush', $findings[0]['id']);
        self::assertSame('unitofwork', $findings[0]['tab']);
        self::assertStringContainsString('5 successful flush attempts out of 7 (2 failed)', $findings[0]['evidence']);
        self::assertStringContainsString('80.00 ms', $findings[0]['evidence']);
        self::assertStringContainsString('do not prove a flush inside a loop', $findings[0]['evidence']);
        self::assertStringContainsString('business semantics', $findings[0]['recommendation']);
    }

    public function testFailedFlushesDoNotSatisfyThresholdAndCustomThresholdWorks(): void
    {
        foreach ([['count' => 5, 'errors' => 1], ['count' => 10, 'errors' => 10], ['count' => 0, 'errors' => 0]] as $flush) {
            self::assertSame([], $this->analyze(metrics: ['flush' => $flush + ['timeMs' => 500.0]]));
        }
        self::assertCount(1, $this->analyze(metrics: ['flush' => ['count' => 2, 'errors' => 0, 'timeMs' => 0.0]], options: ['flush_count_threshold' => 2]));
        self::assertSame([], $this->analyze(metrics: ['flush' => ['count' => 5, 'errors' => 0, 'timeMs' => 0.0]], options: ['enabled' => false]));
    }

    public function testFlushRankingUsesDurationAmongWarnings(): void
    {
        $metrics = ['flush' => ['count' => 5, 'errors' => 0, 'timeMs' => 100.0],
            'unitOfWork' => ['observedManagedMax' => 0, 'phases' => ['change_sets' => ['count' => 5, 'timeMs' => 20.0]]]];
        self::assertSame(['repeated_flush', 'dirty_checking'], array_column($this->analyze(metrics: $metrics), 'id'));
    }

    public function testSpecificFallbackReplacesDuplicateAggregateHint(): void
    {
        $events = array_fill(0, 5, $this->fallback());
        $findings = $this->analyze($events, ['batch' => ['fallbackCounts' => ['size_one' => 5]]]);
        self::assertCount(1, $findings);
        self::assertStringStartsWith('batch_size_one_', $findings[0]['id']);
        self::assertStringContainsString('Order::lines', $findings[0]['title']);
        self::assertStringContainsString('5 size_one fallback decisions', $findings[0]['evidence']);
        self::assertStringContainsString('configured batchSize 1 (one_to_many)', $findings[0]['evidence']);
        self::assertStringContainsString('intentional opt-out', $findings[0]['recommendation']);
    }

    public function testSmallGroupsAreNotCombinedIntoAnInventedAssociation(): void
    {
        $events = [...array_fill(0, 3, $this->fallback()), ...array_fill(0, 3, $this->fallback('Order::tags'))];
        $findings = $this->analyze($events, ['batch' => ['fallbackCounts' => ['size_one' => 6]]]);
        self::assertSame(['batch_size_one'], array_column($findings, 'id'));
        self::assertStringContainsString('No individual association or target reached', $findings[0]['evidence']);
    }

    public function testAggregateHintWorksWithoutRetainedEvents(): void
    {
        $findings = $this->analyze(metrics: ['batch' => ['fallbackCounts' => ['size_one' => 8]]], dropped: 100);
        self::assertSame(['batch_size_one'], array_column($findings, 'id'));
        self::assertStringContainsString('8 size_one', $findings[0]['evidence']);
    }

    public function testSingletonFindingNamesAssociationSizeAndMeasuredSampleTime(): void
    {
        $findings = $this->analyze(array_fill(0, 5, $this->batch()), dropped: 20);
        self::assertCount(1, $findings);
        self::assertSame('batch', $findings[0]['tab']);
        self::assertStringContainsString('Order::lines', $findings[0]['title']);
        self::assertStringContainsString('5 successful batches selected one', $findings[0]['evidence']);
        self::assertStringContainsString('batchSize 32 (one_to_many)', $findings[0]['evidence']);
        self::assertStringContainsString('10.00 ms', $findings[0]['evidence']);
        self::assertStringContainsString('20 operation events were omitted', $findings[0]['evidence']);
        self::assertStringContainsString('larger batchSize alone may not help', $findings[0]['recommendation']);
    }

    public function testSingletonsUseTargetsWhenSourceAssociationWasNotRecorded(): void
    {
        $findings = $this->analyze(array_fill(0, 5, $this->batch(null, 'Product', kind: BatchKind::MANY_TO_ONE)));
        self::assertCount(1, $findings);
        self::assertStringContainsString('target Product (source association not recorded)', $findings[0]['title']);
        self::assertStringContainsString('many_to_one', $findings[0]['evidence']);
    }

    public function testUnrelatedKindsTargetsAndSizesNeverMerge(): void
    {
        $events = [];
        foreach ([$this->batch(), $this->batch(entity: 'OtherLine'), $this->batch(size: 64), $this->batch(kind: BatchKind::MANY_TO_MANY)] as $event) {
            array_push($events, ...array_fill(0, 4, $event));
        }
        self::assertSame([], $this->analyze($events));
        $events[] = $this->batch(size: 64);
        $findings = $this->analyze($events);
        self::assertCount(1, $findings);
        self::assertStringContainsString('batchSize 64', $findings[0]['evidence']);
    }

    public function testFailedLargeEmptyAndOptOutBatchesDoNotCountAsSingletons(): void
    {
        $events = [];
        foreach ([$this->batch(failed: true), $this->batch(selected: 0), $this->batch(selected: 2), $this->batch(size: 1), $this->batch('', '')] as $event) {
            array_push($events, ...array_fill(0, 10, $event));
        }
        $events[] = new OperationEvent(OperationKind::BATCH_FALLBACK, 1, 0, false, BatchKind::ONE_TO_MANY, 'Line', 'Order::lines', 32, fallbackReason: BatchFallbackReason::UNSUPPORTED_JOIN);
        self::assertSame([], $this->analyze($events));
    }

    public function testCustomBatchThresholdsAndStableIds(): void
    {
        $events = [...array_fill(0, 2, $this->fallback()), ...array_fill(0, 2, $this->batch())];
        self::assertSame([], $this->analyze($events));
        $options = ['batch_fallback_threshold' => 2, 'batch_singleton_threshold' => 2];
        $first = $this->analyze($events, options: $options);
        self::assertCount(2, $first);
        self::assertSame($first, $this->analyze(array_reverse($events), options: $options));
        self::assertCount(1, $this->analyze($events, options: ['max_findings' => 1] + $options));
    }

    public function testConfigurationExposesValidatedThresholds(): void
    {
        $processor = new Processor();
        $defaults = $processor->processConfiguration(new Configuration(), []);
        self::assertSame(5, $defaults['profiler']['advisor']['flush_count_threshold']);
        self::assertSame(5, $defaults['profiler']['advisor']['batch_singleton_threshold']);
        $configured = $processor->processConfiguration(new Configuration(), [['profiler' => ['advisor' => ['flush_count_threshold' => 3, 'batch_singleton_threshold' => 8]]]]);
        self::assertSame(3, $configured['profiler']['advisor']['flush_count_threshold']);
        self::assertSame(8, $configured['profiler']['advisor']['batch_singleton_threshold']);
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $processor->processConfiguration(new Configuration(), [['profiler' => ['advisor' => ['flush_count_threshold' => 1]]]]);
    }

    public function testCollectorSavesSpecificEvidenceAndRemainsReadableAfterReset(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->method('isEnabled')->willReturn(true);
        $history = new OperationHistory(20, static fn (): Profiler => $profiler);
        $statistics = new Statistics(true, $history);
        $history->startRequest();
        for ($i = 0; $i < 5; ++$i) {
            $statistics->recordFlush($statistics->start(), false);
            $statistics->recordBatchFallback(BatchKind::ONE_TO_MANY, BatchFallbackReason::SIZE_ONE, 'Line', 'Order::lines', 1);
        }
        $collector = new DataCollector($statistics, new StatisticsCacheLogger(), $history, false, new SqlHistory(false, 0, 128));
        $collector->collect(Request::create('/'), new Response());
        $saved = unserialize(serialize($collector));
        $statistics->reset(); $history->reset();
        self::assertSame($collector->getFindings(), $saved->getFindings());
        self::assertCount(2, $saved->getFindings());
        self::assertStringContainsString('Order::lines', $saved->getFindings()[1]['title']);
        self::assertSame('repeated_flush', $saved->getFindings()[0]['id']);
        $loader = new \Twig\Loader\FilesystemLoader();
        $loader->addPath(dirname(__DIR__).'/templates', 'ModernORM');
        $twig = new \Twig\Environment($loader, ['strict_variables' => true]);
        $twig->addFunction(new \Twig\TwigFunction('path', static fn (string $route, array $parameters): string => '/profile?'.http_build_query($parameters)));
        $html = $twig->render('@ModernORM/Collector/_advisor.html.twig', ['collector' => $saved, 'active' => 'overview', 'token' => 'saved', 'tabs' => ['batch' => 'Batch Fetching', 'unitofwork' => 'UnitOfWork']]);
        self::assertStringContainsString('Order::lines', $html);
        self::assertStringContainsString('modernorm_tab=batch', $html);
        self::assertStringContainsString('modernorm_tab=unitofwork', $html);
    }

    public function testLimitedHistoryKeepsFlushAggregateAndDisclosesBatchCoverage(): void
    {
        $profiler = $this->createMock(Profiler::class);
        $profiler->method('isEnabled')->willReturn(true);
        $history = new OperationHistory(0, static fn (): Profiler => $profiler);
        $statistics = new Statistics(true, $history);
        $history->startRequest();
        for ($i = 0; $i < 5; ++$i) {
            $statistics->recordFlush($statistics->start(), false);
            $statistics->recordBatchFallback(BatchKind::ONE_TO_MANY, BatchFallbackReason::SIZE_ONE, 'Line', 'Order::lines', 1);
        }
        $collector = new DataCollector($statistics, new StatisticsCacheLogger(), $history, false, new SqlHistory(false, 0, 128));
        $collector->collect(Request::create('/'), new Response());
        self::assertSame(10, $collector->getDropped());
        self::assertSame(['repeated_flush', 'batch_size_one'], array_column($collector->getFindings(), 'id'));
        $loader = new \Twig\Loader\FilesystemLoader();
        $loader->addPath(dirname(__DIR__).'/templates', 'ModernORM');
        $twig = new \Twig\Environment($loader, ['strict_variables' => true]);
        $twig->addFunction(new \Twig\TwigFunction('path', static fn (string $route, array $parameters): string => '/profile'));
        $html = $twig->render('@ModernORM/Collector/_advisor.html.twig', ['collector' => $collector, 'active' => 'overview', 'token' => 'saved', 'tabs' => ['batch' => 'Batch Fetching', 'unitofwork' => 'UnitOfWork']]);
        self::assertStringContainsString('10 operation events were omitted', $html);
        self::assertStringContainsString('aggregate counters remain complete', $html);
    }
}
