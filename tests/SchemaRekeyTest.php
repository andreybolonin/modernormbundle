<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use ModernORM\Bundle\DataTransfer\TransferCommand;
use ModernORM\Bundle\DataTransfer\TransformationRules;
use ModernORM\Bundle\SchemaRekey\DependencyGraph;
use ModernORM\Bundle\SchemaRekey\RekeyPlan;
use ModernORM\Bundle\SchemaRekey\RekeyRules;
use ModernORM\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaRekeyTest extends TestCase
{
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsFailBeforeOpeningConnections(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CommandTester(new TransferCommand(new Configuration())))->execute($options);
    }

    public static function invalidOptions(): iterable
    {
        yield [['--schema-rekey' => true]];
        yield [['--schema-rekey' => true, '--execute' => true]];
        yield [['--schema-rekey' => true, '--dry-run' => true, '--batch-size' => '0']];
        yield [['--schema-rekey' => true, '--dry-run' => true, '--max-change-examples' => '-1']];
        yield [['--schema-rekey' => true, '--dry-run' => true, '--suggest-rules' => true, '--format' => 'json']];
        yield [['--schema-rekey' => true, '--dry-run' => true, '--rules-output' => 'unused.json']];
        yield [['--schema-rekey' => true, '--dry-run' => true, '--change-log' => 'unused.jsonl']];
        yield [['--dry-run' => true, '--plan' => 'rekey.yaml']];
        yield [['--dry-run' => true, '--plan-output' => 'rekey.yaml']];
        yield [['--dry-run' => true, '--target-schema' => 'public']];
    }

    public function testGraphBlocksWholePlanAndReportsDependentCycles(): void
    {
        $graph = new DependencyGraph();
        $edge = ['child' => 'links', 'parent' => 'parents', 'name' => 'fk', 'classification' => 'remap', 'reason' => null];
        self::assertSame(['parents', 'links'], $graph->order(['links' => [], 'parents' => []], [$edge])['order']);
        $edge['classification'] = 'blocked';
        $edge['reason'] = 'unsupported nullable composite key';
        self::assertStringContainsString('unsupported nullable', implode(' ', $graph->order(['links' => [], 'parents' => []], [$edge])['blockers']));
    }

    public function testRulesProtectSourceAndTargetKeysAndAllBusinessUniqueColumns(): void
    {
        $table = ['source_key' => ['a', 'b'], 'target_key' => ['id'], 'columns' => [['name' => 'a', 'target_type' => 'bigint'], ['name' => 'b', 'target_type' => 'bigint'], ['name' => 'code', 'target_type' => 'string'], ['name' => 'name', 'target_type' => 'string']], 'source_structure' => ['indexes' => [], 'foreign_keys' => []], 'target_structure' => ['indexes' => [['unique' => true, 'columns' => ['code']]], 'foreign_keys' => [['columns' => ['parent_id']]]]];
        $plan = ['tables' => ['items' => $table], 'foreign_keys' => [], 'blockers' => []];
        $adapted = RekeyRules::validationPlan($plan, 10);
        self::assertSame(['a', 'b'], $adapted['tables']['items']['primary_key']);
        foreach (['a', 'b', 'id', 'code', 'parent_id'] as $protected) {
            self::assertContains($protected, $adapted['tables']['items']['protected_columns']);
        }
        $rules = TransformationRules::fromDocument(['version' => 1, 'rules' => [['id' => 'trim_code', 'table' => 'items', 'column' => 'code', 'operation' => 'trim']]]);
        self::assertNotEmpty(RekeyRules::bind($plan, $rules)['blockers']);
        $rules = TransformationRules::fromDocument(['version' => 1, 'rules' => [['id' => 'trim_name', 'table' => 'items', 'column' => 'name', 'operation' => 'trim']]]);
        self::assertSame([], RekeyRules::bind($plan, $rules)['blockers']);
        $plan['tables']['items']['target_structure']['indexes'][] = ['unique' => true, 'columns' => ['lower(name)']];
        self::assertNotEmpty(RekeyRules::bind($plan, $rules)['blockers']);
    }

    public function testPlanRoundTripHashAndStrictYaml(): void
    {
        $plan = RekeyPlan::seal(['version' => 1, 'command' => 'modernorm:data:transfer', 'schema_rekey' => true, 'rules' => TransformationRules::empty()->describe(), 'execution' => 'offline_transactional', 'connections' => ['source_url_env' => 'SOURCE', 'target_url_env' => 'TARGET'], 'target_schema' => 'public', 'source_fence' => ['strategy' => 'mysql_global_read_lock', 'timeout_seconds' => 30], 'id_strategy' => ['type' => 'bigint', 'allocation' => 'source_pk_order'], 'endpoint_identity' => [], 'source_session' => [], 'fingerprints' => [], 'tables' => [], 'foreign_keys' => [], 'load_order' => [], 'blockers' => []]);
        $path = sys_get_temp_dir().'/rekey_plan_'.bin2hex(random_bytes(8)).'.yaml';
        try {
            RekeyPlan::write($path, $plan);
            self::assertSame($plan, RekeyPlan::read($path));
            file_put_contents($path, "version: 1\nversion: 1\n");
            try {
                RekeyPlan::read($path);
                self::fail('Duplicate keys must fail.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Duplicate', $error->getMessage());
            }
            file_put_contents($path, "value: &ref [1]\ncopy: *ref\n");
            $this->expectException(\InvalidArgumentException::class);
            RekeyPlan::read($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
