<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\Schema\Column;
use ModernORM\Bundle\DataTransfer\DependencyOrder;
use ModernORM\Bundle\DataTransfer\TransferCommand;
use ModernORM\Bundle\DataTransfer\TypeCompatibility;
use ModernORM\Bundle\DataTransfer\EnvironmentConnection;
use ModernORM\Bundle\DataTransfer\RehearsalGuard;
use Doctrine\DBAL\Connection;
use ModernORM\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DataTransferTest extends TestCase
{
    public function testParentsComeFirstAndCyclesBlockDependants(): void
    {
        $sorter = new DependencyOrder();
        self::assertSame(['people', 'purchases'], $sorter->sort(['purchases' => ['people'], 'people' => []])['order']);
        $cycle = $sorter->sort(['a' => ['b'], 'b' => ['a'], 'child' => ['a'], 'safe' => []]);
        self::assertSame(['safe'], $cycle['order']);
        self::assertSame(['a', 'b', 'child'], $cycle['blocked']);
        self::assertSame(['self'], $sorter->sort(['self' => ['self']])['blocked']);
    }

    public function testStructuralCompatibilityDoesNotClaimValuesAreValid(): void
    {
        $types = new TypeCompatibility();
        $range = $types->compare(new Column('id', 'bigint', ['unsigned' => true]), new Column('id', 'integer'));
        self::assertNull($range['error']);
        self::assertStringContainsString('range', $range['warning']);
        $invalid = $types->compare(new Column('payload', 'json'), new Column('payload', 'integer'));
        self::assertStringContainsString('Unsupported conversion', $invalid['error']);
        $nullable = $types->compare(new Column('name', 'string', ['notnull' => false]), new Column('name', 'string'));
        self::assertStringContainsString('NULL values', $nullable['warning']);
        self::assertNull($types->compare(new Column('id', 'guid'), new Column('id', 'guid'))['error']);
    }

    public function testExecutionWithoutDryRunFailsBeforeOpeningConnections(): void
    {
        $tester = new CommandTester(new TransferCommand(new Configuration()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Pass --dry-run or --rehearsal');
        $tester->execute([]);
    }

    public function testInvalidBatchSizeFailsBeforeOpeningConnections(): void
    {
        $tester = new CommandTester(new TransferCommand(new Configuration()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive batch size');
        $tester->execute(['--dry-run' => true, '--source-url-env' => 'SOURCE', '--target-url-env' => 'TARGET', '--batch-size' => '0']);
    }

    public function testWrongDriverDoesNotExposeUrlCredentials(): void
    {
        $_SERVER['MODERNORM_TRANSFER_BAD_TEST_URL'] = 'mysql://user:secret@localhost/database';
        try {
            try {
                EnvironmentConnection::open('MODERNORM_TRANSFER_BAD_TEST_URL', 'pdo_pgsql');
                self::fail('Wrong target driver must fail before connecting.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('must use pdo_pgsql', $error->getMessage());
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        } finally {
            unset($_SERVER['MODERNORM_TRANSFER_BAD_TEST_URL']);
        }
    }

    public function testNegativeExampleLimitFailsBeforeOpeningConnections(): void
    {
        $tester = new CommandTester(new TransferCommand(new Configuration()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nonnegative max error examples');
        $tester->execute(['--dry-run' => true, '--validate-data' => true, '--source-url-env' => 'SOURCE', '--target-url-env' => 'TARGET', '--max-error-examples' => '-1']);
    }

    public function testRehearsalAndDryRunAreMutuallyExclusive(): void
    {
        $tester = new CommandTester(new TransferCommand(new Configuration()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exclusively');
        $tester->execute(['--dry-run' => true, '--rehearsal' => true]);
    }

    public function testRehearsalRequiresKnownApplicationDatabaseBeforeOpeningTarget(): void
    {
        $target = $this->createMock(Connection::class);
        $target->expects(self::never())->method('fetchOne');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('explicitly configured application database');
        (new RehearsalGuard())->checkDatabase($target, null);
    }
}
