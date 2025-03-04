<?php

declare(strict_types=1);

namespace Bolt\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SetupCommand extends Command
{
    protected static $defaultName = 'bolt:setup';

    /** @var Connection */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run Bolt setup / installation commands')
            ->addOption('no-fixtures', 'nf', InputOption::VALUE_NONE, 'If set, no data fixtures will be created and the user will not be prompted for it. An empty database wil be initialised.')
            ->addOption('fixtures', 'f', InputOption::VALUE_NONE, 'If set, data fixtures will be created, without prompting the user for it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = 0;
        $io = new SymfonyStyle($input, $output);

        // Because SQLite breaks on `--if-not-exists`, we need to check for that here.
        // See: https://github.com/doctrine/DoctrineBundle/issues/542
        $options = ['-q' => true];
        if ($this->connection->getDatabasePlatform()->getName() !== 'sqlite') {
            $options[] = ['--if-not-exists' => true];
        }

        $command = $this->getApplication()->find('doctrine:database:create');
        $exitCode += $command->run(new ArrayInput($options), $output);

        $command = $this->getApplication()->find('doctrine:schema:create');
        $exitCode += $command->run(new ArrayInput([]), $output);

        $command = $this->getApplication()->find('bolt:reset-secret');
        $exitCode += $command->run(new ArrayInput([]), $output);

        $command = $this->getApplication()->find('bolt:add-user');
        $commandInput = new ArrayInput(['--admin' => true]);
        $exitCode += $command->run($commandInput, $output);

        // Unless either `--no-fixtures` or `--fixtures` was set, we prompt the user for it.
        if (! $input->getOption('no-fixtures')) {
            if ($input->getOption('fixtures') || $io->confirm('Add fixtures (dummy content) to the Database?', true)) {
                $command = $this->getApplication()->find('doctrine:fixtures:load');
                $commandInput = new ArrayInput(['--append' => true]);
                $exitCode += $command->run($commandInput, $output);
            }
        }

        $io->newLine();

        if ($exitCode !== 0) {
            $io->error('Some errors occurred while setting up Bolt.');
        } else {
            $io->success('Bolt was set up successfully! Start a web server, and open your Bolt site in a browser.');
        }

        return 0;
    }
}
