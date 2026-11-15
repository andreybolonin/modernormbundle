<?php

declare(strict_types=1);

namespace ModernORM\Bundle\CoreCompatibility;

use Doctrine\Migrations\Tools\Console\Command\DiffCommand;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\Persistence\Mapping\Driver\ClassLocator;
use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\Migrations\Command;
use ModernORM\Bundle\Migrations\DependencyFactoryFactory;
use ModernORM\Bundle\Schema\CacheInvalidator;
use ModernORM\Bundle\Schema\SchemaScope;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[ORM\Entity, ORM\Table(name: 'documents')]
#[ORM\FullTextIndex(name: 'document_search', fields: ['title' => 'A'], configuration: 'pg_catalog.english')]
final class SearchDocument
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'text')]
    public string $title;
}

final class FullTextMigrationTest extends TestCase
{
    private EntityManager $manager;
    private SchemaScope $scope;
    private string $schema;
    private string $directory;
    private string $namespace;

    protected function setUp(): void
    {
        $url = getenv('MODERNORM_TEST_URL');
        self::assertNotFalse($url, 'Set MODERNORM_TEST_URL for FTS migration integration.');
        $this->schema = 'bundle_fts_'.bin2hex(random_bytes(5));
        $this->namespace = 'FtsMigrations'.bin2hex(random_bytes(5));
        $this->directory = sys_get_temp_dir().'/'.$this->schema;
        mkdir($this->directory);
        $connection = ConnectionFactory::create($url, '17', new Statistics(true));
        $connection->executeStatement('CREATE SCHEMA '.$this->schema);
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver(new class () implements ClassLocator {
            public function getClassNames(): array
            {
                return [SearchDocument::class];
            }
        }));
        $this->manager = new EntityManager($connection, $configuration);
        $this->manager->getClassMetadata(SearchDocument::class)->table['schema'] = $this->schema;
        $this->scope = new SchemaScope($this->manager, '~^'.$this->schema.'\.~', $this->schema.'.migration_versions');
    }

    protected function tearDown(): void
    {
        if (!isset($this->manager)) {
            return;
        }
        $connection = $this->manager->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
        $connection->close();
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function command(string $class): CommandTester
    {
        $factory = DependencyFactoryFactory::create($this->manager->getConnection(), $this->scope, [$this->namespace => $this->directory], $this->schema.'.migration_versions', true);
        $factory->getMetadataStorage()->ensureInitialized();

        return new CommandTester(new Command(new $class($factory), $this->scope, $this->manager, new CacheInvalidator($this->manager, $this->scope), MigrateCommand::class === $class));
    }

    private function executeCommand(string $class, array $arguments = []): void
    {
        $command = $this->command($class);
        self::assertSame(0, $command->execute($arguments, ['interactive' => false]), $command->getDisplay());
    }

    public function testCreateChangeAndRollbackConvergeThroughRealCommands(): void
    {
        $db = $this->manager->getConnection();
        $filter = $db->getConfiguration()->getSchemaAssetsFilter();
        $metadata = $this->manager->getClassMetadata(SearchDocument::class);
        $indexes = new \ReflectionProperty($metadata, 'fullTextIndexes');
        $original = $indexes->getValue($metadata);
        self::assertTrue($this->scope->hasFullText());
        $this->scope->schema();
        self::assertSame($original, $indexes->getValue($metadata), 'Ownership checks retain FTS metadata.');
        $this->executeCommand(DiffCommand::class);
        $files = glob($this->directory.'/*.php');
        self::assertCount(1, $files);
        self::assertStringContainsString('using gin', strtolower(file_get_contents($files[0])));
        $this->executeCommand(MigrateCommand::class);
        $this->executeCommand(DiffCommand::class, ['--allow-empty-diff' => true]);
        self::assertCount(1, glob($this->directory.'/*.php'));
        $db->executeStatement('INSERT INTO '.$this->schema.'.documents (id, title) VALUES (1, ?)', ['searchable document']);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.documents WHERE search_vector IS NOT NULL'));
        $fixture = new class () extends \ModernORM\Bundle\Fixtures\Fixture {
            public function load(\Doctrine\Persistence\ObjectManager $manager): void
            {
            }
        };
        (new \ModernORM\Bundle\Fixtures\Runner($this->manager, $this->scope, new CacheInvalidator($this->manager, $this->scope), [$fixture]))->load();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.documents'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.migration_versions'));
        self::assertSame($original, $indexes->getValue($metadata));
        $first = $db->fetchOne('SELECT version FROM '.$this->schema.'.migration_versions');
        $indexes->setValue($metadata, [new ORM\FullTextIndex('document_search', ['title' => 'B'], 'pg_catalog.simple')]);
        usleep(1100000);
        $this->executeCommand(DiffCommand::class);
        $this->executeCommand(MigrateCommand::class);
        $this->executeCommand(DiffCommand::class, ['--allow-empty-diff' => true]);
        self::assertCount(2, glob($this->directory.'/*.php'));
        $this->executeCommand(MigrateCommand::class, ['version' => $first]);
        $indexes->setValue($metadata, $original);
        $this->executeCommand(DiffCommand::class, ['--allow-empty-diff' => true]);
        self::assertCount(2, glob($this->directory.'/*.php'));
        self::assertSame($filter, $db->getConfiguration()->getSchemaAssetsFilter());
        $this->executeCommand(MigrateCommand::class, ['version' => '0']);
        self::assertFalse($db->createSchemaManager()->tablesExist([$this->schema.'.documents']));
    }
}
