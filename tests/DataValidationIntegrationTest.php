<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use ModernORM\Bundle\DataTransfer\DataValidator;
use PHPUnit\Framework\TestCase;

final class DataValidationIntegrationTest extends TestCase
{
    public function testPostgresInputValidationRejectsRangesJsonDatesAndLossyValues(): void
    {
        $mysqlUrl = getenv('MODERNORM_TEST_MYSQL_URL');
        $postgresUrl = getenv('MODERNORM_TEST_URL');
        if (!$mysqlUrl || !$postgresUrl) {
            self::markTestSkipped('Set both database test URLs.');
        }
        $parser = new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']);
        $source = DriverManager::getConnection($parser->parse($mysqlUrl));
        $target = DriverManager::getConnection($parser->parse($postgresUrl));
        $schema = 'modernorm_values_'.bin2hex(random_bytes(5));
        $mysqlCreated = $pgCreated = false;
        try {
            $source->executeStatement('CREATE DATABASE '.$schema);
            $mysqlCreated = true;
            $source->executeStatement('USE '.$schema);
            $source->executeStatement("SET SESSION sql_mode = ''");
            $target->executeStatement('CREATE SCHEMA '.$schema);
            $pgCreated = true;
            $source->executeStatement('CREATE TABLE samples (id BIGINT UNSIGNED PRIMARY KEY, flag TINYINT(1), amount DECIMAL(12,4), document JSON, occurred DATETIME(6), clock TIME(6), uuid VARCHAR(64), payload VARBINARY(100), name VARCHAR(20))');
            $target->executeStatement('CREATE TABLE '.$schema.'.samples (id BIGINT PRIMARY KEY, flag BOOLEAN, amount NUMERIC(6,2), document JSONB, occurred TIMESTAMP(3), clock TIME(3), uuid UUID, payload BYTEA, name VARCHAR(3))');
            $source->executeStatement('INSERT INTO samples VALUES (1,?,?,?,?,?,?,?,?)', [1, '12.3400', '{"n":1}', '2024-02-29 10:20:30.123000', '23:59:59.123000', '550e8400-e29b-41d4-a716-446655440000', "\xff\0", 'ЖЖЖ']);
            $source->executeStatement('INSERT INTO samples VALUES (2,?,?,?,?,?,?,?,?)', [2, '12.3456', json_encode(['n' => "\0"], JSON_THROW_ON_ERROR), '0000-00-00 00:00:00', '25:00:00', 'invalid', "\xff\0", 'long']);
            $source->executeStatement('INSERT INTO samples VALUES (3,NULL,?,NULL,?,?,NULL,NULL,NULL)', ['12345.0000', '2024-02-29 10:20:30.123400', '12:00:00.123400']);
            $source->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $source->beginTransaction();
            $target->beginTransaction();
            $target->executeStatement('SET TRANSACTION READ ONLY');
            $columns = array_map(static fn (string $name): array => ['name' => $name], ['id', 'flag', 'amount', 'document', 'occurred', 'clock', 'uuid', 'payload', 'name']);
            $plan = ['tables' => ['samples' => ['primary_key' => ['id'], 'target' => '"'.$schema.'"."samples"', 'columns' => $columns, 'batch_size' => 1]]];
            $report = (new DataValidator())->validate($source, $target, $plan, 100);
            self::assertSame('complete', $report['status']);
            self::assertSame(3, $report['rows_scanned']);
            self::assertSame(2, $report['invalid_rows']);
            self::assertSame(10, $report['error_count']);
            self::assertFalse($report['examples_truncated']);
            self::assertCount(10, $report['error_examples']);
            self::assertNotContains(['id' => '1'], array_column($report['error_examples'], 'primary_key'));
            self::assertContains('document', array_column($report['error_examples'], 'column'));
            self::assertContains('uuid', array_column($report['error_examples'], 'column'));
            self::assertSame(0, (int) $target->fetchOne('SELECT count(*) FROM '.$schema.'.samples'));
            self::assertSame(3, (int) $source->fetchOne('SELECT count(*) FROM samples'));
        } finally {
            if ($source->isTransactionActive()) {
                $source->rollBack();
            }
            if ($target->isTransactionActive()) {
                $target->rollBack();
            }
            if ($mysqlCreated) {
                $source->executeStatement('DROP DATABASE '.$schema);
            }
            if ($pgCreated) {
                $target->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
            }
            $source->close();
            $target->close();
        }
    }
}
