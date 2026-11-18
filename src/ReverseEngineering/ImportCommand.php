<?php

declare(strict_types=1);

namespace ModernORM\Bundle\ReverseEngineering;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ImportCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly string $projectDir)
    {
        parent::__construct('modernorm:mapping:import');
    }

    protected function configure(): void
    {
        $this->setDescription('Generate new attribute entities from an existing PostgreSQL schema.')
            ->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Exact PostgreSQL schema name.', 'public')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Entity namespace.', 'App\\Entity')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory, relative to the project root or absolute.', 'src/Entity')
            ->addOption('table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exact table name; repeat to select multiple tables.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print generated PHP without creating files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $namespace = $input->getOption('namespace');
        $schema = $input->getOption('schema');
        $directory = $input->getOption('output');
        if (!is_string($namespace) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1 || !is_string($schema) || $schema === '' || !is_string($directory) || $directory === '') {
            throw new \InvalidArgumentException('Provide a valid namespace, nonempty schema and output directory.');
        }
        // Let PHP validate reserved namespace segments as well as lexical syntax.
        if (token_get_all('<?php namespace '.$namespace.'; class ImportNamespaceProbe {}', TOKEN_PARSE) === []) {
            throw new \InvalidArgumentException('Invalid namespace.');
        }
        $snapshot = (new SchemaReader($this->connection))->read($schema, $input->getOption('table'));
        $plan = (new EntityGenerator())->generate($snapshot['tables'], $namespace, $schema, $snapshot['sequences']);
        foreach ($plan['files'] as $file => $code) {
            try {
                if (token_get_all($code, TOKEN_PARSE) === []) {
                    throw new \RuntimeException('Empty generated PHP: '.$file);
                }
            } catch (\ParseError $error) {
                throw new \RuntimeException('Generated PHP is invalid for '.$file.': '.$error->getMessage(), 0, $error);
            }
        }
        foreach (array_merge($snapshot['warnings'], $plan['warnings']) as $warning) {
            $io->warning($warning);
        }
        if ($input->getOption('dry-run')) {
            foreach ($plan['files'] as $file => $code) {
                $output->writeln($file, OutputInterface::OUTPUT_RAW);
                $output->writeln($code, OutputInterface::OUTPUT_RAW);
            }
        } else {
            if (!str_starts_with($directory, '/')) {
                $directory = $this->projectDir.'/'.$directory;
            }
            foreach ($plan['files'] as $file => $code) {
                if (file_exists($directory.'/'.$file) || is_link($directory.'/'.$file)) {
                    throw new \RuntimeException('Refusing to overwrite '.$directory.'/'.$file);
                }
            }
            if ($plan['files'] !== [] && !is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Cannot create '.$directory);
            }
            foreach ($plan['files'] as $file => $code) {
                $handle = fopen($directory.'/'.$file, 'x');
                if ($handle === false) {
                    throw new \RuntimeException('Cannot create '.$directory.'/'.$file);
                }
                try {
                    if (fwrite($handle, $code) !== strlen($code)) {
                        throw new \RuntimeException('Incomplete write: '.$file);
                    }
                } finally {
                    fclose($handle);
                }
            }
        }
        $io->success(sprintf('%s %d entities. Review warnings and validate the mapping before use.', $input->getOption('dry-run') ? 'Previewed' : 'Created', count($plan['files'])));

        return self::SUCCESS;
    }
}
