<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class TransferCommand extends Command
{
    public function __construct(private readonly Configuration $configuration, private readonly ?Connection $applicationConnection = null)
    {
        parent::__construct('modernorm:data:transfer');
    }

    protected function configure(): void
    {
        $this->setDescription('Plan, rehearse or execute a MySQL to PostgreSQL data transfer.')
            ->addOption('source-url-env', null, InputOption::VALUE_REQUIRED, 'Environment variable holding the MySQL source URL.')
            ->addOption('target-url-env', null, InputOption::VALUE_REQUIRED, 'Environment variable holding the PostgreSQL target URL.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Planned maximum rows per batch.', '1000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect without writing any data.')
            ->addOption('rehearsal', null, InputOption::VALUE_NONE, 'Validate and load a separate empty rehearsal database, committing results for inspection.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Validate and load the explicit final PostgreSQL target, committing only after verification.')
            ->addOption('validate-data', null, InputOption::VALUE_NONE, 'Scan every source row in bounded batches and validate target values without writing.')
            ->addOption('max-error-examples', null, InputOption::VALUE_REQUIRED, 'Maximum retained validation examples; scanning and error counting always continue.', '100')
            ->addOption('rules', null, InputOption::VALUE_REQUIRED, 'JSON file containing explicit, versioned column transformation rules.')
            ->addOption('max-change-examples', null, InputOption::VALUE_REQUIRED, 'Maximum retained transformation examples per phase; all changes are counted.', '100')
            ->addOption('change-log', null, InputOption::VALUE_REQUIRED, 'New local JSONL file recording every rule change and full PK, without value payloads.')
            ->addOption('suggest-rules', null, InputOption::VALUE_NONE, 'Interactively propose rules after a full read-only scan, save choices and revalidate.')
            ->addOption('rules-output', null, InputOption::VALUE_REQUIRED, 'New proposed-rules file for --suggest-rules (default: rules.proposed.json).')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Report format: text or json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rehearsal = $input->getOption('rehearsal');
        $production = $input->getOption('execute');
        $write = $rehearsal || $production;
        $loadKey = $production ? 'transfer' : 'rehearsal';
        if ((int) $input->getOption('dry-run') + (int) $rehearsal + (int) $production !== 1) {
            throw new \InvalidArgumentException('Pass --dry-run or --rehearsal or --execute, exclusively.');
        }
        $sourceEnv = $input->getOption('source-url-env');
        $targetEnv = $input->getOption('target-url-env');
        $size = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $format = $input->getOption('format');
        $maxExamples = filter_var($input->getOption('max-error-examples'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $maxChanges = filter_var($input->getOption('max-change-examples'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $suggest = $input->getOption('suggest-rules');
        if ($suggest && ($write || !$input->isInteractive() || $format !== 'text' || $input->getOption('change-log') !== null)) {
            throw new \InvalidArgumentException('--suggest-rules requires interactive --dry-run with text output, without --rehearsal, --execute or --change-log.');
        }
        if (!$suggest && $input->getOption('rules-output') !== null) {
            throw new \InvalidArgumentException('--rules-output requires --suggest-rules.');
        }
        $rulesOutput = $input->getOption('rules-output') ?? 'rules.proposed.json';
        if ($suggest) {
            SuggestionWizard::checkOutput($rulesOutput);
        }
        if (!is_string($sourceEnv) || !is_string($targetEnv) || $size === false || $maxExamples === false || $maxChanges === false || !in_array($format, ['text', 'json'], true)) {
            throw new \InvalidArgumentException('Both connection environment names, a positive batch size, nonnegative max error examples, nonnegative max change examples and format text/json are required.');
        }
        $rulesPath = $input->getOption('rules');
        $rules = $rulesPath === null ? TransformationRules::empty() : TransformationRules::fromFile($rulesPath);
        $logPath = $input->getOption('change-log');
        if ($logPath !== null && !$write && !$input->getOption('validate-data')) {
            throw new \InvalidArgumentException('--change-log requires --validate-data, --rehearsal or --execute.');
        }
        if ($production) {
            $this->warnBeforeExecution($output, $format);
        }
        $source = EnvironmentConnection::open($sourceEnv, 'pdo_mysql');
        $target = null;
        $log = null;
        $outcome = 'exception';
        try {
            $log = $logPath === null ? null : new TransformationLog($logPath, $rules);
            $target = EnvironmentConnection::open($targetEnv, 'pdo_pgsql');
            if ($rehearsal) {
                (new RehearsalGuard())->checkDatabase($target, $this->applicationConnection);
            } elseif ($production) {
                (new TargetGuard())->checkDatabase($target);
            }
            // The source is always a read-only consistent snapshot.
            $source->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $source->beginTransaction();
            $target->beginTransaction();
            $target->executeStatement($write ? 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ WRITE' : 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $configuration = clone $this->configuration;
            $configuration->setMetadataCache(new ArrayAdapter());
            $manager = new EntityManager($target, $configuration);
            $metadata = $manager->getMetadataFactory()->getAllMetadata();
            $plan = (new Planner())->plan($source, $target, $metadata, $size);
            $plan['rules'] = $rules->describe();
            $plan['errors'] = array_merge($plan['errors'], $rules->errorsForPlan($plan));
            if ($write) {
                $plan['mode'] = $production ? 'execute' : 'rehearsal';
                $plan['connections'] = ['source_database' => $source->getDatabase(), 'target_database' => $target->fetchOne('SELECT pg_catalog.current_database()')];
                $plan[$loadKey] = ['status' => 'skipped_structural_errors', 'target_data_committed' => false];
                if ($plan['errors'] === []) {
                    try {
                        $plan[$loadKey] = (new Loader())->load($source, $target, $plan, $maxExamples, $rules, $maxChanges, $log);
                        if ($plan[$loadKey]['status'] === 'loaded') {
                            try {
                                $target->commit();
                                $plan[$loadKey]['status'] = 'committed';
                                $plan[$loadKey]['stage'] = 'complete';
                                $plan[$loadKey]['target_data_committed'] = true;
                            } catch (\Doctrine\DBAL\Exception) {
                                $plan[$loadKey]['status'] = 'commit_failed';
                                $plan[$loadKey]['stage'] = 'commit';
                                $plan[$loadKey]['target_data_committed'] = null;
                                $plan['errors'][] = 'Transfer COMMIT failed; final database state may be uncertain. Inspect the explicit target database.';
                            }
                        } else {
                            $plan['errors'][] = 'Transfer stopped before loading because value validation failed.';
                        }
                    } catch (LoadFailure $failure) {
                        $plan[$loadKey] = $failure->report;
                        $plan['errors'][] = $failure->getMessage();
                    }
                    if ($plan[$loadKey]['validation'] !== null) {
                        $plan['validation'] = $plan[$loadKey]['validation'];
                        $plan['data_scanned'] = true;
                    }
                }
            } elseif ($input->getOption('validate-data') || $suggest) {
                if ($plan['errors'] !== []) {
                    $plan['validation'] = ['status' => 'skipped_structural_errors', 'rows_scanned' => 0, 'error_count' => 0, 'error_examples' => []];
                    if ($suggest) {
                        $plan['suggestions'] = ['status' => 'skipped_structural_errors'];
                    }
                } else {
                    $collector = $suggest ? new RuleSuggestions() : null;
                    if ($suggest) {
                        $output->writeln('Scanning every source row for rule suggestions (read-only)...');
                    }
                    $plan['validation'] = (new DataValidator())->validate($source, $target, $plan, $maxExamples, $rules, $maxChanges, $log, $collector);
                    $plan['data_scanned'] = true;
                    if ($collector !== null) {
                        $evidence = $collector->report();
                        $choice = (new SuggestionWizard())->run(new SymfonyStyle($input, $output), $rules, $evidence, $rulesOutput, $plan);
                        $selectedRules = $choice['rules'];
                        unset($choice['rules']);
                        $plan['suggestions'] = $choice + $evidence + ['initial_error_count' => $plan['validation']['error_count'], 'initial_rules_hash' => $rules->describe()['hash']];
                        if ($selectedRules instanceof TransformationRules) {
                            $rules = $selectedRules;
                            $plan['rules'] = $rules->describe();
                            $plan['validation'] = (new DataValidator())->validate($source, $target, $plan, $maxExamples, $rules, $maxChanges);
                        }
                    }
                    if ($plan['validation']['error_count'] > 0) {
                        $plan['errors'][] = sprintf('Data validation found %d invalid values in %d rows.', $plan['validation']['error_count'], $plan['validation']['invalid_rows']);
                    }
                }
            }
            $outcome = $plan['errors'] === [] ? ($write ? 'committed' : 'validated') : 'failed';
            if (isset($plan[$loadKey]) && array_key_exists('target_data_committed', $plan[$loadKey]) && $plan[$loadKey]['target_data_committed'] === null) {
                $outcome = 'commit_uncertain';
            }
        } finally {
            try {
                $this->close($source);
            } finally {
                try {
                    if ($target !== null) {
                        $this->close($target);
                    }
                } finally {
                    $log?->finish($outcome);
                }
            }
        }
        if ($format === 'json') {
            $output->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title($write ? ($production ? 'MySQL to PostgreSQL final transfer' : 'MySQL to PostgreSQL rehearsal') : 'MySQL to PostgreSQL transfer plan');
            if (isset($plan['connections'])) {
                $io->text('Source database: '.$plan['connections']['source_database'].'; target database: '.$plan['connections']['target_database']);
            }
            foreach ($plan['tables'] as $name => $table) {
                $io->section($table['source'].' -> '.$table['target']);
                $io->text(($table['entity'] === null ? 'ManyToMany join table' : 'Entity: '.$table['entity']).'; planned batch size: '.$table['batch_size']);
                $io->table(['Column', 'Planned conversion'], array_map(static fn (array $column): array => [$column['name'], $column['conversion']], $table['columns']));
                if ($table['sequence_after_load'] !== null) {
                    $io->text('Owned sequence: '.$table['sequence_after_load'].($write ? ' (see load result).' : ' (reset not executed).'));
                }
            }
            $io->text('Load order: '.implode(' -> ', $plan['order']));
            foreach ($plan['warnings'] as $warning) {
                $io->warning($warning);
            }
            foreach ($plan['errors'] as $error) {
                $io->error($error);
            }
            if (isset($plan['validation'])) {
                $validation = $plan['validation'];
                $io->section('Data validation: '.$validation['status']);
                $io->text(sprintf('Rows scanned: %d; invalid values: %d.', $validation['rows_scanned'], $validation['error_count']));
                foreach ($validation['error_examples'] as $example) {
                    $io->text(sprintf('%s PK=%s column=%s: %s', $example['table'], json_encode($example['primary_key'], JSON_THROW_ON_ERROR), $example['column'], $example['error']));
                }
                if ($validation['examples_truncated'] ?? false) {
                    $io->text('Example limit reached; every row was still scanned and every error counted.');
                }
                if (isset($validation['transformations'])) {
                    $this->showChanges($io, $validation['transformations']);
                }
            }
            if ($write) {
                $io->section(($production ? 'Transfer: ' : 'Rehearsal: ').$plan[$loadKey]['status']);
                $io->text(json_encode($plan[$loadKey]['tables'] ?? [], JSON_THROW_ON_ERROR));
                if (isset($plan[$loadKey]['transformations'])) {
                    $this->showChanges($io, $plan[$loadKey]['transformations']);
                }
                $io->note('Results are committed only after verification to the explicitly selected target database. On failure table writes are rolled back where possible; sequences and external trigger actions may persist. Inspect the database before retrying.');
            } else {
                $io->note($plan['data_scanned'] ? 'No data written. All planned source rows were scanned for value compatibility. UNIQUE, FK, CHECK constraints, triggers and timezone conversion policies were not validated.' : 'No data written. Row values were not scanned. Use --validate-data after resolving structural blockers.');
            }
            if ($plan['errors'] === []) {
                $io->success($write ? ($production ? 'Final transfer committed. Switch the application connection separately; source unchanged.' : 'Rehearsal committed. Constraints, row counts and primary keys checked; source unchanged.') : ($plan['data_scanned'] ? 'Value scan completed without detected errors. No data written.' : 'Structural plan prepared. Per-value checks remain required.'));
            }
        }

        return $plan['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function close(Connection $connection): void
    {
        try {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } finally {
            $connection->close();
        }
    }

    private function warnBeforeExecution(OutputInterface $output, string $format): void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        } elseif ($format === 'json') {
            $stream = fopen('php://stderr', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Cannot display the execution warning on stderr.');
            }
            $output = new StreamOutput($stream, $output->getVerbosity(), false);
        }
        $output->writeln([
            '[WARNING] --execute performs real writes to PostgreSQL.',
            'Stop writes to MySQL and stop application/worker traffic to the PostgreSQL target before proceeding.',
            'MySQL changes committed after the snapshot is established will NOT be transferred.',
            'Target tables must be empty; this will be checked before loading.',
            'The command cannot verify that source writes have stopped. Application cutover is a separate step.',
        ], OutputInterface::VERBOSITY_QUIET);
    }

    private function showChanges(SymfonyStyle $io, array $changes): void
    {
        $io->section('Transformations: '.$changes['phase']);
        $io->text(sprintf('Changed rows: %d; changed values: %d; rule changes: %d; rules hash: %s', $changes['changed_rows'], $changes['changed_values'], $changes['rule_changes'], $changes['rules_hash']));
        foreach ($changes['rules'] as $rule) {
            $io->text(sprintf('%s %s.%s: %d changes', $rule['id'], $rule['table'], $rule['column'], $rule['changes']));
        }
        foreach ($changes['examples'] as $example) {
            $io->text(sprintf('%s %s.%s PK=%s', $example['rule'], $example['table'], $example['column'], json_encode($example['primary_key'], JSON_THROW_ON_ERROR)));
        }
    }
}
