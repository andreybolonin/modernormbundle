<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use ModernORM\Bundle\DataTransfer\EnvironmentConnection;
use ModernORM\Bundle\DataTransfer\RehearsalGuard;
use ModernORM\Bundle\DataTransfer\TargetGuard;
use Symfony\Component\Console\Command\Command;
use ModernORM\Bundle\DataTransfer\TransformationRules;
use ModernORM\Bundle\DataTransfer\TransformationJournal;
use ModernORM\Bundle\DataTransfer\TransformationLog;
use ModernORM\Bundle\DataTransfer\RuleSuggestions;
use ModernORM\Bundle\DataTransfer\SuggestionWizard;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Separate rekey execution path behind data:transfer --schema-rekey. */
final class RekeyTransfer
{
    public function __construct(private readonly ?Connection $applicationConnection = null)
    {
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $dry = (bool) $input->getOption('dry-run');
        $rehearsal = (bool) $input->getOption('rehearsal');
        $execute = (bool) $input->getOption('execute');
        $size = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $format = $input->getOption('format');
        if ((int) $dry + (int) $rehearsal + (int) $execute !== 1 || $size === false || !in_array($format, ['text', 'json'], true)) {
            throw new \InvalidArgumentException('Choose exactly one of --dry-run, --rehearsal, --execute; batch size must be positive and format text/json.');
        }
        $path = $input->getOption('plan');
        $save = $input->getOption('plan-output');
        $suggest = (bool) $input->getOption('suggest-rules');
        $scan = (bool) $input->getOption('validate-data') || $suggest || !$dry;
        $maxErrors = filter_var($input->getOption('max-error-examples'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $maxChanges = filter_var($input->getOption('max-change-examples'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $logPath = $input->getOption('change-log');
        $rulesOutput = $input->getOption('rules-output') ?? 'rules.proposed.json';
        if ($maxErrors === false || $maxChanges === false || ($logPath !== null && !$scan)) {
            throw new \InvalidArgumentException('Example limits must be nonnegative; --change-log requires a value scan or write mode.');
        }
        if ($suggest && (!$dry || !$input->isInteractive() || $format !== 'text' || $logPath !== null)) {
            throw new \InvalidArgumentException('--suggest-rules requires interactive text --dry-run without --change-log.');
        }
        if (!$suggest && $input->getOption('rules-output') !== null) {
            throw new \InvalidArgumentException('--rules-output requires --suggest-rules.');
        }
        if ($suggest) {
            SuggestionWizard::checkOutput($rulesOutput);
        }
        $rulesPath = $input->getOption('rules');
        $rules = $rulesPath === null ? TransformationRules::empty() : TransformationRules::fromFile($rulesPath);
        $saved = $path === null ? null : RekeyPlan::read($path);
        if ($saved !== null) {
            foreach (['source-url-env' => $saved['connections']['source_url_env'], 'target-url-env' => $saved['connections']['target_url_env'], 'target-schema' => $saved['target_schema']] as $option => $expected) {
                $provided = $input->getOption($option);
                if ($provided !== null && $provided !== $expected) {
                    throw new \InvalidArgumentException('Option --'.$option.' differs from the saved plan; omit --plan to build a fresh plan.');
                }
            }
        }
        if ($saved !== null && $saved['rules'] !== $rules->describe()) {
            throw new \InvalidArgumentException('Rules do not match the saved plan; supply its matching --rules file or regenerate the plan.');
        }

        $connections = $saved['connections'] ?? ['source_url_env' => $input->getOption('source-url-env'), 'target_url_env' => $input->getOption('target-url-env')];
        foreach ($connections as $environment) {
            if (!is_string($environment) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $environment) !== 1) {
                throw new \InvalidArgumentException('Source and target environment variable names are required.');
            }
        }
        $schema = $saved['target_schema'] ?? $input->getOption('target-schema') ?? 'public';
        if (!is_string($schema) || $schema === '' || str_contains($schema, "\0")) {
            throw new \InvalidArgumentException('A valid target schema is required.');
        }
        if ($save !== null && (file_exists($save) || is_link($save) || !is_dir(dirname($save)) || !is_writable(dirname($save)))) {
            throw new \InvalidArgumentException('Plan output must be a new file in a writable existing directory.');
        }
        if (!$dry) {
            $this->warnBeforeWrite($output, $format);
        }
        $source = $target = $log = $journal = null;
        $fence = false;
        $session = null;
        $mode = $dry ? 'dry-run' : ($rehearsal ? 'rehearsal' : 'execute');
        $report = ['command' => 'modernorm:data:transfer', 'schema_rekey' => true, 'mode' => $mode, 'stage' => 'connections', 'plan_hash' => $saved['plan_hash'] ?? null, 'source_fence' => 'not_acquired', 'data_scanned' => false, 'validation_complete' => false, 'target_data_committed' => false, 'blockers' => [], 'validation' => null];
        try {
            $log = $logPath === null ? null : new TransformationLog($logPath, $rules);
            $journal = new TransformationJournal($rules, 'load', $maxChanges, $log);
            $source = EnvironmentConnection::open($connections['source_url_env'], 'pdo_mysql');
            $target = EnvironmentConnection::open($connections['target_url_env'], 'pdo_pgsql');
            if ($rehearsal) {
                (new RehearsalGuard())->checkDatabase($target, $this->applicationConnection);
            } else {
                (new TargetGuard())->checkDatabase($target);
            }
            $source->executeStatement("SET NAMES utf8mb4 COLLATE utf8mb4_bin");
            $source->executeStatement("SET SESSION time_zone = '+00:00'");
            $target->executeStatement("SET TIME ZONE 'UTC'");
            $target->executeStatement('SET standard_conforming_strings=on');
            if (!$dry) {
                $report['stage'] = 'source_fence';
                $session = (new SourceFence())->acquire($source);
                $fence = true;
                $report['source_fence'] = 'held';
            }
            $source->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $source->beginTransaction();
            $target->beginTransaction();
            $target->executeStatement($dry ? 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY' : 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ WRITE');
            $report['stage'] = 'planning';
            $plan = RekeyRules::bind((new RekeyPlanner())->plan($source, $target, $schema, $connections), $rules);
            if ($saved !== null && !hash_equals($saved['plan_hash'], $plan['plan_hash'])) {
                throw new \RuntimeException('Saved plan differs from current endpoints, catalogs or execution policy; regenerate and review it.');
            }
            $report['plan'] = $plan;
            $report['plan_hash'] = $plan['plan_hash'];
            $report['blockers'] = $plan['blockers'];
            if ($plan['blockers'] === []) {
                if ($dry) {
                    $this->checkEmpty($target, $plan);
                    if ($scan) {
                        $report['stage'] = 'value_validation';
                        $collector = $suggest ? new RuleSuggestions() : null;
                        $report['value_validation'] = (new RekeyRules())->validate($source, $target, $plan, $size, $rules, $maxErrors, $maxChanges, $log, $collector);
                        $report['data_scanned'] = true;
                        if ($collector !== null) {
                            $evidence = $collector->report();
                            $choice = (new SuggestionWizard())->run(new SymfonyStyle($input, $output), $rules, $evidence, $rulesOutput, RekeyRules::validationPlan($plan, $size));
                            $selected = $choice['rules'];
                            unset($choice['rules']);
                            $report['suggestions'] = $choice + $evidence;
                            if ($selected instanceof TransformationRules) {
                                $rules = $selected;
                                $plan = RekeyRules::bind($plan, $rules);
                                $report['plan_hash'] = $plan['plan_hash'];
                                $report['plan'] = $plan;
                                $report['value_validation'] = (new RekeyRules())->validate($source, $target, $plan, $size, $rules, $maxErrors, $maxChanges);
                            }
                        }
                        if ($report['value_validation']['error_count'] !== 0) {
                            throw new \RuntimeException('Value validation failed; no executable plan was saved.');
                        }
                    }
                    if ($save !== null) {
                        RekeyPlan::write($save, $plan);
                        $report['plan_output'] = $save;
                    }
                } else {
                    $report['stage'] = 'target_locks';
                    (new TargetGuard())->lockTargets($target, $plan);
                    $locked = RekeyRules::bind((new RekeyPlanner())->plan($source, $target, $schema, $connections), $rules);
                    if (!hash_equals($plan['plan_hash'], $locked['plan_hash'])) {
                        throw new \RuntimeException('Target schema changed while acquiring table locks.');
                    }
                    $report['stage'] = 'value_validation';
                    $report['value_validation'] = (new RekeyRules())->validate($source, $target, $plan, $size, $rules, $maxErrors, $maxChanges, $log);
                    $report['data_scanned'] = true;
                    if ($report['value_validation']['error_count'] !== 0) {
                        throw new \RuntimeException('Value validation failed before staging.');
                    }
                    if ($save !== null) {
                        RekeyPlan::write($save, $plan);
                        $report['plan_output'] = $save;
                    }
                    $report['stage'] = 'staging';
                    $stages = (new StagingManager())->createAndCopy($source, $target, $plan, $size, $rules, $journal);
                    $report['stage'] = 'allocate';
                    (new SurrogateIdAllocator())->allocate($target, $plan, $stages);
                    $report['stage'] = 'remap';
                    (new ForeignKeyRemapper())->remap($target, $plan, $stages);
                    $report['stage'] = 'load';
                    $report['rows_loaded'] = (new StagingManager())->load($target, $plan, $stages);
                    $report['stage'] = 'source_validation';
                    $report['source_rows'] = (new RekeyValidator())->source($source, $target, $plan, $stages, $size, $rules);
                    $report['data_scanned'] = true;
                    $report['stage'] = 'validation';
                    $report['validation'] = (new RekeyValidator())->final($target, $plan, $stages);
                    $report['validation_complete'] = true;
                    $report['stage'] = 'sequences';
                    $report['sequences_reset'] = (new SurrogateIdAllocator())->synchronize($target, $plan);
                    $report['stage'] = 'source_fence_check';
                    (new SourceFence())->assertHeld($source, $session);
                    $report['stage'] = 'commit';
                    // A failed COMMIT has an uncertain outcome; never retry automatically.
                    $report['target_data_committed'] = null;
                    $target->commit();
                    $report['target_data_committed'] = true;
                    (new SourceFence())->assertHeld($source, $session);
                    $report['source_fence_at_commit'] = 'held';
                }
            }
            $report['plan'] = $plan;
            $report['stage'] = $plan['blockers'] === [] ? 'complete' : 'blocked';
        } catch (\Throwable $error) {
            $report['failure_type'] = (new \ReflectionClass($error))->getShortName();
            $report['sqlstate'] = $error instanceof DriverException ? $error->getSQLState() : null;
            $report['blockers'][] = $error instanceof \Doctrine\DBAL\Exception ? 'Database rejected rekey at '.$report['stage'].' (SQLSTATE '.($report['sqlstate'] ?? 'unknown').').' : $error->getMessage();
            if ($fence && in_array($report['stage'], ['source_fence_check', 'commit'], true)) {
                $report['source_fence_at_commit'] = 'unverified';
            }
        } finally {
            if ($target !== null) {
                try {
                    if ($target->isTransactionActive()) {
                        $target->rollBack();
                    }
                } catch (\Throwable) {
                    $report['blockers'][] = 'Target cleanup failed; inspect the planned database before retrying.';
                } finally {
                    $target->close();
                }
            }
            if ($source !== null) {
                try {
                    if ($source->isTransactionActive()) {
                        $source->rollBack();
                    }
                    if ($fence) {
                        (new SourceFence())->release($source);
                        $report['source_fence'] = 'released';
                    }
                } catch (\Throwable) {
                    $report['source_fence'] = 'connection_lost';
                } finally {
                    $source->close();
                }
            }
        }
        if (!$dry && $journal !== null) {
            $report['transformations'] = $journal->report();
        }
        if ($log !== null) {
            $outcome = $report['target_data_committed'] === null ? 'commit_uncertain' : ($report['target_data_committed'] ? 'committed' : ($report['blockers'] === [] ? 'validated' : 'failed'));
            try {
                $log->finish($outcome);
            } catch (\Throwable $error) {
                $report['blockers'][] = $error->getMessage();
            }
        }
        if ($format === 'json') {
            $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('MySQL to PostgreSQL schema rekey: '.$mode);
            $io->text('Stage: '.$report['stage'].'; plan: '.($report['plan_hash'] ?? 'unavailable'));
            if (isset($report['plan'])) {
                $io->table(['Source', 'Target', 'Policy', 'Source PK', 'Target PK'], array_map(static fn (array $table): array => [$table['source'], $table['target'], $table['policy'], implode(', ', $table['source_key']), implode(', ', $table['target_key'])], array_values($report['plan']['tables'])));
                foreach ($report['plan']['tables'] as $table) {
                    if ($table['target_projections'] !== []) {
                        $io->section($table['target'].' target-only columns');
                        $io->table(['Column', 'Fill', 'Type'], array_map(static fn (string $column, array $projection): array => [$column, $projection['operation'], $projection['target_type']], array_keys($table['target_projections']), array_values($table['target_projections'])));
                    }
                }
            }
            if (isset($report['value_validation'])) {
                $scanResult = $report['value_validation'];
                $io->text(sprintf('Rows scanned: %d; invalid values: %d.', $scanResult['rows_scanned'], $scanResult['error_count']));
                foreach ($scanResult['error_examples'] as $example) {
                    $io->text(sprintf('%s PK=%s column=%s: %s', $example['table'], json_encode($example['primary_key'], JSON_THROW_ON_ERROR), $example['column'], $example['error']));
                }
                $changes = $scanResult['transformations'];
                $io->text(sprintf('Validation transformations: %d rows, %d values; rules hash: %s.', $changes['changed_rows'], $changes['changed_values'], $changes['rules_hash']));
            }
            if (isset($report['transformations'])) {
                $changes = $report['transformations'];
                $io->text(sprintf('Load transformations: %d rows, %d values.', $changes['changed_rows'], $changes['changed_values']));
            }
            foreach ($report['blockers'] as $blocker) {
                $io->error($blocker);
            }
            if ($report['blockers'] === []) {
                $io->success($dry ? ($report['data_scanned'] ? 'Source value scan passed. No database data changed.' : 'Structural plan checked. No data changed; values not scanned.') : 'Rekey committed after full validation. Switch the application separately.');
            }
        }

        return $report['blockers'] === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function warnBeforeWrite(OutputInterface $output, string $format): void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        } elseif ($format === 'json') {
            $stream = fopen('php://stderr', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Cannot display rekey warning on stderr.');
            }
            $output = new StreamOutput($stream, $output->getVerbosity(), false);
        }
        $output->writeln([
            '[WARNING] --schema-rekey performs real writes and commits verified data to the planned PostgreSQL target.',
            'Stop source writes and target application/workers before proceeding.',
            'Rekey holds a GLOBAL MySQL read lock across the entire source instance during the transfer.',
            'Keep source writes stopped after the command exits until manual application cutover is complete.',
        ], OutputInterface::VERBOSITY_QUIET);
    }

    private function checkEmpty(Connection $target, array $plan): void
    {
        foreach ($plan['tables'] as $name => $table) {
            if ($target->fetchOne('SELECT 1 FROM '.$table['target'].' LIMIT 1') !== false) {
                throw new \RuntimeException($name.': target is not empty.');
            }
        }
    }
}
