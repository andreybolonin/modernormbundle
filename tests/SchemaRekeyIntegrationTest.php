<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use ModernORM\Bundle\DataTransfer\TransferCommand;
use ModernORM\Bundle\SchemaRekey\RekeyPlan;
use ModernORM\Bundle\SchemaRekey\SourceFence;
use ModernORM\Bundle\SchemaRekey\RekeyPlanner;
use ModernORM\Bundle\SchemaRekey\RekeyRules;
use ModernORM\Bundle\SchemaRekey\StagingManager;
use ModernORM\Bundle\SchemaRekey\SurrogateIdAllocator;
use ModernORM\Bundle\SchemaRekey\ForeignKeyRemapper;
use ModernORM\Bundle\SchemaRekey\RekeyValidator;
use ModernORM\Bundle\DataTransfer\TransformationRules;
use ModernORM\Bundle\DataTransfer\TransformationJournal;
use ModernORM\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaRekeyIntegrationTest extends TestCase
{
    private Connection $source;
    private Connection $target;
    private Connection $application;
    private string $sourceName;
    private string $targetName;
    private array $files = [];
    private bool $sourceCreated = false;
    private bool $targetCreated = false;

    protected function setUp(): void
    {
        $mysqlUrl = getenv('MODERNORM_TEST_MYSQL_URL');
        $pgUrl = getenv('MODERNORM_TEST_URL');
        if (!$mysqlUrl || !$pgUrl) {
            self::markTestSkipped('Set both database URLs; MySQL global SELECT/FLUSH_TABLES and PostgreSQL CREATEDB/pg_control_system are required.');
        }
        $parser = new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']);
        $this->source = DriverManager::getConnection($parser->parse($mysqlUrl));
        $this->application = DriverManager::getConnection($parser->parse($pgUrl));
        $suffix = bin2hex(random_bytes(6));
        $this->sourceName = 'mr_rekey_source_'.$suffix;
        $this->targetName = 'mr_rekey_target_'.$suffix;
        $this->source->executeStatement('CREATE DATABASE '.$this->sourceName);
        $this->sourceCreated = true;
        $this->source->executeStatement('USE '.$this->sourceName);
        $this->application->executeStatement('CREATE DATABASE '.$this->targetName);
        $this->targetCreated = true;
        $params = $parser->parse($pgUrl);
        $params['dbname'] = $this->targetName;
        $this->target = DriverManager::getConnection($params);
        $_SERVER['REKEY_TEST_SOURCE'] = preg_replace('~/[^/]*(\?.*)?$~', '/'.$this->sourceName, $mysqlUrl);
        $_SERVER['REKEY_TEST_TARGET'] = preg_replace('~/[^/]*(\?.*)?$~', '/'.$this->targetName, $pgUrl);
        $this->source->executeStatement('CREATE TABLE balances (warehouse_id BIGINT UNSIGNED NOT NULL, product_id INT NOT NULL, name VARCHAR(100) NOT NULL, quantity DECIMAL(18,3), PRIMARY KEY(warehouse_id,product_id))');
        $this->source->executeStatement('CREATE TABLE reservations (id INT PRIMARY KEY, warehouse_id BIGINT UNSIGNED NOT NULL, product_id INT NOT NULL, FOREIGN KEY(warehouse_id,product_id) REFERENCES balances(warehouse_id,product_id))');
        $this->source->executeStatement('CREATE TABLE labels (id INT PRIMARY KEY)');
        $this->source->executeStatement('CREATE TABLE balance_label (warehouse_id BIGINT UNSIGNED NOT NULL, product_id INT NOT NULL, label_id INT NOT NULL, PRIMARY KEY(warehouse_id,product_id,label_id), FOREIGN KEY(warehouse_id,product_id) REFERENCES balances(warehouse_id,product_id), FOREIGN KEY(label_id) REFERENCES labels(id))');
        $this->source->executeStatement("INSERT INTO balances VALUES (1,2,' First ',NULL),(1,10,' Second ',2.500),(18446744073709551614,3,' Third ',3.125)");
        $this->source->executeStatement('INSERT INTO reservations VALUES (10,1,10),(20,18446744073709551614,3)');
        $this->source->executeStatement('INSERT INTO labels VALUES (1),(2)');
        $this->source->executeStatement('INSERT INTO balance_label VALUES (1,2,1),(1,2,2),(1,10,1),(18446744073709551614,3,2)');
        $this->target->executeStatement('CREATE TABLE balances (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, warehouse_id NUMERIC(20,0) NOT NULL, product_id INT NOT NULL, name VARCHAR(100) NOT NULL, quantity NUMERIC(18,3) NOT NULL, UNIQUE(warehouse_id,product_id))');
        $this->target->executeStatement('CREATE TABLE reservations (id INT PRIMARY KEY, warehouse_id NUMERIC(20,0) NOT NULL, product_id INT NOT NULL, balance_id BIGINT NOT NULL REFERENCES balances(id))');
        $this->target->executeStatement('CREATE TABLE labels (id INT PRIMARY KEY)');
        $this->target->executeStatement('CREATE TABLE balance_label (warehouse_id NUMERIC(20,0) NOT NULL, product_id INT NOT NULL, label_id INT NOT NULL REFERENCES labels(id), balance_id BIGINT NOT NULL REFERENCES balances(id), PRIMARY KEY(balance_id,label_id))');
    }

    protected function tearDown(): void
    {
        if (isset($this->target)) {
            $this->target->close();
        }
        if ($this->sourceCreated) {
            $this->source->executeStatement('DROP DATABASE '.$this->sourceName);
        }
        if ($this->targetCreated) {
            $this->application->executeStatement('DROP DATABASE '.$this->targetName);
        }
        if (isset($this->source)) {
            $this->source->close();
            $this->application->close();
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        unset($_SERVER['REKEY_TEST_SOURCE'], $_SERVER['REKEY_TEST_TARGET']);
    }

    public function testSavedPlanRulesFullTransferAndWrongRulesRejection(): void
    {
        $rules = $this->rules();
        $plan = $this->file('.yaml');
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($this->planning() + ['--rules' => $rules, '--plan-output' => $plan, '--validate-data' => true]), $tester->getDisplay());
        $saved = RekeyPlan::read($plan);
        self::assertTrue($saved['schema_rekey']);
        self::assertSame('surrogate', $saved['tables']['balances']['policy']);
        self::assertSame('join_table', $saved['tables']['balance_label']['kind']);
        self::assertSame(0, (int) $this->target->fetchOne('SELECT count(*) FROM balances'));
        try {
            $tester->execute(['--schema-rekey' => true, '--plan' => $plan, '--rehearsal' => true]);
            self::fail('Missing rules must fail before connecting.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Rules do not match', $error->getMessage());
        }
        $log = $this->file('.jsonl');
        $write = ['--schema-rekey' => true, '--plan' => $plan, '--rules' => $rules, '--rehearsal' => true, '--batch-size' => '1', '--format' => 'json', '--change-log' => $log];
        $write += ['--source-url-env' => 'REKEY_TEST_SOURCE', '--target-url-env' => 'REKEY_TEST_TARGET', '--target-schema' => 'public'];
        self::assertSame(0, $tester->execute($write), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['target_data_committed']);
        self::assertTrue($report['validation_complete']);
        self::assertSame(4, $report['transformations']['changed_values']);
        self::assertSame(['First', 'Second', 'Third'], $this->target->fetchFirstColumn('SELECT name FROM balances ORDER BY id'));
        self::assertSame([1, 2, 3], array_map('intval', $this->target->fetchFirstColumn('SELECT id FROM balances ORDER BY warehouse_id,product_id')));
        self::assertSame([2, 3], array_map('intval', $this->target->fetchFirstColumn('SELECT balance_id FROM reservations ORDER BY id')));
        self::assertSame([[1, 1], [1, 2], [2, 1], [3, 2]], array_map(static fn (array $row): array => array_map('intval', $row), $this->target->fetchAllNumeric('SELECT balance_id,label_id FROM balance_label ORDER BY balance_id,label_id')));
        self::assertSame(4, (int) $this->target->fetchOne("SELECT nextval('balances_id_seq')"));
        self::assertSame(' First ', $this->source->fetchOne('SELECT name FROM balances WHERE warehouse_id=1 AND product_id=2'));
        $events = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($log, FILE_IGNORE_NEW_LINES));
        self::assertSame('committed', $events[array_key_last($events)]['outcome']);
        self::assertCount(8, array_filter($events, static fn (array $e): bool => $e['event'] === 'change'));
        unset($write['--change-log']);
        self::assertSame(1, $tester->execute($write));
        self::assertFalse(json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['target_data_committed']);
    }

    public function testConstraintFailureRollsBackAndSchemaChangesInvalidatePlan(): void
    {
        $this->target->executeStatement('ALTER TABLE balances ADD CHECK(quantity > 0)');
        $rules = $this->rules();
        $plan = $this->file('.yaml');
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($this->planning() + ['--rules' => $rules, '--plan-output' => $plan]), $tester->getDisplay());
        $write = ['--schema-rekey' => true, '--plan' => $plan, '--rules' => $rules, '--execute' => true, '--format' => 'json'];
        self::assertSame(1, $tester->execute($write));
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('load', $report['stage']);
        self::assertSame('23514', $report['sqlstate']);
        self::assertFalse($report['target_data_committed']);
        self::assertSame(0, (int) $this->target->fetchOne('SELECT count(*) FROM balances'));
        $this->target->executeStatement('ALTER TABLE balances ALTER COLUMN name TYPE VARCHAR(101)');
        self::assertSame(1, $tester->execute($write));
        self::assertStringContainsString('Saved plan differs', $tester->getDisplay());
    }

    public function testProtectedBusinessKeysAndUnsupportedGraphBlockPlan(): void
    {
        $bad = $this->file('.json');
        file_put_contents($bad, json_encode(['version' => 1, 'rules' => [['id' => 'bad', 'table' => 'balances', 'column' => 'warehouse_id', 'operation' => 'replace', 'from' => '1', 'to' => '2']]], JSON_THROW_ON_ERROR));
        $tester = $this->tester();
        self::assertSame(1, $tester->execute($this->planning() + ['--rules' => $bad]));
        self::assertStringContainsString('PK and FK columns cannot be transformed', $tester->getDisplay());
        $this->source->executeStatement('ALTER TABLE balances ADD reservation_id INT NULL, ADD FOREIGN KEY(reservation_id) REFERENCES reservations(id)');
        $this->target->executeStatement('ALTER TABLE balances ADD reservation_id INT NULL REFERENCES reservations(id)');
        self::assertSame(1, $tester->execute($this->planning()));
        self::assertStringContainsString('Cyclic or unresolved', $tester->getDisplay());
    }

    public function testSuggestionsReuseWizardAndBindSelectedRulesToPlan(): void
    {
        $rules = $this->file('.json');
        $plan = $this->file('.yaml');
        $tester = $this->tester();
        $tester->setInputs(['1', '1', '"0.000"', 'yes']);
        $options = $this->planning();
        $options['--format'] = 'text';
        self::assertSame(0, $tester->execute($options + ['--suggest-rules' => true, '--rules-output' => $rules, '--plan-output' => $plan], ['interactive' => true]), $tester->getDisplay());
        self::assertFileExists($rules);
        self::assertFileExists($plan);
        self::assertCount(2, json_decode(file_get_contents($rules), true, flags: JSON_THROW_ON_ERROR)['rules']);
        self::assertSame(0, $tester->execute(['--schema-rekey' => true, '--plan' => $plan, '--rules' => $rules, '--execute' => true, '--format' => 'json']), $tester->getDisplay());
    }

    public function testSuggestionWizardCanUseAnOptionalSavedPlan(): void
    {
        $tester = $this->tester();
        $original = $this->file('.yaml');
        self::assertSame(0, $tester->execute($this->planning() + ['--plan-output' => $original]), $tester->getDisplay());
        $rules = $this->file('.json');
        $updated = $this->file('.yaml');
        $tester->setInputs(['1', '1', '"0.000"', 'yes']);
        self::assertSame(0, $tester->execute(['--schema-rekey' => true, '--plan' => $original, '--dry-run' => true, '--suggest-rules' => true, '--rules-output' => $rules, '--plan-output' => $updated], ['interactive' => true]), $tester->getDisplay());
        self::assertNotSame(RekeyPlan::read($original)['rules']['hash'], RekeyPlan::read($updated)['rules']['hash']);
        self::assertSame(0, (int) $this->target->fetchOne('SELECT count(*) FROM balances'));
    }

    public function testDirectExecutionBuildsPlanInMemoryWithoutPlanFiles(): void
    {
        $tester = $this->tester();
        $options = $this->planning();
        unset($options['--dry-run']);
        self::assertSame(0, $tester->execute($options + ['--execute' => true, '--rules' => $this->rules(), '--batch-size' => '1']), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['target_data_committed']);
        self::assertTrue($report['validation_complete']);
        self::assertNotEmpty($report['plan_hash']);
        self::assertArrayNotHasKey('plan_output', $report);
        self::assertSame(3, (int) $this->target->fetchOne('SELECT count(*) FROM balances'));
    }

    public function testDirectRehearsalCanOptionallySaveItsPlan(): void
    {
        $tester = $this->tester();
        $options = $this->planning();
        unset($options['--dry-run']);
        $plan = $this->file('.yaml');
        self::assertSame(0, $tester->execute($options + ['--rehearsal' => true, '--rules' => $this->rules(), '--plan-output' => $plan]), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['target_data_committed']);
        self::assertSame($report['plan_hash'], RekeyPlan::read($plan)['plan_hash']);
    }

    public function testEmptyTablesCommitAndKeepIdentityReadyAtStart(): void
    {
        $this->source->executeStatement('DELETE FROM balance_label');
        $this->source->executeStatement('DELETE FROM reservations');
        $this->source->executeStatement('DELETE FROM balances');
        $this->source->executeStatement('DELETE FROM labels');
        $plan = $this->file('.yaml');
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($this->planning() + ['--plan-output' => $plan]), $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--schema-rekey' => true, '--plan' => $plan, '--execute' => true, '--format' => 'json']), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['validation_complete']);
        self::assertTrue($report['target_data_committed']);
        self::assertSame(0, $report['validation']['tables']['balances']['source_rows']);
        self::assertFalse((bool) $this->target->fetchOne('SELECT is_called FROM balances_id_seq'));
        self::assertSame(1, (int) $this->target->fetchOne("SELECT nextval('balances_id_seq')"));
    }

    public function testSourceFenceBlocksOtherWritersAndDetectsLostSession(): void
    {
        $other = DriverManager::getConnection($this->source->getParams());
        $other->executeStatement('USE '.$this->sourceName);
        $fence = new SourceFence();
        $session = $fence->acquire($this->source);
        $this->source->beginTransaction();
        try {
            $fence->assertHeld($this->source, $session);
            $other->executeStatement('SET SESSION lock_wait_timeout=1');
            try {
                $other->executeStatement('INSERT INTO labels VALUES (99)');
                self::fail('A concurrent writer must not pass the source fence.');
            } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                self::assertSame(1205, $error->getPrevious()->getCode());
            }
            $this->source->rollBack();
            try {
                $fence->assertHeld($this->source, $session);
                self::fail('A lost source snapshot must be detected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('fence/snapshot was lost', $error->getMessage());
            }
        } finally {
            $fence->release($this->source);
            $other->executeStatement('INSERT INTO labels VALUES (99)');
            $other->close();
        }
    }

    public function testValidatorDetectsWrongExistingParentAndModifiedPayload(): void
    {
        $rules = TransformationRules::fromFile($this->rules());
        $this->source->beginTransaction();
        $this->target->beginTransaction();
        try {
            $plan = RekeyRules::bind((new RekeyPlanner())->plan($this->source, $this->target, 'public', ['source_url_env' => 'REKEY_TEST_SOURCE', 'target_url_env' => 'REKEY_TEST_TARGET']), $rules);
            self::assertSame([], $plan['blockers']);
            $stages = (new StagingManager())->createAndCopy($this->source, $this->target, $plan, 1, $rules, new TransformationJournal($rules, 'load', 0));
            (new SurrogateIdAllocator())->allocate($this->target, $plan, $stages);
            (new ForeignKeyRemapper())->remap($this->target, $plan, $stages);
            (new StagingManager())->load($this->target, $plan, $stages);
            (new RekeyValidator())->source($this->source, $this->target, $plan, $stages, 1, $rules);
            $this->target->executeStatement('UPDATE reservations SET balance_id=1 WHERE id=10');
            try {
                (new RekeyValidator())->final($this->target, $plan, $stages);
                self::fail('A wrong FK pointing to an existing ID must fail validation.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('differ from expected projection', $error->getMessage());
            }
            $this->target->executeStatement('UPDATE reservations SET balance_id=2 WHERE id=10');
            $this->target->executeStatement("UPDATE balances SET name='corrupt' WHERE id=1");
            try {
                (new RekeyValidator())->final($this->target, $plan, $stages);
                self::fail('Modified payload must fail even with identical keys and row counts.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('differ from expected projection', $error->getMessage());
            }
            $this->target->executeStatement('UPDATE '.$stages['balances']['table']." SET name='corrupt'");
            try {
                (new RekeyValidator())->source($this->source, $this->target, $plan, $stages, 1, $rules);
                self::fail('Modified typed staging must fail even when raw values remain intact.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('staging values differ from source', $error->getMessage());
            }
        } finally {
            $this->target->rollBack();
            $this->source->rollBack();
        }
    }

    private function rules(): string
    {
        $file = $this->file('.json');
        file_put_contents($file, json_encode(['version' => 1, 'rules' => [['id' => 'trim_name', 'table' => 'balances', 'column' => 'name', 'operation' => 'trim'], ['id' => 'default_quantity', 'table' => 'balances', 'column' => 'quantity', 'operation' => 'null_to', 'value' => '0.000']]], JSON_THROW_ON_ERROR));

        return $file;
    }

    private function file(string $extension): string
    {
        $path = sys_get_temp_dir().'/modernorm_rekey_'.bin2hex(random_bytes(8)).$extension;
        $this->files[] = $path;
        return $path;
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new TransferCommand(new Configuration(), $this->application));
    }

    private function planning(): array
    {
        return ['--schema-rekey' => true, '--source-url-env' => 'REKEY_TEST_SOURCE', '--target-url-env' => 'REKEY_TEST_TARGET', '--dry-run' => true, '--format' => 'json'];
    }
}
