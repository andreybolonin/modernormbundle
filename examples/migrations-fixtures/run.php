<?php

declare(strict_types=1);

namespace ModernORMExample;

use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use ModernORM\Bundle\ConnectionFactory;
use ModernORM\Bundle\Fixtures\Fixture;
use ModernORM\Bundle\Fixtures\Runner;
use ModernORM\Bundle\Migrations\Command;
use ModernORM\Bundle\Migrations\DependencyFactoryFactory;
use ModernORM\Bundle\Schema\CacheInvalidator;
use ModernORM\Bundle\Schema\SchemaScope;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 2).'/vendor/autoload.php';

#[ORM\Entity]
#[ORM\Table(name: 'country')]
#[ORM\Immutable]
#[ORM\Cacheable(ttl: 3600)]
class Country
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
}

#[ORM\Entity]
#[ORM\Table(name: 'city')]
class City
{
    #[ORM\Id] #[ORM\GeneratedValue(strategy: 'IDENTITY')] #[ORM\Column(type: 'integer')] public ?int $id = null;
    #[ORM\Column] public string $name;
    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'country_id', referencedColumnName: 'id', nullable: false)]
    public Country $country;
}

class DemoDriver extends AttributeDriver
{
    public function __construct(private readonly string $schema)
    {
        parent::__construct(new class implements \Doctrine\Persistence\Mapping\Driver\ClassLocator {
            public function getClassNames(): array { return [Country::class, City::class]; }
        });
    }
    public function loadMetadataForClass(string $className, \Doctrine\Persistence\Mapping\ClassMetadata $metadata): void
    {
        parent::loadMetadataForClass($className, $metadata);
        $metadata->setPrimaryTable(['name' => $metadata->getTableName(), 'schema' => $this->schema]);
    }
}

class CountryFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $country = new Country();
        $country->id = 1;
        $country->name = 'France';
        $manager->persist($country);
        $this->addReference('country.france', $country);
    }
}

class CityFixture extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array { return [CountryFixture::class]; }
    public function load(ObjectManager $manager): void
    {
        $city = new City();
        $city->name = 'Paris';
        $city->country = $this->getReference('country.france', Country::class);
        $manager->persist($city);
    }
}

$url = getenv('MODERNORM_TEST_URL') ?: throw new \RuntimeException('Set MODERNORM_TEST_URL to a local PostgreSQL test database.');
$suffix = bin2hex(random_bytes(5));
$schema = 'modernorm_demo_'.$suffix;
$directory = sys_get_temp_dir().'/'.$schema;
mkdir($directory);
$connection = ConnectionFactory::create($url, '17', new Statistics());
$connection->executeStatement('CREATE SCHEMA '.$schema);

try {
    $configuration = new Configuration();
    $configuration->setMetadataDriverImpl(new DemoDriver($schema));
    $configuration->setSecondLevelCache(new ArrayAdapter(), $suffix);
    $manager = new EntityManager($connection, $configuration);
    $scope = new SchemaScope($manager, '~^'.$schema.'\.~', $schema.'.migration_versions');
    $cache = new CacheInvalidator($manager, $scope);
    $paths = ['ModernORMDemoMigrations'.$suffix => $directory];
    $output = new ConsoleOutput();
    foreach (['sync-metadata-storage', 'diff', 'migrate'] as $name) {
        $factory = DependencyFactoryFactory::create($connection, $scope, $paths, $schema.'.migration_versions', true);
        $inner = match ($name) {
            'sync-metadata-storage' => new \Doctrine\Migrations\Tools\Console\Command\SyncMetadataCommand($factory),
            'diff' => new \Doctrine\Migrations\Tools\Console\Command\DiffCommand($factory),
            'migrate' => new \Doctrine\Migrations\Tools\Console\Command\MigrateCommand($factory),
        };
        $command = new Command($inner, $scope, $manager, $cache, $name === 'migrate');
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        if ($command->run($input, $output) !== 0) { throw new \RuntimeException('Migration command failed: '.$name); }
    }
    $factory = DependencyFactoryFactory::create($connection, $scope, $paths, $schema.'.migration_versions', true);
    try {
        $factory->getDiffGenerator()->generate('ModernORMDemoMigrations'.$suffix.'\\NoChangesExpected', null);
        throw new \RuntimeException('A second diff should have been empty.');
    } catch (\Doctrine\Migrations\Generator\Exception\NoChangesDetected) {
        $output->writeln('<info>Repeated diff is empty.</info>');
    }
    (new Runner($manager, $scope, $cache, [new CityFixture(), new CountryFixture()]))->load();
    $city = $manager->getRepository(City::class)->findAll()[0];
    $output->writeln('Fixture loaded: '.$city->name.' / '.$city->country->name);
    $output->writeln('<info>Completed: empty schema -> migration -> empty diff -> atomic fixtures.</info>');
} finally {
    while ($connection->isTransactionActive()) { $connection->rollBack(); }
    $connection->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
    $connection->close();
    foreach (glob($directory.'/*') as $file) { unlink($file); }
    rmdir($directory);
}
