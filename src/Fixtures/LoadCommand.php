<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Fixtures;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LoadCommand extends Command
{
    public function __construct(private readonly Runner $runner, private readonly string $environment)
    {
        parent::__construct('modernorm:fixtures:load');
    }

    protected function configure(): void
    {
        $this->setDescription('Load ModernORM fixture services into their managed PostgreSQL tables.')
            ->addOption('append', null, InputOption::VALUE_NONE, 'Append instead of truncating all mapped ModernORM tables.')
            ->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Fixture groups or fixture class names.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow fixture loading in production.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('prod' === $this->environment && !$input->getOption('force')) {
            throw new \LogicException('Production fixtures require --force.');
        }
        $this->runner->select($input->getOption('group'));
        $io = new SymfonyStyle($input, $output);
        if (!$input->getOption('append') && $input->isInteractive() && !$io->confirm('Purge all mapped ModernORM tables and load fixtures?', false)) {
            return self::SUCCESS;
        }
        $this->runner->load($input->getOption('group'), $input->getOption('append'));
        $io->success('ModernORM fixtures loaded.');

        return self::SUCCESS;
    }
}
