<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\Connection;
use ModernORM\Bundle\ReverseEngineering\ImportCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportSourceConfigurationTest extends TestCase
{
    public function testMissingSourceVariableFailsWithoutUsingApplicationConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('getDatabasePlatform');
        $tester = new CommandTester(new ImportCommand($connection, sys_get_temp_dir()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty or missing');
        $tester->execute(['--source-url-env' => 'MODERNORM_NONEXISTENT_SOURCE_'.bin2hex(random_bytes(4)), '--dry-run' => true]);
    }

    public function testUnsupportedDriverDoesNotExposeUrl(): void
    {
        $_SERVER['MODERNORM_INVALID_TEST_SOURCE'] = 'sqlite://user:secret-password@localhost/database';
        try {
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::never())->method('getDatabasePlatform');
            $tester = new CommandTester(new ImportCommand($connection, sys_get_temp_dir()));
            try {
                $tester->execute(['--source-url-env' => 'MODERNORM_INVALID_TEST_SOURCE', '--dry-run' => true]);
                self::fail('Unsupported source driver must fail.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringNotContainsString('secret-password', $error->getMessage());
                self::assertStringContainsString('MySQL or PostgreSQL', $error->getMessage());
            }
        } finally {
            unset($_SERVER['MODERNORM_INVALID_TEST_SOURCE']);
        }
    }
}
