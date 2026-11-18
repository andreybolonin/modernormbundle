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
use Symfony\Component\Console\Style\SymfonyStyle;

final class TransferCommand extends Command
{
    public function __construct(private readonly Configuration $configuration)
    {
        parent::__construct('modernorm:data:transfer');
    }

    protected function configure(): void
    {
        $this->setDescription('Plan a MySQL to PostgreSQL data transfer without writing data.')
            ->addOption('source-url-env', null, InputOption::VALUE_REQUIRED, 'Environment variable holding the MySQL source URL.')
            ->addOption('target-url-env', null, InputOption::VALUE_REQUIRED, 'Environment variable holding the PostgreSQL target URL.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Planned maximum rows per batch.', '1000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect schema and produce a plan; required in this first version.')
            ->addOption('validate-data', null, InputOption::VALUE_NONE, 'Scan every source row in bounded batches and validate target values without writing.')
            ->addOption('max-error-examples', null, InputOption::VALUE_REQUIRED, 'Maximum retained validation examples; scanning and error counting always continue.', '100')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Report format: text or json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('dry-run')) {
            throw new \InvalidArgumentException('This first version only plans transfers. Pass --dry-run; data loading is not implemented.');
        }
        $sourceEnv = $input->getOption('source-url-env');
        $targetEnv = $input->getOption('target-url-env');
        $size = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $format = $input->getOption('format');
        $maxExamples = filter_var($input->getOption('max-error-examples'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_string($sourceEnv) || !is_string($targetEnv) || $size === false || $maxExamples === false || !in_array($format, ['text', 'json'], true)) {
            throw new \InvalidArgumentException('Both connection environment names, a positive batch size, nonnegative max error examples and format text/json are required.');
        }
        $source = EnvironmentConnection::open($sourceEnv, 'pdo_mysql');
        $target = null;
        try {
            $target = EnvironmentConnection::open($targetEnv, 'pdo_pgsql');
            // Enforce read-only planning on both servers, including metadata loading.
            $source->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $source->beginTransaction();
            $target->beginTransaction();
            $target->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $configuration = clone $this->configuration;
            $configuration->setMetadataCache(new ArrayAdapter());
            $manager = new EntityManager($target, $configuration);
            $metadata = $manager->getMetadataFactory()->getAllMetadata();
            $plan = (new Planner())->plan($source, $target, $metadata, $size);
            if ($input->getOption('validate-data')) {
                if ($plan['errors'] !== []) {
                    $plan['validation'] = ['status' => 'skipped_structural_errors', 'rows_scanned' => 0, 'error_count' => 0, 'error_examples' => []];
                } else {
                    $plan['validation'] = (new DataValidator())->validate($source, $target, $plan, $maxExamples);
                    $plan['data_scanned'] = true;
                    if ($plan['validation']['error_count'] > 0) {
                        $plan['errors'][] = sprintf('Data validation found %d invalid values in %d rows.', $plan['validation']['error_count'], $plan['validation']['invalid_rows']);
                    }
                }
            }
        } finally {
            try {
                $this->close($source);
            } finally {
                if ($target !== null) {
                    $this->close($target);
                }
            }
        }
        if ($format === 'json') {
            $output->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('MySQL to PostgreSQL transfer plan');
            foreach ($plan['tables'] as $name => $table) {
                $io->section($table['source'].' -> '.$table['target']);
                $io->text('Entity: '.$table['entity'].'; planned batch size: '.$table['batch_size']);
                $io->table(['Column', 'Planned conversion'], array_map(static fn (array $column): array => [$column['name'], $column['conversion']], $table['columns']));
                if ($table['sequence_after_load'] !== null) {
                    $io->text('After loading: reset owned sequence '.$table['sequence_after_load'].' (not executed).');
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
            }
            $io->note($plan['data_scanned'] ? 'No data written. All planned source rows were scanned for value compatibility. UNIQUE, FK, CHECK constraints, triggers and timezone conversion policies were not validated.' : 'No data written. Row values were not scanned. Use --validate-data after resolving structural blockers.');
            if ($plan['errors'] === []) {
                $io->success($plan['data_scanned'] ? 'Value scan completed without detected errors. Data loading is not implemented.' : 'Structural plan prepared. Per-value checks remain required; data loading is not implemented.');
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
}
