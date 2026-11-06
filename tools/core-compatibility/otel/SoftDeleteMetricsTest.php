<?php

declare(strict_types=1);

namespace ModernORM\Bundle\CoreCompatibility;

use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\Observability\OpenTelemetry\OperationMetrics;
use ModernORM\Bulk\BulkPolicy;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\TestCase;

#[ORM\Entity, ORM\SoftDelete, ORM\Table(name: 'morm_otel_soft_item')]
final class MetricSoftItem
{
    use \ModernORM\Behavior\SoftDeleteTrait;
    #[ORM\Id, ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column(unique: true)] public string $code;
}

final class SoftDeleteMetricsTest extends TestCase
{
    public function testRealStateChangesExportDistinctOperationsRowsAndErrorsWithoutDoubleCounting(): void
    {
        self::assertSame(realpath(getenv('MODERNORM_CORE_PATH').'/src/Bulk/BulkExecutor.php'), realpath((new \ReflectionClass(\ModernORM\Bulk\BulkExecutor::class))->getFileName()));
        $exporter = new InMemoryExporter(temporality: Temporality::DELTA);
        $provider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();
        $statistics = new Statistics(true, new OperationMetrics($provider->getMeter('modernorm')));
        $connection = ConnectionFactory::create(getenv('MODERNORM_TEST_URL'), '17', $statistics);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager($connection, $config);
        try {
            $connection->executeStatement('CREATE TEMP TABLE morm_otel_soft_item (id INT PRIMARY KEY, code TEXT NOT NULL UNIQUE, deleted_at TIMESTAMP DEFAULT NULL)');
            $connection->executeStatement("INSERT INTO morm_otel_soft_item VALUES (1, 'private-one'), (2, 'private-two')");
            $em->bulk()->softDelete(MetricSoftItem::class, 'id = :id', BulkPolicy::CLEAR, ['id' => 1], returning: ['id']);
            $em->bulk()->restore(MetricSoftItem::class, 'id = :id', BulkPolicy::BYPASS, ['id' => 1], returning: ['id']);
            $em->bulk()->restore(MetricSoftItem::class, 'id = :id', BulkPolicy::CLEAR, ['id' => 1]);
            try {
                $em->bulk()->softDelete(MetricSoftItem::class, 'TRUE', BulkPolicy::CLEAR, values: ['code' => 'private-conflict']);
                self::fail('Expected SQL failure');
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            }
            self::assertTrue($provider->forceFlush());
            $operations = $durations = $affected = [];
            foreach ($exporter->collect(true) as $metric) {
                $data = $metric->data;
                if (!$data instanceof \OpenTelemetry\SDK\Metrics\Data\Sum && !$data instanceof \OpenTelemetry\SDK\Metrics\Data\Histogram) {
                    self::fail('Expected counter or histogram metric data.');
                }
                foreach ($data->dataPoints as $point) {
                    $attributes = $point->attributes->toArray();
                    if (($attributes['operation'] ?? null) !== 'bulk') {
                        continue;
                    }
                    self::assertSame(['operation', 'outcome', 'bulk_operation', 'policy'], array_keys(array_diff_key($attributes, ['row_kind' => true])));
                    $key = $attributes['bulk_operation'].':'.$attributes['policy'].':'.$attributes['outcome'];
                    if ($metric->name === 'modernorm.operations') {
                        $operations[$key] = $point->value;
                    }
                    if ($metric->name === 'modernorm.operation.duration') {
                        $durations[$key] = $point->count;
                        self::assertGreaterThan(0, $point->sum);
                    }
                    if ($metric->name === 'modernorm.bulk.rows' && $attributes['row_kind'] === 'affected') {
                        $affected[$key] = $point->value;
                    }
                }
            }
            self::assertSame(['soft_delete:CLEAR:success' => 1, 'restore:BYPASS:success' => 1, 'restore:CLEAR:success' => 1, 'soft_delete:CLEAR:error' => 1], $operations);
            self::assertSame($operations, $durations);
            self::assertSame(['soft_delete:CLEAR:success' => 1, 'restore:BYPASS:success' => 1, 'restore:CLEAR:success' => 0], $affected);
            self::assertSame(4, $statistics->snapshot()->bulk->count);
            $statistics->reset();
            self::assertTrue($provider->forceFlush());
            foreach ($exporter->collect() as $metric) {
                if ($metric->name !== 'modernorm.operations') {
                    continue;
                }
                $data = $metric->data;
                if (!$data instanceof \OpenTelemetry\SDK\Metrics\Data\Sum && !$data instanceof \OpenTelemetry\SDK\Metrics\Data\Histogram) {
                    self::fail('Expected counter or histogram metric data.');
                }
                foreach ($data->dataPoints as $point) {
                    if (($point->attributes->toArray()['operation'] ?? null) === 'bulk') {
                        self::assertSame(0, $point->value, 'Reset/flush must not replay a completed bulk delta.');
                    }
                }
            }
        } finally {
            $connection->close();
            $provider->shutdown();
        }
    }
}
