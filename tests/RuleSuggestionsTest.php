<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\Schema\Column;
use ModernORM\Bundle\DataTransfer\RuleSuggestions;
use ModernORM\Bundle\DataTransfer\SuggestionWizard;
use ModernORM\Bundle\DataTransfer\TransformationRules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class RuleSuggestionsTest extends TestCase
{
    public function testEvidenceCountsAllValuesAndProtectsKeys(): void
    {
        $collector = new RuleSuggestions();
        for ($i = 0; $i < 500; ++$i) {
            $collector->observe('t', 'required', null, new Column('required', 'string'), false);
            $collector->error('t', 'required', 'NULL is not allowed.');
        }
        $collector->observe('t', 'id', ' padded ', new Column('id', 'string'), true);
        $collector->observe('t', 'created_at', '0000-00-00', new Column('created_at', 'date', ['notnull' => false]), false);
        $collector->observe('t', 'name', ' private payload ', new Column('name', 'string'), false);
        $report = $collector->report();
        self::assertCount(3, $report['candidates']);
        self::assertSame(500, $report['candidates'][0]['count']);
        self::assertSame(500, $report['issues'][0]['count']);
        self::assertSame('zero_nullable', $report['candidates'][1]['kind']);
        self::assertStringNotContainsString('private payload', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertNotContains('id', array_column($report['candidates'], 'column'));
    }

    public function testSelectedRulesAreSavedWithExistingRulesAfterConfirmation(): void
    {
        $path = sys_get_temp_dir().'/modernorm_suggest_'.bin2hex(random_bytes(6)).'.json';
        $existing = TransformationRules::fromDocument(['version' => 1, 'rules' => [['id' => 'existing', 'table' => 't', 'column' => 'name', 'operation' => 'replace', 'from' => 'old', 'to' => 'new']]]);
        $command = new WizardFixtureCommand($existing, $this->evidence(), $path, $this->plan());
        $tester = new CommandTester($command);
        $tester->setInputs(['1', '1', '1', '"0.00"', 'yes']);
        try {
            self::assertSame(0, $tester->execute([], ['interactive' => true]));
            self::assertSame('saved', $command->result['status']);
            $saved = TransformationRules::fromFile($path)->document();
            self::assertCount(4, $saved['rules']);
            self::assertSame('existing', $saved['rules'][0]['id']);
            self::assertSame('trim', $saved['rules'][1]['operation']);
            self::assertNull($saved['rules'][2]['to']);
            self::assertSame('0.00', $saved['rules'][3]['value']);
            self::assertStringContainsString('Review complete rules file', $tester->getDisplay());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testDeclineAndSkipDoNotCreateFiles(): void
    {
        $path = sys_get_temp_dir().'/modernorm_suggest_'.bin2hex(random_bytes(6)).'.json';
        $command = new WizardFixtureCommand(TransformationRules::empty(), ['candidates' => [$this->evidence()['candidates'][0]], 'issues' => []], $path, $this->plan());
        $tester = new CommandTester($command);
        $tester->setInputs(['1', 'no']);
        $tester->execute([], ['interactive' => true]);
        self::assertSame('declined', $command->result['status']);
        self::assertFileDoesNotExist($path);
        $tester->setInputs(['0']);
        $tester->execute([], ['interactive' => true]);
        self::assertSame('no_rules_selected', $command->result['status']);
        self::assertFileDoesNotExist($path);
    }

    public function testExistingOutputIsNeverOverwritten(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'modernorm_existing_');
        file_put_contents($path, 'keep');
        try {
            try {
                SuggestionWizard::checkOutput($path);
                self::fail('Existing file must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('never overwritten', $error->getMessage());
            }
            self::assertSame('keep', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    private function evidence(): array
    {
        return ['issues' => [], 'candidates' => [
            ['table' => 't', 'column' => 'name', 'kind' => 'trim', 'count' => 2, 'from' => null],
            ['table' => 't', 'column' => 'created', 'kind' => 'zero_nullable', 'count' => 1, 'from' => '0000-00-00'],
            ['table' => 't', 'column' => 'amount', 'kind' => 'null_required', 'count' => 1, 'from' => null],
        ]];
    }

    private function plan(): array
    {
        return ['tables' => ['t' => ['primary_key' => ['id'], 'protected_columns' => ['id'], 'columns' => [
            ['name' => 'name', 'target_type' => 'string'], ['name' => 'created', 'target_type' => 'date'], ['name' => 'amount', 'target_type' => 'decimal'],
        ]]]];
    }
}

final class WizardFixtureCommand extends Command
{
    public array $result = [];

    public function __construct(private readonly TransformationRules $rules, private readonly array $evidence, private readonly string $path, private readonly array $plan)
    {
        parent::__construct('test:wizard');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->result = (new SuggestionWizard())->run(new SymfonyStyle($input, $output), $this->rules, $this->evidence, $this->path, $this->plan);

        return self::SUCCESS;
    }
}
