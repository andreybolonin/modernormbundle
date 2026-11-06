<?php

declare(strict_types=1);

namespace ModernORM\Bundle\CoreCompatibility;

use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\Observability\OpenTelemetry\OperationMetrics;
use ModernORM\Statistics;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\TestCase;

final class DmlMetricsTest extends TestCase
{
    public function testActualDmlExportsOnceWithSuccessfulZeroRowsFailureAndRollback(): void
    {
        $exporter = new InMemoryExporter(temporality: Temporality::DELTA);
        $provider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();
        $statistics = new Statistics(true, new OperationMetrics($provider->getMeter('modernorm')));
        $db = ConnectionFactory::create(getenv('MODERNORM_TEST_URL'), '17', $statistics);
        try {
            $db->executeStatement('CREATE TEMP TABLE morm_dml_metrics (id INT PRIMARY KEY)');
            $db->executeStatement('INSERT INTO morm_dml_metrics VALUES (1),(2)');
            $db->executeStatement('UPDATE morm_dml_metrics SET id = id WHERE id = -1');
            $db->beginTransaction();
            $db->executeStatement('DELETE FROM morm_dml_metrics WHERE id = 2');
            $db->rollBack();
            try {
                $db->executeStatement('INSERT INTO morm_dml_metrics VALUES (1)');
                self::fail();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            }
            self::assertTrue($provider->forceFlush());
            $statements = $rows = [];
            foreach ($exporter->collect(true) as $metric) {
                if (!in_array($metric->name, ['modernorm.dml.statements', 'modernorm.dml.rows'], true)) {
                    continue;
                }
                self::assertInstanceOf(Sum::class, $metric->data);
                foreach ($metric->data->dataPoints as $point) {
                    $attributes = $point->attributes->toArray();
                    self::assertArrayNotHasKey('entity', $attributes);
                    self::assertArrayNotHasKey('sql', $attributes);
                    if ($metric->name === 'modernorm.dml.statements') {
                        $statements[$attributes['dml_operation'].':'.$attributes['outcome']] = $point->value;
                    } else {
                        $rows[$attributes['dml_operation']] = $point->value;
                    }
                }
            }
            self::assertSame(['insert:success' => 1, 'update:success' => 1, 'delete:success' => 1, 'insert:error' => 1], $statements);
            self::assertSame(['insert' => 2, 'update' => 0, 'delete' => 1], $rows);
            self::assertSame(1, $statistics->snapshot()->dml->delete->count);
        } finally {
            $db->close();
            $provider->shutdown();
        }
    }
}
