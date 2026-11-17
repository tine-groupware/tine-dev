<?php

namespace App\Commands\Src;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\ConsoleStyle;
use App\Commands\Docker\DockerCommand;

class NpmCommand extends DockerCommand
{
    protected function configure() {
        $this
            ->setName('src:npm')
            ->setDescription('run npm command')
            ->setHelp('')
            ->addArgument(
                'cmd',
                InputArgument::REQUIRED,
                'npm cmd to execute, like "install --save emoji-regex"')
        ;

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $io = new ConsoleStyle($input, $output);

        $branch = $this->branch;
        if (isset($this->config['tine20']['npminstall']['branchmatrix'][$branch])) {
            $branch = $this->config['tine20']['npminstall']['branchmatrix'][$branch];
        }

        $env = $this->getComposeEnv();

        $localCacheDir = $this->cacheDir('npm-cache');

        // NOTE: we can't use getComposeCommand here as mutagen has a ro filesystem and even if we skip mutagen here
        //       it runs in the existing node container with ro filesystem (well we could kill the node-container but
        //       this tradeoff seems to big
        passthru("docker run --rm \
            --user " . self::hostUser() . " \
            -p 9229:9229 \
            -v $localCacheDir:/.npm \
            {$this->srcMount($io, 'tine20', '/usr/share/tine20')} \
            {$this->srcMount($io, 'tests', '/usr/share/tests')} \
            {$env['WEBPACK_IMAGE']} \
            sh -c 'cd /usr/share/tine20/Tinebase/js && npm {$input->getArgument('cmd')}'", $result_code); // --loglevel verbose
        return $result_code;
    }
}
