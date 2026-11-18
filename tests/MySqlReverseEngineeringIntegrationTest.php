<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use ModernORM\Bundle\ReverseEngineering\ImportCommand;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MySqlReverseEngineeringIntegrationTest extends TestCase
{
    public function testImportFromSeparateMySqlSource(): void
    {
        $url = getenv('MODERNORM_TEST_MYSQL_URL');
        if (!$url) {
            self::markTestSkipped('Set MODERNORM_TEST_MYSQL_URL for MySQL 8.4 integration.');
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
        $source = DriverManager::getConnection($params);
        $suffix = bin2hex(random_bytes(5));
        $schema = 'modernorm_import_'.$suffix;
        $directory = sys_get_temp_dir().'/'.$schema;
        $namespace = 'MySqlImport'.$suffix;
        $created = false;
        try {
            $source->executeStatement('CREATE DATABASE '.$schema);
            $created = true;
            $source->executeStatement('USE '.$schema);
            $testUrl = preg_replace('~/[^/]*(\?.*)?$~', '/'.$schema, $url);
            $_SERVER['MODERNORM_IMPORT_TEST_SOURCE'] = $testUrl;
            // No application DB connection is needed, even during real source introspection.
            $application = $this->createMock(\Doctrine\DBAL\Connection::class);
            $application->expects(self::never())->method('getDatabasePlatform');
            $tester = new CommandTester(new ImportCommand($application, sys_get_temp_dir()));
            $options = ['--source-url-env' => 'MODERNORM_IMPORT_TEST_SOURCE', '--namespace' => $namespace, '--output' => $directory];
            $source->executeStatement("CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, rating TINYINT NOT NULL, credit DECIMAL(12,2) NOT NULL DEFAULT '0.00', payload JSON NULL, token VARCHAR(36) DEFAULT (uuid()), status ENUM('new','done') NOT NULL DEFAULT 'new', tags SET('a','b') NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY users_name_key(name)) ENGINE=InnoDB");
            $source->executeStatement('CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, amount DECIMAL(12,2) NOT NULL, KEY orders_user_idx(user_id), CONSTRAINT orders_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB');
            $source->executeStatement('CREATE TABLE computed (id INT PRIMARY KEY, doubled INT GENERATED ALWAYS AS (id*2) STORED)');
            $source->executeStatement('CREATE TABLE composite (a INT, b INT, PRIMARY KEY (a,b))');
            $source->executeStatement('CREATE TABLE prefixes (id INT PRIMARY KEY, label VARCHAR(120), KEY label_prefix(label(10)))');
            $source->executeStatement('CREATE VIEW user_names AS SELECT name FROM users');
            self::assertSame(0, $tester->execute($options + ['--dry-run' => true]));
            self::assertDirectoryDoesNotExist($directory);
            $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
            self::assertStringContainsString('tinyint(1) mapped as boolean', $display);
            self::assertStringContainsString('unsigned range requires review', $display);
            self::assertStringContainsString('SET mapped as simple_array', $display);
            self::assertStringContainsString('ON UPDATE expression requires manual mapping', $display);
            self::assertStringContainsString('users.token: SQL expression default requires manual mapping and was omitted', $display);
            self::assertStringContainsString('generated columns require manual mapping', $display);
            self::assertStringContainsString('requires one scalar primary key', $display);
            self::assertStringContainsString('index label_prefix requires manual review', $display);
            self::assertStringNotContainsString('class UserNames', $display);
            $_SERVER['MODERNORM_IMPORT_TEST_SOURCE'] = $url;
            self::assertSame(0, $tester->execute($options + ['--schema' => $schema, '--dry-run' => true]));
            self::assertStringContainsString('class Users', $tester->getDisplay());
            $_SERVER['MODERNORM_IMPORT_TEST_SOURCE'] = $testUrl;
            self::assertSame(0, $tester->execute($options));
            $code = file_get_contents($directory.'/Users.php');
            self::assertStringContainsString('public int|string|null $id = null;', $code);
            self::assertStringContainsString('public int $rating;', $code);
            self::assertStringContainsString('public bool $active;', $code);
            self::assertStringContainsString("'values' => ['new', 'done']", $code);
            self::assertStringNotContainsString('__set_state', $code);
            require $directory.'/Users.php';
            require $directory.'/Orders.php';
            $driver = new AttributeDriver([$directory]);
            $metadata = [];
            foreach ($driver->getAllClassNames() as $class) {
                $item = new ClassMetadata($class);
                $item->initializeReflection(new RuntimeReflectionService());
                $driver->loadMetadataForClass($class, $item);
                $metadata[$item->getTableName()] = $item;
            }
            self::assertSame(['id'], $metadata['users']->identifier);
            self::assertSame(ClassMetadata::GENERATOR_TYPE_IDENTITY, $metadata['users']->generatorType);
            self::assertTrue($metadata['users']->fieldMappings['id']->options['unsigned']);
            self::assertSame('enum', $metadata['users']->fieldMappings['status']->type);
            self::assertSame($namespace.'\\Users', $metadata['orders']->associationMappings['user']->targetEntity);
            self::assertArrayHasKey('users_name_key', $metadata['users']->table['uniqueConstraints']);
            self::assertArrayHasKey('orders_user_idx', $metadata['orders']->table['indexes']);
            self::assertSame(0, $tester->execute($options + ['--table' => ['orders'], '--dry-run' => true]));
            self::assertStringNotContainsString('class Users', $tester->getDisplay());
            $before = file_get_contents($directory.'/Users.php');
            try {
                $tester->execute($options);
                self::fail('Must not overwrite existing files.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Refusing to overwrite', $error->getMessage());
            }
            self::assertSame($before, file_get_contents($directory.'/Users.php'));
            self::assertSame(0, (int) $source->fetchOne('SELECT COUNT(*) FROM users'));
        } finally {
            unset($_SERVER['MODERNORM_IMPORT_TEST_SOURCE']);
            if ($created) {
                $source->executeStatement('DROP DATABASE '.$schema);
            }
            $source->close();
            foreach (glob($directory.'/*.php') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
