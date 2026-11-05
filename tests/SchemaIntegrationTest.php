<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Migrations\Tools\Console\Command\DiffCommand;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\EntityManagerFactory;
use ModernORM\Bundle\Fixtures\Fixture;
use ModernORM\Bundle\Fixtures\FixtureGroupInterface;
use ModernORM\Bundle\Fixtures\FixtureLoadException;
use ModernORM\Bundle\Fixtures\Runner;
use ModernORM\Bundle\Migrations\DependencyFactoryFactory;
use ModernORM\Bundle\Migrations\Command;
use ModernORM\Bundle\Schema\SchemaScope;
use ModernORM\Bundle\Schema\CacheInvalidator;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaIntegrationTest extends TestCase
{
    private EntityManager $manager;
    private SchemaScope $scope;
    private CacheInvalidator $cache;
    private string $schema;
    private string $directory;
    private string $namespace;
    private StatisticsCacheLogger $logger;
    private TestPool $pool;

    protected function setUp(): void
    {
        if (!getenv('MODERNORM_TEST_URL')) { self::markTestSkipped('Set MODERNORM_TEST_URL for PostgreSQL integration.'); }
        $suffix = bin2hex(random_bytes(5));
        $this->schema = 'modernorm_mf_'.$suffix;
        $this->namespace = 'ModernORMTestMigrations'.$suffix;
        $this->directory = sys_get_temp_dir().'/'.$this->schema;
        mkdir($this->directory);
        $connection = ConnectionFactory::create(getenv('MODERNORM_TEST_URL'), '17', new Statistics(true));
        $connection->executeStatement('CREATE SCHEMA '.$this->schema);
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new class($this->schema) extends AttributeDriver {
            public function __construct(private readonly string $schema) {
                parent::__construct(new class implements \Doctrine\Persistence\Mapping\Driver\ClassLocator {
                    public function getClassNames(): array { return [Lookup::class, Record::class]; }
                });
            }
            public function loadMetadataForClass(string $className, \Doctrine\Persistence\Mapping\ClassMetadata $metadata): void {
                parent::loadMetadataForClass($className, $metadata);
                $metadata->setPrimaryTable(['name' => $metadata->getTableName(), 'schema' => $this->schema]);
            }
        });
        $this->logger = new StatisticsCacheLogger();
        $configuration->setSecondLevelCache($this->pool = new TestPool(), $suffix, $this->logger);
        $this->manager = new EntityManager($connection, $configuration);
        foreach ([Lookup::class, Record::class] as $class) {
            $metadata = $this->manager->getClassMetadata($class);
            $metadata->setPrimaryTable(['name' => $metadata->getTableName(), 'schema' => $this->schema]);
        }
        $this->scope = new SchemaScope($this->manager, '~^'.$this->schema.'\.~', $this->schema.'.migration_versions');
        $this->cache = new CacheInvalidator($this->manager, $this->scope);
    }
    protected function tearDown(): void
    {
        if (!isset($this->manager)) { return; }
        $connection = $this->manager->getConnection();
        while ($connection->isTransactionActive()) { $connection->rollBack(); }
        $connection->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
        $connection->close();
        foreach (glob($this->directory.'/*') as $file) { unlink($file); }
        rmdir($this->directory);
    }
    private function factory(): \Doctrine\Migrations\DependencyFactory
    {
        return DependencyFactoryFactory::create($this->manager->getConnection(), $this->scope, [$this->namespace => $this->directory], $this->schema.'.migration_versions', true);
    }
    private function command(string $class, bool $writes = false): CommandTester
    {
        return new CommandTester(new Command(new $class($this->factory()), $this->scope, $this->manager, $this->cache, $writes));
    }
    private function migrateInitial(): void
    {
        $this->factory()->getMetadataStorage()->ensureInitialized();
        $diff = $this->command(DiffCommand::class);
        self::assertSame(0, $diff->execute([], ['interactive' => false]));
        $migrate = $this->command(MigrateCommand::class, true);
        self::assertSame(0, $migrate->execute([], ['interactive' => false]), $migrate->getDisplay());
    }
    public function testDiffMigrateAndEmptyDiffPreserveForeignTablesAndHistory(): void
    {
        $db = $this->manager->getConnection();
        $originalFilter = $db->getConfiguration()->getSchemaAssetsFilter();
        $foreignSchema = 'mf_namespace_'.bin2hex(random_bytes(4));
        $db->executeStatement('CREATE SCHEMA '.$foreignSchema);
        $foreign = 'mf_foreign_'.bin2hex(random_bytes(4));
        $db->executeStatement('CREATE TABLE public.'.$foreign.' (id INT PRIMARY KEY)');
        try {
            $this->migrateInitial();
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.migration_versions'));
            self::assertStringNotContainsString($foreignSchema, file_get_contents(glob($this->directory.'/*.php')[0]));
            $diff = $this->command(DiffCommand::class);
            self::assertSame(0, $diff->execute(['--allow-empty-diff' => true], ['interactive' => false]));
            self::assertCount(1, glob($this->directory.'/*.php'));
            self::assertTrue($db->createSchemaManager()->tablesExist([$foreign]));
            self::assertSame($originalFilter, $db->getConfiguration()->getSchemaAssetsFilter(), 'No filter leaks into the ORM connection.');
            // Alter mapping and run a second version through the same standard migration engine.
            $this->manager->getClassMetadata(Record::class)->mapField(['fieldName' => 'note', 'type' => 'string', 'nullable' => true]);
            usleep(1100000); // Migration class names have second precision.
            $diff = $this->command(DiffCommand::class);
            self::assertSame(0, $diff->execute([], ['interactive' => false]));
            $migrate = $this->command(MigrateCommand::class, true);
            self::assertSame(0, $migrate->execute([], ['interactive' => false]));
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.migration_versions'));
            $diff = $this->command(DiffCommand::class);
            self::assertSame(0, $diff->execute(['--allow-empty-diff' => true], ['interactive' => false]));
            self::assertCount(2, glob($this->directory.'/*.php'));
        } finally { $db->executeStatement('DROP TABLE public.'.$foreign); $db->executeStatement('DROP SCHEMA '.$foreignSchema); }
    }
    public function testFixtureDependenciesReferencesGeneratedIdsAndL2Invalidation(): void
    {
        $this->migrateInitial();
        $lookup = new Lookup(); $lookup->id = 1; $lookup->name = 'old';
        $this->manager->persist($lookup); $this->manager->flush(); $this->manager->clear();
        $this->manager->find(Lookup::class, 1); $this->manager->clear(); $this->manager->find(Lookup::class, 1);
        self::assertGreaterThan(0, $this->logger->getHitCount());
        $runner = new Runner($this->manager, $this->scope, $this->cache, [new RecordFixture(), new FollowupFixture(), new LookupFixture('seed')]);
        $runner->load(['demo']); // Dependencies outside the group are included using registered services.
        self::assertSame('seed', $this->manager->find(Lookup::class, 1)->name);
        $record = $this->manager->getRepository(Record::class)->findAll()[0];
        self::assertSame('referenced', $record->name);
        self::assertGreaterThan(0, $record->id);
        self::assertSame(1, $record->lookup->id);
        self::assertGreaterThan(0, $this->logger->getEvictionCount());
        self::assertSame(1, (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.migration_versions'));
        // Append keeps existing rows and uses generated identifiers after clear.
        $append = new Runner($this->manager, $this->scope, $this->cache, [new AppendFixture()]);
        $append->load([], true);
        self::assertSame(2, (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.mf_record'));
    }
    public function testFixtureFailureRollsBackPurgeAndAllEarlierFixtures(): void
    {
        $this->migrateInitial();
        $lookup = new Lookup(); $lookup->id = 1; $lookup->name = 'original';
        $this->manager->persist($lookup); $this->manager->flush();
        $runner = new Runner($this->manager, $this->scope, $this->cache, [new LookupFixture('replacement'), new FailingFixture()]);
        try { $runner->load(); self::fail('Fixture load should fail.'); }
        catch (FixtureLoadException $error) { self::assertFalse($error->databaseCommitted); }
        self::assertSame('original', $this->manager->find(Lookup::class, 1)->name);
        self::assertFalse($this->manager->getConnection()->isTransactionActive());
        self::assertTrue($this->manager->isOpen());
    }
    public function testDryRunDoesNotApplySqlAndMetadataStorageStaysTransactional(): void
    {
        $factory = $this->factory();
        $factory->getMetadataStorage()->ensureInitialized();
        self::assertSame(0, $this->command(DiffCommand::class)->execute([], ['interactive' => false]));
        $dryRun = $this->command(MigrateCommand::class, true);
        self::assertSame(0, $dryRun->execute(['--dry-run' => true], ['interactive' => false]));
        self::assertFalse($this->manager->getConnection()->createSchemaManager()->tablesExist([$this->schema.'.mf_lookup']));
        self::assertSame(0, (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.migration_versions'));
        self::assertSame(0, $this->command(MigrateCommand::class, true)->execute([], ['interactive' => false]));
        self::assertSame(1, (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.migration_versions'));
    }
    public function testPurgeDoesNotCascadeIntoForeignReferencingTables(): void
    {
        $this->migrateInitial();
        $db = $this->manager->getConnection();
        $foreign = 'mf_external_'.bin2hex(random_bytes(4));
        $db->executeStatement('INSERT INTO '.$this->schema.'.mf_lookup (id, name) VALUES (1, ?)', ['original']);
        $db->executeStatement('CREATE TABLE public.'.$foreign.' (id INT PRIMARY KEY, lookup_id INT REFERENCES '.$this->schema.'.mf_lookup (id))');
        $db->executeStatement('INSERT INTO public.'.$foreign.' VALUES (1, 1)');
        try {
            $runner = new Runner($this->manager, $this->scope, $this->cache, [new LookupFixture('replacement')]);
            try { $runner->load(); self::fail('TRUNCATE must reject external FK dependencies.'); }
            catch (FixtureLoadException $error) { self::assertFalse($error->databaseCommitted); }
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM public.'.$foreign));
            self::assertSame('original', $db->fetchOne('SELECT name FROM '.$this->schema.'.mf_lookup WHERE id = 1'));
        } finally { $db->executeStatement('DROP TABLE public.'.$foreign); }
    }
    public function testMissingDependencyIsRejectedBeforeAnyPurge(): void
    {
        $runner = new Runner($this->manager, $this->scope, $this->cache, [new RecordFixture()]);
        $this->expectException(\LogicException::class);
        $runner->load();
    }
    public function testFixtureTransactionTamperingReportsAnUnknownOutcome(): void
    {
        $this->migrateInitial();
        $runner = new Runner($this->manager, $this->scope, $this->cache, [new BoundaryFixture()]);
        try { $runner->load([], true); self::fail('Fixture must not commit the runner transaction.'); }
        catch (FixtureLoadException $error) { self::assertNull($error->databaseCommitted); }
        self::assertFalse($this->manager->isOpen());
    }
    public function testPublicQualifiedHistoryNameIsAlwaysExcluded(): void
    {
        $scope = new SchemaScope($this->manager, '~^(?:public\\.)?modernorm_~', 'public.modernorm_migration_versions');
        self::assertFalse($scope->owns('modernorm_migration_versions'));
        self::assertFalse($scope->owns('public.modernorm_migration_versions'));
        self::assertTrue($scope->isMetadataTable('modernorm_migration_versions'));
    }
    public function testFailedMigrationRollsBackDataAndDoesNotRecordItsVersion(): void
    {
        $this->migrateInitial();
        $factory = $this->factory();
        $sql = 'INSERT INTO '.$this->schema.'.mf_lookup (id, name) VALUES (2, \'uncommitted\')';
        $factory->getMigrationGenerator()->generateMigration($this->namespace.'\\Version20990101000000', '$this->addSql('.var_export($sql, true).');'."\n".'$this->addSql(\'SELECT 1 / 0\');');
        try { $this->command(MigrateCommand::class, true)->execute([], ['interactive' => false]); self::fail('Migration must fail.'); }
        catch (\Doctrine\DBAL\Exception) {}
        self::assertSame(0, (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.mf_lookup'));
        self::assertSame(1, (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->schema.'.migration_versions'));
    }
    public function testCommittedFixtureCacheFailureMustNotBeRetriedAsRollback(): void
    {
        $this->migrateInitial();
        $runner = new Runner($this->manager, $this->scope, $this->cache, [new CacheFailureFixture($this->pool)]);
        try { $runner->load(); self::fail('Post-commit invalidation must fail.'); }
        catch (FixtureLoadException $error) {
            self::assertTrue($error->databaseCommitted);
            self::assertNotNull($error->cacheFailure);
        } finally { $this->pool->fail = false; }
        self::assertSame('committed', $this->manager->getConnection()->fetchOne('SELECT name FROM '.$this->schema.'.mf_lookup WHERE id = 1'));
        self::assertFalse($this->manager->getConnection()->isTransactionActive());
    }
    public function testOwnershipAndUnknownGroupFailBeforePurge(): void
    {
        $this->migrateInitial();
        $runner = new Runner($this->manager, $this->scope, $this->cache, [new LookupFixture('seed')]);
        try { $runner->load(['missing']); self::fail('Unknown group must fail.'); }
        catch (\InvalidArgumentException) {}
        $scope = new SchemaScope($this->manager, '~^unrelated_~', $this->schema.'.migration_versions');
        $this->expectException(\LogicException::class);
        $scope->schema();
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'mf_lookup')]
#[ORM\Cacheable(ttl: 60)]
class Lookup
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
}
#[ORM\Entity]
#[ORM\Table(name: 'mf_record')]
class Record
{
    #[ORM\Id] #[ORM\GeneratedValue(strategy: 'IDENTITY')] #[ORM\Column(type: 'integer')] public ?int $id = null;
    #[ORM\Column] public string $name;
    public ?string $note = null;
    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'lookup_id', referencedColumnName: 'id', nullable: false)]
    public Lookup $lookup;
}
class LookupFixture extends Fixture
{
    public function __construct(private readonly string $name) {}
    public function load(ObjectManager $manager): void
    {
        $lookup = new Lookup(); $lookup->id = 1; $lookup->name = $this->name;
        $manager->persist($lookup); $manager->flush(); $this->addReference('lookup', $lookup);
    }
}
class RecordFixture extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array { return [LookupFixture::class]; }
    public function load(ObjectManager $manager): void
    {
        $record = new Record(); $record->name = 'record'; $record->lookup = $this->getReference('lookup', Lookup::class);
        $manager->persist($record); $this->addReference('record', $record);
    }
}
class FollowupFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public static function getGroups(): array { return ['demo']; }
    public function getDependencies(): array { return [RecordFixture::class]; }
    public function load(ObjectManager $manager): void { $this->getReference('record', Record::class)->name = 'referenced'; }
}
class AppendFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $record = new Record(); $record->name = 'appended'; $record->lookup = $manager->getReference(Lookup::class, 1); $manager->persist($record);
    }
}
class FailingFixture extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array { return [LookupFixture::class]; }
    public function load(ObjectManager $manager): void { throw new \RuntimeException('deliberate fixture failure'); }
}

class BoundaryFixture extends Fixture
{
    public function load(ObjectManager $manager): void { $manager->getConnection()->commit(); }
}

class TestPool extends ArrayAdapter
{
    public bool $fail = false;
    public function save(\Psr\Cache\CacheItemInterface $item): bool
    {
        if ($this->fail) { throw new \RuntimeException('simulated cache outage'); }
        return parent::save($item);
    }
}
class CacheFailureFixture extends Fixture
{
    public function __construct(private readonly TestPool $pool) {}
    public function load(ObjectManager $manager): void
    {
        $lookup = new Lookup(); $lookup->id = 1; $lookup->name = 'committed'; $manager->persist($lookup);
        $this->pool->fail = true;
    }
}
