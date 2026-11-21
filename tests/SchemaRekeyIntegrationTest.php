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
use ModernORM\Bundle\SchemaRekey\TargetProjections;
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
        $this->projectionSchema();
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

    public function testNullableCompositeSelfReferenceRemapsRootsForwardLinksAndRowCycles(): void
    {
        $this->selfReferenceSchema();
        foreach ([[1, 1], [1, 2], [1, 3], [1, 10], [1, 20], [1, 30], [1, 40], ['18446744073709551614', 1]] as [$tenant, $node]) {
            $this->source->executeStatement('INSERT INTO categories(tenant_id,node_id,parent_tenant_id,parent_code) VALUES(?,?,NULL,NULL)', [$tenant, $node]);
        }
        $this->source->executeStatement('UPDATE categories SET parent_tenant_id=1,parent_code=10 WHERE node_id=1');
        $this->source->executeStatement('UPDATE categories SET parent_code=999 WHERE node_id=2');
        $this->source->executeStatement('UPDATE categories SET parent_tenant_id=999 WHERE node_id=3');
        $this->source->executeStatement('UPDATE categories SET parent_tenant_id=1,parent_code=node_id WHERE node_id=20');
        $this->source->executeStatement('UPDATE categories SET parent_tenant_id=1,parent_code=40 WHERE node_id=30');
        $this->source->executeStatement('UPDATE categories SET parent_tenant_id=1,parent_code=30 WHERE node_id=40');
        $tester = $this->tester();
        $options = $this->planning();
        $plan = $this->file('.yaml');
        $rules = $this->rules();
        self::assertSame(0, $tester->execute($options + ['--rules' => $rules, '--plan-output' => $plan]), $tester->getDisplay());
        $saved = RekeyPlan::read($plan);
        $edge = array_values(array_filter($saved['foreign_keys'], static fn (array $e): bool => $e['child'] === 'categories'))[0];
        self::assertTrue($edge['self_reference']);
        self::assertTrue($edge['nullable']);
        self::assertSame('any_null_absent', $edge['null_policy']);
        self::assertSame('SIMPLE', $edge['source_match']);
        self::assertSame(0, $tester->execute(['--schema-rekey' => true, '--plan' => $plan, '--rules' => $rules, '--rehearsal' => true, '--batch-size' => '1', '--format' => 'json']), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['validation_complete']);
        self::assertTrue($report['target_data_committed']);
        $rows = $this->target->fetchAllNumeric('SELECT node_id,parent_id FROM categories WHERE tenant_id=1 ORDER BY node_id');
        $rows = array_map(static fn (array $row): array => array_map(static fn ($value): ?int => $value === null ? null : (int) $value, $row), $rows);
        self::assertSame([[1, 4], [2, null], [3, null], [10, null], [20, 5], [30, 7], [40, 6]], $rows);
        self::assertSame(4, (int) $this->target->fetchOne('SELECT parent_id FROM categories WHERE tenant_id=18446744073709551614'));
        self::assertSame('999', (string) $this->target->fetchOne('SELECT parent_code FROM categories WHERE node_id=2'));
    }

    public function testSelfReferenceWithSharedTenantPkAndNullableParentCode(): void
    {
        $this->selfReferenceSchema(sharedTenant: true);
        $this->source->executeStatement('INSERT INTO categories VALUES(1,10,NULL),(1,1,10)');
        $options = $this->planning();
        unset($options['--dry-run']);
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($options + ['--execute' => true, '--rules' => $this->rules(), '--batch-size' => '1']), $tester->getDisplay());
        self::assertSame(2, (int) $this->target->fetchOne('SELECT parent_id FROM categories WHERE node_id=1'));
        self::assertNull($this->target->fetchOne('SELECT parent_id FROM categories WHERE node_id=10'));
    }

    public function testRequiredSelfReferenceSupportsSelfParentAndForwardSourceOrder(): void
    {
        $this->selfReferenceSchema(nullable: false);
        $this->source->executeStatement('INSERT INTO categories VALUES(1,10,1,10),(1,1,1,10)');
        $options = $this->planning();
        unset($options['--dry-run']);
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($options + ['--execute' => true, '--rules' => $this->rules(), '--batch-size' => '1']), $tester->getDisplay());
        self::assertSame([2, 2], array_map('intval', $this->target->fetchFirstColumn('SELECT parent_id FROM categories ORDER BY id')));
    }

    public function testPreservedScalarSelfReferenceNeedsNoParentFirstInsertOrder(): void
    {
        $this->source->executeStatement('CREATE TABLE categories(id INT PRIMARY KEY,parent_id INT NULL,FOREIGN KEY(parent_id) REFERENCES categories(id))');
        $this->target->executeStatement('CREATE TABLE categories(id INT PRIMARY KEY,parent_id INT NULL REFERENCES categories(id) MATCH FULL)');
        $this->source->executeStatement('INSERT INTO categories VALUES(10,NULL),(1,10)');
        $options = $this->planning();
        unset($options['--dry-run']);
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($options + ['--execute' => true, '--rules' => $this->rules(), '--batch-size' => '1']), $tester->getDisplay());
        self::assertSame(10, (int) $this->target->fetchOne('SELECT parent_id FROM categories WHERE id=1'));
    }

    public function testMissingSelfParentFailsRemapAndRollsBackWithoutNullFallback(): void
    {
        $this->selfReferenceSchema();
        $this->source->executeStatement('SET SESSION foreign_key_checks=0');
        try {
            $this->source->executeStatement('INSERT INTO categories VALUES(1,1,1,999)');
        } finally {
            $this->source->executeStatement('SET SESSION foreign_key_checks=1');
        }
        $options = $this->planning();
        unset($options['--dry-run']);
        $tester = $this->tester();
        self::assertSame(1, $tester->execute($options + ['--execute' => true, '--rules' => $this->rules()]), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('remap', $report['stage']);
        self::assertFalse($report['target_data_committed']);
        self::assertStringContainsString('missing or ambiguous source parent mapping', implode(' ', $report['blockers']));
        self::assertSame(0, (int) $this->target->fetchOne('SELECT count(*) FROM categories'));
        self::assertSame(0, (int) $this->target->fetchOne('SELECT count(*) FROM balances'));
    }

    public function testNullableSelfReferenceRequiresNullableNewFk(): void
    {
        $this->selfReferenceSchema();
        $this->target->executeStatement('ALTER TABLE categories ALTER COLUMN parent_id SET NOT NULL');
        $tester = $this->tester();
        self::assertSame(1, $tester->execute($this->planning()), $tester->getDisplay());
        self::assertStringContainsString('nullability matching the source relationship', $tester->getDisplay());
    }

    public function testFinalValidationRejectsInventedMissingAndWrongSelfParents(): void
    {
        $this->selfReferenceSchema(sharedTenant: true);
        $this->source->executeStatement('INSERT INTO categories VALUES(1,10,NULL),(1,1,10)');
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
            self::assertTrue((new RekeyValidator())->final($this->target, $plan, $stages)['validation_complete']);
            foreach (['id=2' => '1', 'id=1' => 'NULL'] as $where => $invalidParent) {
                // Corrupt both expected staging and final values to exercise independent edge validation.
                $this->target->executeStatement('UPDATE '.$stages['categories']['table'].' SET parent_id='.$invalidParent.' WHERE '.$where);
                $this->target->executeStatement('UPDATE categories SET parent_id='.$invalidParent.' WHERE '.$where);
                try {
                    (new RekeyValidator())->final($this->target, $plan, $stages);
                    self::fail('Invalid self parent must fail even when staging and final parent IDs agree.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('final FK points to a wrong source parent', $error->getMessage());
                }
                $this->target->executeStatement('UPDATE '.$stages['categories']['table'].' SET parent_id=NULL WHERE id=2');
                $this->target->executeStatement('UPDATE categories SET parent_id=NULL WHERE id=2');
                $this->target->executeStatement('UPDATE '.$stages['categories']['table'].' SET parent_id=2 WHERE id=1');
                $this->target->executeStatement('UPDATE categories SET parent_id=2 WHERE id=1');
            }
            $this->target->executeStatement('UPDATE '.$stages['categories']['table'].' SET parent_id=1 WHERE id=1');
            $this->target->executeStatement('UPDATE categories SET parent_id=1 WHERE id=1');
            try {
                (new RekeyValidator())->final($this->target, $plan, $stages);
                self::fail('A wrong existing parent must fail independent edge verification.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('final FK points to a wrong source parent', $error->getMessage());
            }
        } finally {
            $this->target->rollBack();
            $this->source->rollBack();
        }
    }

    public function testNullableCompositeReferenceTransfersEveryNullCombinationWithRulesAndJournal(): void
    {
        $this->nullableReservationSchema();
        $this->source->executeStatement('INSERT INTO reservations VALUES (30,NULL,NULL),(40,1,NULL),(50,NULL,999),(60,999,NULL),(70,1,2)');
        $rules = $this->rules();
        $plan = $this->file('.yaml');
        $log = $this->file('.jsonl');
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($this->planning() + ['--rules' => $rules, '--plan-output' => $plan, '--validate-data' => true, '--batch-size' => '1']), $tester->getDisplay());
        $saved = RekeyPlan::read($plan);
        $edge = array_values(array_filter($saved['foreign_keys'], static fn (array $edge): bool => $edge['child'] === 'reservations'))[0];
        self::assertFalse($edge['self_reference']);
        self::assertTrue($edge['nullable']);
        self::assertSame('any_null_absent', $edge['null_policy']);
        self::assertSame('SIMPLE', $edge['source_match']);
        self::assertLessThan(array_search('reservations', $saved['load_order'], true), array_search('balances', $saved['load_order'], true));
        self::assertSame(0, $tester->execute(['--schema-rekey' => true, '--plan' => $plan, '--rules' => $rules, '--execute' => true, '--change-log' => $log, '--batch-size' => '1', '--format' => 'json']), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['target_data_committed']);
        self::assertTrue($report['validation_complete']);
        $parents = $this->target->fetchAllNumeric('SELECT id,balance_id FROM reservations ORDER BY id');
        $parents = array_map(static fn (array $row): array => array_map(static fn ($value): ?int => $value === null ? null : (int) $value, $row), $parents);
        self::assertSame([[10,2],[20,3],[30,null],[40,null],[50,null],[60,null],[70,1]], $parents);
        $expected = $this->source->fetchAllNumeric('SELECT CAST(warehouse_id AS CHAR),CAST(product_id AS CHAR) FROM reservations ORDER BY id');
        self::assertSame($expected, $this->target->fetchAllNumeric('SELECT warehouse_id::text,product_id::text FROM reservations ORDER BY id'));
        self::assertSame(4, $report['transformations']['changed_values']);
        $events = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($log, FILE_IGNORE_NEW_LINES));
        self::assertSame('committed', $events[array_key_last($events)]['outcome']);
        self::assertSame('First', $this->target->fetchOne('SELECT name FROM balances WHERE id=1'));
    }

    public function testCompositeReferenceWithOnlyOneNullableComponent(): void
    {
        $this->nullableReservationSchema(allNullable: false);
        $this->source->executeStatement('INSERT INTO reservations VALUES (30,999,NULL)');
        $tester = $this->tester();
        $options = $this->planning();
        unset($options['--dry-run']);
        self::assertSame(0, $tester->execute($options + ['--rules' => $this->rules(), '--rehearsal' => true]), $tester->getDisplay());
        self::assertNull($this->target->fetchOne('SELECT balance_id FROM reservations WHERE id=30'));
        self::assertSame('999', (string) $this->target->fetchOne('SELECT warehouse_id FROM reservations WHERE id=30'));
    }

    public function testNullableCompositeReferenceRequiresNullableNewFkAndProtectsOldKeys(): void
    {
        $this->nullableReservationSchema();
        $this->target->executeStatement('ALTER TABLE reservations ALTER COLUMN balance_id SET NOT NULL');
        $tester = $this->tester();
        self::assertSame(1, $tester->execute($this->planning()), $tester->getDisplay());
        self::assertStringContainsString('nullability matching the source relationship', $tester->getDisplay());
        $this->target->executeStatement('ALTER TABLE reservations ALTER COLUMN balance_id DROP NOT NULL');
        $badRules = $this->file('.json');
        file_put_contents($badRules, json_encode(['version' => 1, 'rules' => [['id' => 'unsafe_default_key', 'table' => 'reservations', 'column' => 'product_id', 'operation' => 'null_to', 'value' => '2']]], JSON_THROW_ON_ERROR));
        self::assertSame(1, $tester->execute($this->planning() + ['--rules' => $badRules]), $tester->getDisplay());
        self::assertStringContainsString('PK and FK columns cannot be transformed', $tester->getDisplay());
    }

    public function testFullyPopulatedNullableCompositeOrphanRollsBackWithoutNullFallback(): void
    {
        $this->nullableReservationSchema();
        $this->source->executeStatement('SET SESSION foreign_key_checks=0');
        try {
            $this->source->executeStatement('INSERT INTO reservations VALUES (30,1,999)');
        } finally {
            $this->source->executeStatement('SET SESSION foreign_key_checks=1');
        }
        $tester = $this->tester();
        $options = $this->planning();
        unset($options['--dry-run']);
        self::assertSame(1, $tester->execute($options + ['--rules' => $this->rules(), '--execute' => true]), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('remap', $report['stage']);
        self::assertFalse($report['target_data_committed']);
        self::assertStringContainsString('missing or ambiguous source parent mapping', implode(' ', $report['blockers']));
        self::assertSame(0, (int) $this->target->fetchOne('SELECT count(*) FROM reservations'));
        self::assertSame(0, (int) $this->target->fetchOne('SELECT count(*) FROM balances'));
    }

    public function testNullableCompositeFinalValidationRejectsIncorrectAbsenceAndWrongParent(): void
    {
        $this->nullableReservationSchema();
        $this->source->executeStatement('INSERT INTO reservations VALUES (30,NULL,NULL)');
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
            self::assertTrue((new RekeyValidator())->final($this->target, $plan, $stages)['validation_complete']);
            foreach ([[30, '1'], [10, 'NULL'], [10, '1']] as [$id, $wrongParent]) {
                $this->target->executeStatement('UPDATE '.$stages['reservations']['table'].' SET balance_id='.$wrongParent.' WHERE id='.$id);
                $this->target->executeStatement('UPDATE reservations SET balance_id='.$wrongParent.' WHERE id='.$id);
                try {
                    (new RekeyValidator())->final($this->target, $plan, $stages);
                    self::fail('A wrong nullable parent must fail even when staging/final agree.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('final FK points to a wrong source parent', $error->getMessage());
                }
                $this->target->executeStatement('UPDATE '.$stages['reservations']['table'].' SET balance_id=NULL WHERE id=30');
                $this->target->executeStatement('UPDATE reservations SET balance_id=NULL WHERE id=30');
                $this->target->executeStatement('UPDATE '.$stages['reservations']['table'].' SET balance_id=2 WHERE id=10');
                $this->target->executeStatement('UPDATE reservations SET balance_id=2 WHERE id=10');
            }
        } finally {
            $this->target->rollBack();
            $this->source->rollBack();
        }
    }

    public function testNullableCompositeReferenceStillBlocksRekeyThroughChildIdentity(): void
    {
        $this->nullableReservationSchema(allNullable: false);
        $this->source->executeStatement('ALTER TABLE reservations DROP PRIMARY KEY, ADD PRIMARY KEY(warehouse_id,id)');
        $this->target->executeStatement('ALTER TABLE reservations DROP CONSTRAINT reservations_pkey, ADD COLUMN rekey_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, ADD UNIQUE(warehouse_id,id)');
        $tester = $this->tester();
        self::assertSame(1, $tester->execute($this->planning()), $tester->getDisplay());
        self::assertStringContainsString('remapped FK participates in child entity identity', $tester->getDisplay());
    }

    public function testTargetOnlyNullAndConstantColumnsArePlannedLoadedAndVerified(): void
    {
        $this->projectionSchema();
        $rules = $this->rules();
        $plan = $this->file('.yaml');
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($this->planning() + ['--rules' => $rules, '--plan-output' => $plan]), $tester->getDisplay());
        $saved = RekeyPlan::read($plan);
        $projections = $saved['tables']['balances']['target_projections'];
        self::assertSame('null', $projections['memo']['operation']);
        self::assertNull($projections['memo']['value']);
        self::assertSame('constant', $projections['status']['operation']);
        self::assertSame('pending', $projections['status']['value']);
        self::assertSame('0', $projections['attempts']['value']);
        self::assertSame('0', $projections['enabled']['value']);
        self::assertSame('base64', $projections['binary_marker']['encoding']);
        self::assertSame('00ff', bin2hex(base64_decode($projections['binary_marker']['value'], true)));
        self::assertSame(0, $tester->execute(['--schema-rekey' => true, '--plan' => $plan, '--rules' => $rules, '--rehearsal' => true, '--batch-size' => '1', '--format' => 'json']), $tester->getDisplay());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['validation_complete']);
        self::assertTrue($report['target_data_committed']);
        $values = $this->target->fetchAssociative("SELECT memo,status,attempts::text,enabled,amount::text,negative_value,float_value::text,encode(binary_marker,'hex') AS binary_marker,settings::text,cutoff::text,fixed_time::text,empty_value,optional_value,quoted_value,escaped_value,literal_function,explicit_null FROM balances WHERE id=1");
        self::assertNull($values['memo']);
        self::assertSame('pending', $values['status']);
        self::assertSame('0', $values['attempts']);
        self::assertFalse((bool) $values['enabled']);
        self::assertSame('123456789012345.1250', $values['amount']);
        self::assertSame(-7, (int) $values['negative_value']);
        self::assertSame('1.25', $values['float_value']);
        self::assertSame('00ff', $values['binary_marker']);
        self::assertSame(['mode' => 'legacy'], json_decode($values['settings'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('2020-01-02', $values['cutoff']);
        self::assertSame('2020-01-02 03:04:05', $values['fixed_time']);
        self::assertSame('', $values['empty_value']);
        self::assertSame('fallback', $values['optional_value']);
        self::assertSame("it's ready", $values['quoted_value']);
        self::assertSame("line\nnext", $values['escaped_value']);
        self::assertSame('now()', $values['literal_function']);
        self::assertNull($values['explicit_null']);
        self::assertSame(3, (int) $this->target->fetchOne('SELECT count(*) FROM balances WHERE status=? AND memo IS NULL', ['pending']));
        self::assertSame(4, $report['transformations']['changed_values']);
    }

    public function testUnsupportedDefaultsNeverRunAndRequiredOrGeneratedExtrasBlock(): void
    {
        $this->target->executeStatement('CREATE SEQUENCE projection_probe');
        $tester = $this->tester();
        foreach (["BIGINT DEFAULT nextval('projection_probe')", 'TIMESTAMP DEFAULT now()', 'INTEGER DEFAULT (1+2)', 'UUID DEFAULT gen_random_uuid()'] as $definition) {
            $this->target->executeStatement('ALTER TABLE balances ADD unsupported_value '.$definition);
            self::assertSame(1, $tester->execute($this->planning()), $tester->getDisplay());
            self::assertStringContainsString('functions and expressions are blocked', $tester->getDisplay());
            self::assertFalse((bool) $this->target->fetchOne('SELECT is_called FROM projection_probe'));
            $this->target->executeStatement('ALTER TABLE balances DROP COLUMN unsupported_value');
        }
        $this->target->executeStatement('ALTER TABLE balances ADD required_value TEXT NOT NULL');
        self::assertSame(1, $tester->execute($this->planning()), $tester->getDisplay());
        self::assertStringContainsString('NULL is not allowed', $tester->getDisplay());
        $this->target->executeStatement('ALTER TABLE balances DROP COLUMN required_value, ADD generated_value BIGINT GENERATED ALWAYS AS (id*2) STORED');
        self::assertSame(1, $tester->execute($this->planning()), $tester->getDisplay());
        self::assertStringContainsString('unsupported generated target projection', $tester->getDisplay());
    }

    public function testProjectionConstraintsRollBackAndDefaultChangesInvalidateSavedPlan(): void
    {
        $this->target->executeStatement("ALTER TABLE balances ADD status TEXT NOT NULL DEFAULT 'pending' CHECK(status='ready')");
        $rules = $this->rules();
        $plan = $this->file('.yaml');
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($this->planning() + ['--rules' => $rules, '--plan-output' => $plan]), $tester->getDisplay());
        $write = ['--schema-rekey' => true, '--plan' => $plan, '--rules' => $rules, '--execute' => true, '--format' => 'json'];
        self::assertSame(1, $tester->execute($write));
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('load', $report['stage']);
        self::assertFalse($report['target_data_committed']);
        self::assertSame('23514', $report['sqlstate']);
        self::assertSame(0, (int) $this->target->fetchOne('SELECT count(*) FROM balances'));
        $this->target->executeStatement("ALTER TABLE balances ALTER COLUMN status SET DEFAULT 'ready'");
        self::assertSame(1, $tester->execute($write));
        self::assertStringContainsString('Saved plan differs', $tester->getDisplay());
    }

    public function testProjectionValidationIsIndependentOfStagingFinalAgreement(): void
    {
        $this->projectionSchema();
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
            self::assertTrue((new RekeyValidator())->final($this->target, $plan, $stages)['validation_complete']);
            $this->target->executeStatement("UPDATE ".$stages['balances']['table']." SET status='corrupt'");
            $this->target->executeStatement("UPDATE balances SET status='corrupt'");
            try {
                (new RekeyValidator())->final($this->target, $plan, $stages);
                self::fail('A constant projection must be checked independently of staging/final agreement.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('planned NULL/constant projections', $error->getMessage());
            }
        } finally {
            $this->target->rollBack();
            $this->source->rollBack();
        }
    }

    public function testProjectionRulesCannotFillTargetOnlyColumnsAndTemporalSpecialsBlock(): void
    {
        $this->target->executeStatement('ALTER TABLE balances ADD memo TEXT NULL');
        $bad = $this->file('.json');
        file_put_contents($bad, json_encode(['version' => 1, 'rules' => [['id' => 'new_column_rule', 'table' => 'balances', 'column' => 'memo', 'operation' => 'null_to', 'value' => 'default']]], JSON_THROW_ON_ERROR));
        $tester = $this->tester();
        self::assertSame(1, $tester->execute($this->planning() + ['--rules' => $bad]), $tester->getDisplay());
        self::assertStringContainsString('column is absent from the transfer plan', $tester->getDisplay());
        $this->target->executeStatement("ALTER TABLE balances ADD relative_time TIMESTAMP DEFAULT ('now'::text)::timestamp");
        self::assertSame(1, $tester->execute($this->planning()), $tester->getDisplay());
        self::assertStringContainsString('functions and expressions are blocked', $tester->getDisplay());
        $this->target->executeStatement("ALTER TABLE balances DROP COLUMN relative_time, ADD relative_time TIMESTAMP DEFAULT 'infinity'::timestamp");
        self::assertSame(1, $tester->execute($this->planning()), $tester->getDisplay());
        self::assertStringContainsString('absolute date/time values', $tester->getDisplay());
    }

    private function projectionSchema(): void
    {
        $this->target->executeStatement(<<<'SQL'
ALTER TABLE balances
ADD memo TEXT NULL,
ADD status VARCHAR(20) NOT NULL DEFAULT 'pending',
ADD attempts BIGINT NOT NULL DEFAULT 0,
ADD enabled BOOLEAN NOT NULL DEFAULT false,
ADD amount NUMERIC(20,4) NOT NULL DEFAULT 123456789012345.1250,
ADD negative_value INTEGER NOT NULL DEFAULT (-7),
ADD float_value DOUBLE PRECISION NOT NULL DEFAULT 1.25,
ADD binary_marker BYTEA NOT NULL DEFAULT '\x00ff'::bytea,
ADD settings JSONB NOT NULL DEFAULT '{"mode":"legacy"}'::jsonb,
ADD cutoff DATE NOT NULL DEFAULT '2020-01-02',
ADD fixed_time TIMESTAMP NOT NULL DEFAULT '2020-01-02 03:04:05',
ADD empty_value TEXT NOT NULL DEFAULT '',
ADD optional_value TEXT NULL DEFAULT 'fallback',
ADD quoted_value TEXT NOT NULL DEFAULT 'it''s ready',
ADD escaped_value TEXT NOT NULL DEFAULT E'line\nnext',
ADD literal_function TEXT NOT NULL DEFAULT 'now()',
ADD explicit_null TEXT DEFAULT NULL
SQL);
    }

    private function nullableReservationSchema(bool $allNullable = true): void
    {
        $this->source->executeStatement('ALTER TABLE reservations MODIFY product_id INT NULL');
        $this->target->executeStatement('ALTER TABLE reservations ALTER COLUMN product_id DROP NOT NULL, ALTER COLUMN balance_id DROP NOT NULL');
        if ($allNullable) {
            $this->source->executeStatement('ALTER TABLE reservations MODIFY warehouse_id BIGINT UNSIGNED NULL');
            $this->target->executeStatement('ALTER TABLE reservations ALTER COLUMN warehouse_id DROP NOT NULL');
        }
    }

    private function selfReferenceSchema(bool $sharedTenant = false, bool $nullable = true): void
    {
        $null = $nullable ? 'NULL' : 'NOT NULL';
        $sourceParent = $sharedTenant ? '' : 'parent_tenant_id BIGINT UNSIGNED '.$null.',';
        $targetParent = $sharedTenant ? '' : 'parent_tenant_id NUMERIC(20,0) '.$null.',';
        $referencing = $sharedTenant ? 'tenant_id,parent_code' : 'parent_tenant_id,parent_code';
        $this->source->executeStatement('CREATE TABLE categories(tenant_id BIGINT UNSIGNED NOT NULL,node_id INT NOT NULL,'.$sourceParent.'parent_code INT '.$null.',PRIMARY KEY(tenant_id,node_id),FOREIGN KEY('.$referencing.') REFERENCES categories(tenant_id,node_id))');
        $this->target->executeStatement('CREATE TABLE categories(id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,tenant_id NUMERIC(20,0) NOT NULL,node_id INT NOT NULL,'.$targetParent.'parent_code INT '.$null.',parent_id BIGINT '.$null.' REFERENCES categories(id) NOT DEFERRABLE,UNIQUE(tenant_id,node_id))');
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
