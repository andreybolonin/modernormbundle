<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Example\Observability\Tests;

use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\EntityManagerFactory;
use ModernORM\Bundle\Observability\CacheLoggerDispatcher;
use ModernORM\Bundle\Observability\OpenTelemetry\CacheMetrics;
use ModernORM\Bulk\BulkPolicy;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Statistics;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'morm_otel_l2_test_item')]
#[ORM\Cacheable(writePolicy: \ModernORM\Cache\CacheWritePolicy::INVALIDATE)]
class CacheMetricItem
{
    #[ORM\Id, ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
}

#[ORM\Entity, ORM\Table(name: 'morm_otel_l2_test_owner')]
class CacheMetricOwner
{
    #[ORM\Id, ORM\Column(type: 'integer')] public int $id;
    #[ORM\ManyToOne(targetEntity: CacheMetricItem::class, batchSize: 8)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id')]
    public CacheMetricItem $item;
}

final class CacheMetricsIntegrationTest extends TestCase
{
    public function testRealFindBatchAndBulkEventsMatchLocalCountersWithStatisticsDisabled(): void
    {
        if (!getenv('MODERNORM_TEST_URL')) {
            self::markTestSkipped('Set MODERNORM_TEST_URL for L2 integration.');
        }
        $exporter = new InMemoryExporter(temporality: Temporality::DELTA);
        $provider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();
        $request = new StatisticsCacheLogger();
        $metrics = new CacheMetrics($provider->getMeter('modernorm'), [CacheMetricItem::class => 'item']);
        $dispatcher = new CacheLoggerDispatcher($request, [$metrics]);
        $statistics = new Statistics(false);
        $connection = ConnectionFactory::create(getenv('MODERNORM_TEST_URL'), '17', $statistics);
        $config = EntityManagerFactory::configuration([], 8, new ArrayAdapter(), new ArrayAdapter(), new ArrayAdapter(), 'sdk-l2-integration', $dispatcher);
        $em = new EntityManager($connection, $config);
        try {
            $connection->executeStatement('CREATE TEMP TABLE morm_otel_l2_test_item (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)');
            $connection->executeStatement('CREATE TEMP TABLE morm_otel_l2_test_owner (id INT PRIMARY KEY, item_id INT NOT NULL)');
            $connection->executeStatement("INSERT INTO morm_otel_l2_test_item VALUES (1, 'one'), (2, 'two'), (3, 'three')");
            $connection->executeStatement('INSERT INTO morm_otel_l2_test_owner VALUES (1, 1), (2, 2), (3, 3)');
            $assertExport = function () use ($provider, $exporter, $request, $statistics): void {
                self::assertTrue($provider->forceFlush());
                $actual = [];
                foreach ($exporter->collect(true) as $metric) {
                    self::assertSame('modernorm.l2.operations', $metric->name);
                    $data = $metric->data;
                    if (!$data instanceof \OpenTelemetry\SDK\Metrics\Data\Sum && !$data instanceof \OpenTelemetry\SDK\Metrics\Data\Histogram) {
                        self::fail('Expected counter or histogram metric data.');
                    }
                    foreach ($data->dataPoints as $point) {
                        self::assertSame('item', $point->attributes->toArray()['entity']);
                        $actual[$point->attributes->toArray()['operation']] = $point->value;
                    }
                }
                $expected = array_filter(['hit' => $request->getHitCount(), 'miss' => $request->getMissCount(), 'put' => $request->getPutCount(), 'invalidation' => $request->getEvictionCount()]);
                self::assertEquals($expected, $actual);
                self::assertSame(0, $statistics->snapshot()->sqlCount);
                $request->reset();
                $statistics->reset();
            };
            // Ordinary find and a warm-cache find after clearing the Identity Map.
            self::assertSame('one', $em->find(CacheMetricItem::class, 1)->name);
            $em->clear();
            self::assertSame('one', $em->find(CacheMetricItem::class, 1)->name);
            self::assertSame(1, $request->getHitCount());
            self::assertSame(1, $request->getMissCount());
            self::assertSame(1, $request->getPutCount());
            $assertExport();
            // Cached ManyToOne batch: one warm hit, two misses and two fills.
            $em->clear();
            $owners = $em->getRepository(CacheMetricOwner::class)->findBy([], ['id' => 'ASC']);
            self::assertSame('one', $owners[0]->item->name);
            self::assertSame('three', $owners[2]->item->name);
            self::assertSame(1, $request->getHitCount());
            self::assertSame(2, $request->getMissCount());
            self::assertSame(2, $request->getPutCount());
            $assertExport();
            // Cache bypass inside a caller-owned transaction is not a lookup.
            $em->clear();
            $connection->beginTransaction();
            self::assertSame('one', $em->find(CacheMetricItem::class, 1)->name);
            $connection->rollBack();
            $assertExport();
            $em->clear();
            // Both normal writes and bulk writes emit their actual invalidation calls.
            $item = $em->find(CacheMetricItem::class, 1);
            $item->name = 'changed';
            $em->flush();
            self::assertSame(2, $request->getEvictionCount());
            $assertExport();
            $em->bulk()->update(CacheMetricItem::class, ['name' => 'bulk'], 'id = :id', BulkPolicy::CLEAR, ['id' => 1]);
            self::assertSame(2, $request->getEvictionCount());
            $assertExport();
            $em->bulk()->delete(CacheMetricItem::class, 'id = :id', BulkPolicy::CLEAR, ['id' => 3]);
            self::assertSame(2, $request->getEvictionCount());
            self::assertNull($em->find(CacheMetricItem::class, 3));
            self::assertSame(1, $request->getMissCount());
            self::assertSame(0, $request->getPutCount());
            $assertExport();
            $em->getCache()->evict(CacheMetricItem::class, 1);
            $em->getCache()->evictAll(CacheMetricItem::class);
            self::assertSame(2, $request->getEvictionCount());
            $assertExport();
            self::assertSame(0, $dispatcher->failures());
        } finally {
            $connection->close();
            $provider->shutdown();
        }
    }
}
