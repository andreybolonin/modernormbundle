<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Persistence\Mapping\Driver\ClassLocator;
use ModernORM\Bundle\DataTransfer\TransferCommand;
use ModernORM\Configuration;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ManyToManyTransferIntegrationTest extends TestCase
{
    public function testJoinTablePlanningLoadingAndFullPairVerification(): void
    {
        $mysqlUrl = getenv('MODERNORM_TEST_MYSQL_URL');
        $pgUrl = getenv('MODERNORM_TEST_URL');
        if (!$mysqlUrl || !$pgUrl) {
            self::markTestSkipped('Set both test database URLs; PostgreSQL test role needs CREATEDB.');
        }
        $parser = new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']);
        $source = DriverManager::getConnection($parser->parse($mysqlUrl));
        $application = DriverManager::getConnection($parser->parse($pgUrl));
        $suffix = bin2hex(random_bytes(5));
        $sourceName = 'modernorm_source_'.$suffix;
        $targetName = 'modernorm_rehearsal_'.$suffix;
        $sourceCreated = $targetCreated = false;
        $target = null;
        try {
            $source->executeStatement('CREATE DATABASE '.$sourceName);
            $sourceCreated = true;
            $source->executeStatement('USE '.$sourceName);
            $application->executeStatement('CREATE DATABASE '.$targetName);
            $targetCreated = true;
            $targetParams = $parser->parse($pgUrl);
            $targetParams['dbname'] = $targetName;
            $target = DriverManager::getConnection($targetParams);
            $source->executeStatement('CREATE TABLE owners (id BIGINT UNSIGNED PRIMARY KEY)');
            $source->executeStatement('CREATE TABLE labels (id INT PRIMARY KEY)');
            $source->executeStatement('CREATE TABLE owner_label (owner_id BIGINT UNSIGNED NOT NULL, label_id INT NOT NULL, PRIMARY KEY(owner_id,label_id), FOREIGN KEY(owner_id) REFERENCES owners(id), FOREIGN KEY(label_id) REFERENCES labels(id))');
            $source->executeStatement('INSERT INTO owners VALUES (1),(2),(9223372036854775806),(9223372036854775807)');
            $source->executeStatement('INSERT INTO labels VALUES (1),(2)');
            $source->executeStatement('INSERT INTO owner_label VALUES (1,1),(1,2),(2,1),(9223372036854775806,1),(9223372036854775806,2),(9223372036854775807,1)');
            $target->executeStatement('CREATE TABLE owners (id BIGINT PRIMARY KEY)');
            $target->executeStatement('CREATE TABLE labels (id INT PRIMARY KEY)');
            $target->executeStatement('CREATE TABLE owner_label (owner_id BIGINT NOT NULL REFERENCES owners(id), label_id INT NOT NULL REFERENCES labels(id), PRIMARY KEY(owner_id,label_id))');
            $_SERVER['MODERNORM_REHEARSAL_SOURCE'] = preg_replace('~/[^/]*(\?.*)?$~', '/'.$sourceName, $mysqlUrl);
            $_SERVER['MODERNORM_REHEARSAL_TARGET'] = preg_replace('~/[^/]*(\?.*)?$~', '/'.$targetName, $pgUrl);
            $configuration = new Configuration();
            $configuration->setMetadataDriverImpl(new AttributeDriver(new class () implements ClassLocator {
                public function getClassNames(): array
                {
                    return [JoinTransferOwner::class, JoinTransferLabel::class];
                }
            }));
            $tester = new CommandTester(new TransferCommand($configuration, $application));
            $options = ['--source-url-env' => 'MODERNORM_REHEARSAL_SOURCE', '--target-url-env' => 'MODERNORM_REHEARSAL_TARGET', '--rehearsal' => true, '--batch-size' => '1', '--format' => 'json'];
            $dry = array_replace($options, ['--rehearsal' => false, '--dry-run' => true, '--validate-data' => true]);
            $target->executeStatement('ALTER TABLE owner_label DROP CONSTRAINT owner_label_label_id_fkey');
            self::assertSame(1, $tester->execute($dry));
            self::assertStringContainsString('requires a matching target foreign key', $tester->getDisplay());
            $target->executeStatement('ALTER TABLE owner_label ADD FOREIGN KEY(label_id) REFERENCES labels(id)');
            $target->executeStatement('ALTER TABLE owner_label DROP CONSTRAINT owner_label_pkey');
            self::assertSame(1, $tester->execute($dry));
            self::assertStringContainsString('requires matching primary keys', $tester->getDisplay());
            $target->executeStatement('ALTER TABLE owner_label ADD PRIMARY KEY(owner_id,label_id)');
            self::assertSame(0, $tester->execute($options));
            $result = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('committed', $result['rehearsal']['status']);
            self::assertSame('join_table', $result['tables']['owner_label']['kind']);
            self::assertNull($result['tables']['owner_label']['entity']);
            self::assertSame(['owner_id', 'label_id'], $result['tables']['owner_label']['primary_key']);
            self::assertSame('owner_label', $result['order'][2]);
            self::assertTrue($result['rehearsal']['tables']['owner_label']['keys_verified']);
            self::assertSame(6, $result['rehearsal']['tables']['owner_label']['source_rows']);
            self::assertSame($source->fetchAllNumeric('SELECT CAST(owner_id AS CHAR), CAST(label_id AS CHAR) FROM owner_label ORDER BY owner_id,label_id'), $target->fetchAllNumeric('SELECT owner_id::text, label_id::text FROM owner_label ORDER BY owner_id,label_id'));
            $target->executeStatement('TRUNCATE owners, labels, owner_label');
            self::assertSame(0, $tester->execute($dry));
            $result = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(6, $result['validation']['tables']['owner_label']['rows_scanned']);
            // Changing the second component must be detected even with an unchanged first component.
            $target->executeStatement("CREATE FUNCTION alter_pair() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.owner_id=2 THEN NEW.label_id=2; END IF; RETURN NEW; END'");
            $target->executeStatement('CREATE TRIGGER alter_pair BEFORE INSERT ON owner_label FOR EACH ROW EXECUTE FUNCTION alter_pair()');
            self::assertSame(1, $tester->execute($options));
            $result = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('insert', $result['rehearsal']['stage']);
            self::assertStringContainsString('changed primary keys', implode(' ', $result['errors']));
            self::assertSame(0, (int) $target->fetchOne('SELECT count(*) FROM owner_label'));
            $target->executeStatement('DROP TRIGGER alter_pair ON owner_label');
            // A later table trigger changes an already inserted pair without changing row counts.
            $target->executeStatement("CREATE FUNCTION replace_pair() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.owner_id=9223372036854775807 THEN UPDATE owner_label SET label_id=2 WHERE owner_id=2; END IF; RETURN NEW; END'");
            $target->executeStatement('CREATE TRIGGER replace_pair AFTER INSERT ON owner_label FOR EACH ROW EXECUTE FUNCTION replace_pair()');
            self::assertSame(1, $tester->execute($options));
            $result = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('verification', $result['rehearsal']['stage']);
            self::assertStringContainsString('Source primary keys are missing', implode(' ', $result['errors']));
            $source->executeStatement('ALTER TABLE owner_label ADD extra INT');
            self::assertSame(1, $tester->execute($dry));
            self::assertStringContainsString('source column has no mapping', $tester->getDisplay());
        } finally {
            unset($_SERVER['MODERNORM_REHEARSAL_SOURCE'], $_SERVER['MODERNORM_REHEARSAL_TARGET']);
            $target?->close();
            if ($sourceCreated) {
                $source->executeStatement('DROP DATABASE '.$sourceName);
            }
            if ($targetCreated) {
                $application->executeStatement('DROP DATABASE '.$targetName);
            }
            $source->close();
            $application->close();
        }
    }
}

#[ORM\Entity, ORM\Table(name: 'owners')]
class JoinTransferOwner
{
    #[ORM\Id, ORM\Column(type: 'bigint')]
    public string $id;
    #[ORM\ManyToMany(targetEntity: JoinTransferLabel::class, inversedBy: 'owners')]
    #[ORM\JoinTable(name: 'owner_label', joinColumns: [new ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'label_id', referencedColumnName: 'id')])]
    public $labels;
}

#[ORM\Entity, ORM\Table(name: 'labels')]
class JoinTransferLabel
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\ManyToMany(targetEntity: JoinTransferOwner::class, mappedBy: 'labels')]
    public $owners;
}
