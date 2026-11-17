<?php

namespace App\Commands\Src;

use \Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Commands\Docker\DockerCommand;
use App\Commands\Docker\DockerWebpackRestartCommand;
use App\Commands\Tine\TineClearCacheCommand;
use App\ConsoleStyle;

class ChangeBranchCommand extends DockerCommand {

    protected function configure()
    {
        parent::configure();

        $this->setName('src:changeBranch')
            ->setDescription('change dev branch in a running dev system')
            ->setHelp('')
        ;
        $this->addOption(
            'git',
            'g',
            InputOption::VALUE_NONE,
            'run git checkout BRANCH (requires --branch option), submodules are updated anyway'
        );
        $this->addOption(
            'nonpm',
            null,
            InputOption::VALUE_NONE,
            'skip npm install'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $io = new ConsoleStyle($input, $output);

        if ($input->getOption('git')) {
            if (! $input->getOption('branch')) {
                $io->error("use --branch BRANCH to select a branch");
                return 1;
            }
            $io->infoWithTime("git checkout {$this->branch}");

            passthru(self::git($this->srcDir) . 'checkout ' . escapeshellarg($this->branch), $result_code);
            if (0 !== $result_code) {
                $io->error("git checkout {$this->branch} failed");
                return 1;
            }
        }

        // also after a checkout without --git (by hand, or by an AI client which can't write .git/modules): the
        // submodules (icon-set) have to match the branch
        // sync first: the url of a submodule can change between branches (icon-set moved from
        // git@github.com:tine20 to https://github.com/tine-groupware), update uses the url of the existing clone
        $io->infoWithTime('git submodule sync && git submodule update');
        passthru(self::git($this->srcDir) . 'submodule sync --recursive && '
            . self::git($this->srcDir) . 'submodule update --init', $result_code);
        if (0 !== $result_code) {
            $io->error("git submodule update failed");
            return 1;
        }

        // stop tine/web container to make sure DB PREFIX is correct & tine20_install is executed
        // restart/up is done in DockerWebpackRestartCommand

        $io->info('Stopping web container ...');
        $composeString = $this->getComposeString();
        passthru($composeString . " stop web");

        $io->infoWithTime('Running composer install ...');
        $composerCmd = new ComposerCommand();
        $a = new ArrayInput([]);
        $a->bind($composerCmd->getDefinition());
        $a->setArgument('cmd', 'install');
        // failing steps don't stop the others (the containers have to be started again), but the exit code
        $failed = [];
        if (0 !== ($composerCmd)->execute($a, $output)) {
            $failed[] = 'composer install';
        }

        if (! $input->getOption('nonpm') && 0 !== (new NpmInstallCommand())->execute($input, $output)) {
            $failed[] = 'npm install';
        }
        if (0 !== (new DockerWebpackRestartCommand())->execute($input, $output)) {
            $failed[] = 'webpack restart';
        }
        if (0 !== (new TineClearCacheCommand())->execute($input, $output)) {
            $failed[] = 'clear cache';
        }

        if ($failed) {
            $io->error('changeBranch failed: ' . implode(', ', $failed));
            return 1;
        }
        return 0;
    }
}
