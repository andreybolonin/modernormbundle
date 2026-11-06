<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use ModernORM\Bundle\DependencyInjection\Configuration;
use ModernORM\Bundle\Profiler\PerformanceAdvisor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class PerformanceAdvisorTest extends TestCase
{
    private function metrics(array $overrides = []): array
    {
        return $overrides + ['unitOfWork' => null, 'batch' => [], 'bulk' => null, 'hydration' => null];
    }

    private function l2(): array
    {
        return ['enabled' => false, 'hits' => 0, 'misses' => 0];
    }

    private function query(string $sql, float $duration, bool $failed = false): array
    {
        return ['sql' => $sql, 'fingerprint' => hash('sha256', $sql), 'truncated' => false,
            'durationMilliseconds' => $duration, 'failed' => $failed];
    }

    private function hydration(array $overrides = []): array
    {
        return $overrides + ['count' => 2, 'timeMs' => 0.0, 'entitiesCreated' => 0, 'errors' => 0, 'incomplete' => 0];
    }

    public function testRepeatedGroupsRankByTotalDurationBeforeLimiting(): void
    {
        $queries = [...array_fill(0, 5, $this->query('SELECT fast', 1)), ...array_fill(0, 5, $this->query('SELECT costly', 9))];
        $findings = (new PerformanceAdvisor(['max_findings' => 1]))->analyze($this->metrics(), $queries, $this->l2());
        self::assertCount(1, $findings);
        self::assertSame('repeated_'.hash('sha256', 'SELECT costly'), $findings[0]['id']);
        self::assertSame(6, $findings[0]['query']);
        self::assertStringContainsString('45.00 ms total', $findings[0]['evidence']);
        self::assertArrayNotHasKey('timeMs', $findings[0]);
    }

    public function testSeverityPrecedesTimeAndSlowQueryRankingPrecedesIds(): void
    {
        $findings = (new PerformanceAdvisor())->analyze($this->metrics(['bulk' => ['errors' => 1]]), [
            $this->query('SELECT a', 20), $this->query('SELECT b', 90), $this->query('SELECT c', 40), $this->query('SELECT d', 30),
        ], $this->l2());
        self::assertSame(['bulk_errors', 'slow_2', 'slow_3', 'slow_4'], array_column($findings, 'id'));
    }

    public function testEqualCostsHaveStableOrder(): void
    {
        $advisor = new PerformanceAdvisor();
        $queries = [$this->query('SELECT b', 20), $this->query('SELECT a', 20)];
        self::assertSame(['slow_1', 'slow_2'], array_column($advisor->analyze($this->metrics(), $queries, $this->l2()), 'id'));
    }

    public function testDroppedEntriesQualifyBothSqlRulesWithoutExtrapolation(): void
    {
        $queries = array_fill(0, 5, $this->query('SELECT repeated', 12));
        $findings = (new PerformanceAdvisor())->analyze($this->metrics(), $queries, $this->l2(), 100);
        self::assertCount(4, $findings);
        foreach ($findings as $finding) {
            self::assertStringContainsString('100 SQL entries were omitted', $finding['evidence']);
            self::assertStringContainsString('retained entries only', $finding['evidence']);
        }
        self::assertStringContainsString('5 identical SQL executions', $findings[0]['evidence']);
    }

    public function testCompleteSampleDoesNotClaimMissingEntries(): void
    {
        $findings = (new PerformanceAdvisor())->analyze($this->metrics(), [$this->query('SELECT a', 12)], $this->l2());
        self::assertStringNotContainsString('omitted', $findings[0]['evidence']);
    }

    public function testFailedQueryDoesNotBecomeSlowQueryFinding(): void
    {
        self::assertSame([], (new PerformanceAdvisor())->analyze($this->metrics(), [$this->query('SELECT failed', 500, true)], $this->l2()));
    }

    public function testHydrationUsesAggregateThresholdsAndDescribesLimits(): void
    {
        $metrics = $this->metrics(['hydration' => $this->hydration(['timeMs' => 50.0, 'entitiesCreated' => 1000, 'errors' => 1, 'incomplete' => 1])]);
        $findings = (new PerformanceAdvisor())->analyze($metrics, [], $this->l2());
        self::assertSame(['hydration_time', 'hydration_entities'], array_column($findings, 'id'));
        self::assertSame(['hydration', 'hydration'], array_column($findings, 'tab'));
        self::assertSame([null, null], array_column($findings, 'query'));
        self::assertStringContainsString('1 errors, 1 incomplete', $findings[0]['evidence']);
        self::assertStringContainsString('do not add them', $findings[0]['evidence']);
        self::assertStringContainsString('not a count of distinct database entities', $findings[1]['evidence']);
    }

    public function testHydrationRulesRespectCustomThresholdsAndDisabledAdvisor(): void
    {
        $metrics = $this->metrics(['hydration' => $this->hydration(['timeMs' => 20.0, 'entitiesCreated' => 5])]);
        self::assertSame([], (new PerformanceAdvisor())->analyze($metrics, [], $this->l2()));
        $options = ['hydration_ms' => 20.0, 'hydrated_entities_threshold' => 5];
        self::assertCount(2, (new PerformanceAdvisor($options))->analyze($metrics, [], $this->l2()));
        self::assertSame([], (new PerformanceAdvisor(['enabled' => false] + $options))->analyze($metrics, [], $this->l2(), 20));
    }

    public function testUnavailableAndEmptyHydrationDoNotGenerateFindings(): void
    {
        foreach ([null, $this->hydration(['timeMs' => 49.99, 'entitiesCreated' => 999]), $this->hydration(['count' => 0])] as $hydration) {
            self::assertSame([], (new PerformanceAdvisor())->analyze($this->metrics(['hydration' => $hydration]), [], $this->l2()));
        }
        self::assertSame([], (new PerformanceAdvisor(['hydration_ms' => 0]))->analyze($this->metrics(['hydration' => $this->hydration(['count' => 0])]), [], $this->l2()));
    }

    public function testTimedChangeSetsRankAheadOfUntimedContextSize(): void
    {
        $work = ['observedManagedMax' => 1000, 'phases' => ['change_sets' => ['timeMs' => 30.0, 'count' => 2]]];
        $findings = (new PerformanceAdvisor())->analyze($this->metrics(['unitOfWork' => $work]), [], $this->l2());
        self::assertSame(['dirty_checking', 'large_context'], array_column($findings, 'id'));
    }

    public function testConfigurationExposesHydrationThresholds(): void
    {
        $processor = new Processor();
        $defaults = $processor->processConfiguration(new Configuration(), []);
        self::assertSame(50.0, $defaults['profiler']['advisor']['hydration_ms']);
        self::assertSame(1000, $defaults['profiler']['advisor']['hydrated_entities_threshold']);
        $configured = $processor->processConfiguration(new Configuration(), [['profiler' => ['advisor' => ['hydration_ms' => 12.5, 'hydrated_entities_threshold' => 50]]]]);
        self::assertSame(12.5, $configured['profiler']['advisor']['hydration_ms']);
        self::assertSame(50, $configured['profiler']['advisor']['hydrated_entities_threshold']);
    }

    public function testSqlCoverageNoticeSurvivesAnEmptyFindingsList(): void
    {
        $loader = new \Twig\Loader\FilesystemLoader();
        $loader->addPath(dirname(__DIR__).'/templates', 'ModernORM');
        $twig = new \Twig\Environment($loader, ['strict_variables' => true]);
        $twig->addFunction(new \Twig\TwigFunction('path', static fn (string $route, array $parameters): string => '/profile?'.http_build_query($parameters)));
        $context = ['collector' => ['findings' => [], 'advisorEnabled' => true, 'sqlEnabled' => true, 'queriesDropped' => 12],
            'active' => 'overview', 'token' => 'saved', 'tabs' => ['overview' => 'Overview', 'queries' => 'Queries']];
        foreach (['overview', 'queries'] as $tab) {
            $html = $twig->render('@ModernORM/Collector/_advisor.html.twig', ['active' => $tab] + $context);
            self::assertStringContainsString('12 SQL entries were omitted', $html);
            self::assertStringContainsString('no findings does not establish', $html);
            self::assertStringContainsString('No findings above', $html);
        }
        $context['collector']['queriesDropped'] = 0;
        self::assertStringNotContainsString('SQL entries were omitted', $twig->render('@ModernORM/Collector/_advisor.html.twig', $context));
        $context['collector']['queriesDropped'] = 12;
        $context['collector']['advisorEnabled'] = false;
        self::assertStringNotContainsString('SQL entries were omitted', $twig->render('@ModernORM/Collector/_advisor.html.twig', $context));
    }

    public function testHydrationFindingsRenderWithTheirSavedTabLink(): void
    {
        $loader = new \Twig\Loader\FilesystemLoader();
        $loader->addPath(dirname(__DIR__).'/templates', 'ModernORM');
        $twig = new \Twig\Environment($loader, ['strict_variables' => true]);
        $twig->addFunction(new \Twig\TwigFunction('path', static fn (string $route, array $parameters): string => '/profile?'.http_build_query($parameters)));
        $findings = (new PerformanceAdvisor())->analyze($this->metrics(['hydration' => $this->hydration(['timeMs' => 100])]), [], $this->l2());
        $html = $twig->render('@ModernORM/Collector/_advisor.html.twig', [
            'collector' => ['findings' => unserialize(serialize($findings)), 'advisorEnabled' => true, 'sqlEnabled' => false, 'queriesDropped' => 0],
            'active' => 'hydration', 'token' => 'saved', 'tabs' => ['hydration' => 'Hydration'],
        ]);
        self::assertStringContainsString('data-finding="hydration_time"', $html);
        self::assertStringContainsString('modernorm_tab=hydration', $html);
        self::assertStringContainsString('Inspect Hydration', $html);
    }

}
