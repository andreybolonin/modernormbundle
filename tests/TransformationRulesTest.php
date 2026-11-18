<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use ModernORM\Bundle\DataTransfer\TransformationJournal;
use ModernORM\Bundle\DataTransfer\TransformationLog;
use ModernORM\Bundle\DataTransfer\TransformationRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransformationRulesTest extends TestCase
{
    public function testOrderedRulesCountActualChangesWithoutCountingNoOpsTwice(): void
    {
        $rules = TransformationRules::fromDocument(['version' => 1, 'rules' => [
            ['id' => 'trim_name', 'table' => 'people', 'column' => 'name', 'operation' => 'trim'],
            ['id' => 'rename', 'table' => 'people', 'column' => 'name', 'operation' => 'replace', 'from' => 'old', 'to' => 'new'],
            ['id' => 'default', 'table' => 'people', 'column' => 'flag', 'operation' => 'null_to', 'value' => 0],
        ]]);
        $journal = new TransformationJournal($rules, 'validation', 1);
        $row = $rules->applyRow('people', ['id' => 7, 'name' => ' old ', 'flag' => null], ['id' => 7], $journal);
        self::assertSame(['id' => 7, 'name' => 'new', 'flag' => 0], $row);
        $rules->applyRow('people', ['id' => 8, 'name' => 'new', 'flag' => '0'], ['id' => 8], $journal);
        $report = $journal->report();
        self::assertSame(1, $report['changed_rows']);
        self::assertSame(2, $report['changed_values']);
        self::assertSame(3, $report['rule_changes']);
        self::assertCount(1, $report['examples']);
        self::assertTrue($report['examples_truncated']);
        self::assertSame(['id' => '7'], $report['examples'][0]['primary_key']);
        self::assertSame(1, $report['rules']['default']['changes']);
        self::assertStringNotContainsString(' old ', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertSame($rules->describe()['hash'], $report['rules_hash']);
    }

    public function testPkFkUnknownAndBinaryRulesAreRejected(): void
    {
        $plan = ['tables' => ['people' => ['protected_columns' => ['id', 'reference', 'referenced_name'], 'columns' => [
            ['name' => 'id', 'target_type' => 'integer'], ['name' => 'reference', 'target_type' => 'integer'], ['name' => 'referenced_name', 'target_type' => 'string'], ['name' => 'file', 'target_type' => 'blob'],
        ]]]];
        foreach (['id', 'reference', 'referenced_name', 'file', 'missing'] as $column) {
            $rules = TransformationRules::fromDocument(['version' => 1, 'rules' => [['id' => 'fix', 'table' => 'people', 'column' => $column, 'operation' => 'null_to', 'value' => '0']]]);
            self::assertNotEmpty($rules->errorsForPlan($plan));
        }
    }

    public function testNullableDateFixStillHasToPassTargetValidation(): void
    {
        $rules = TransformationRules::fromDocument(['version' => 1, 'rules' => [['id' => 'zero_date', 'table' => 'people', 'column' => 'created_at', 'operation' => 'replace', 'from' => '0000-00-00', 'to' => null]]]);
        $journal = new TransformationJournal($rules, 'validation', 100);
        $row = $rules->applyRow('people', ['created_at' => '0000-00-00'], ['id' => 1], $journal);
        self::assertNull($row['created_at']);
        $validator = new \ModernORM\Bundle\DataTransfer\ValueValidator();
        self::assertStringContainsString('NULL', $validator->check($row['created_at'], new \Doctrine\DBAL\Schema\Column('created_at', 'date')));
        self::assertNull($validator->check($row['created_at'], new \Doctrine\DBAL\Schema\Column('created_at', 'date', ['notnull' => false])));
    }

    public function testCallerReferencesCannotChangeRulesBetweenPasses(): void
    {
        $replacement = 'before';
        $rules = TransformationRules::fromDocument(['version' => 1, 'rules' => [['id' => 'fill', 'table' => 't', 'column' => 'c', 'operation' => 'null_to', 'value' => &$replacement]]]);
        $replacement = 'after';
        $journal = new TransformationJournal($rules, 'load', 0);
        self::assertSame('before', $rules->applyRow('t', ['c' => null], ['id' => 1], $journal)['c']);
    }

    public function testRevertedRuleChangesAreNotFinalChangedValues(): void
    {
        $rules = TransformationRules::fromDocument(['version' => 1, 'rules' => [
            ['id' => 'first', 'table' => 't', 'column' => 'c', 'operation' => 'replace', 'from' => 'a', 'to' => 'b'],
            ['id' => 'second', 'table' => 't', 'column' => 'c', 'operation' => 'replace', 'from' => 'b', 'to' => 'a'],
        ]]);
        $journal = new TransformationJournal($rules, 'validation', 100);
        self::assertSame('a', $rules->applyRow('t', ['c' => 'a'], ['id' => 1], $journal)['c']);
        self::assertSame(2, $journal->report()['rule_changes']);
        self::assertSame(0, $journal->report()['changed_values']);
        self::assertSame(0, $journal->report()['changed_rows']);
    }

    #[DataProvider('invalidRules')]
    public function testInvalidConfigurationFailsExplicitly(array $document): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TransformationRules::fromDocument($document);
    }

    public static function invalidRules(): array
    {
        $rule = ['id' => 'x', 'table' => 't', 'column' => 'c', 'operation' => 'trim'];

        return [
            [['version' => 2, 'rules' => []]],
            [['version' => 1, 'rules' => [$rule, $rule]]],
            [['version' => 1, 'rules' => [array_replace($rule, ['operation' => 'php'])]]],
            [['version' => 1, 'rules' => [$rule + ['unknown' => true]]]],
            [['version' => 1, 'rules' => [array_replace($rule, ['operation' => 'replace', 'from' => '1'])]]],
            [['version' => 1, 'rules' => [array_replace($rule, ['operation' => 'null_to', 'value' => 0.1])]]],
        ];
    }

    public function testStreamingAuditKeepsAllPksWhenExamplesAreDisabled(): void
    {
        $path = sys_get_temp_dir().'/modernorm_changes_'.bin2hex(random_bytes(6)).'.jsonl';
        $rules = TransformationRules::fromDocument(['version' => 1, 'rules' => [['id' => 'clean', 'table' => 't', 'column' => 'name', 'operation' => 'trim']]]);
        try {
            $log = new TransformationLog($path, $rules);
            $journal = new TransformationJournal($rules, 'validation', 0, $log);
            foreach ([1, 2, 3] as $key) {
                $rules->applyRow('t', ['name' => ' secret '], ['id' => $key], $journal);
            }
            $log->finish('validated');
            $events = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
            self::assertCount(5, $events);
            self::assertSame(['id' => '3'], $events[3]['primary_key']);
            self::assertSame('validated', $events[4]['outcome']);
            self::assertSame(3, $journal->report()['changed_values']);
            self::assertSame([], $journal->report()['examples']);
            self::assertStringNotContainsString('secret', file_get_contents($path));
            $this->expectException(\InvalidArgumentException::class);
            new TransformationLog($path, $rules);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
