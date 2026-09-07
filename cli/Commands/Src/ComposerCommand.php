<?php

namespace App\Commands\Src;

use App\ConsoleStyle;
use App\Commands\Docker\DockerCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ComposerCommand extends DockerCommand
{
    protected function configure()
    {
        $this
            ->setName('src:composer')
            ->setDescription('execute composer in tine20 src context')
            ->setHelp('')
            ->addArgument(
                'cmd',
                InputArgument::REQUIRED,
                'composer cmd to execute, like "require metaways/timezoneconverter"')
        ;

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $io = new ConsoleStyle($input, $output);

        $localCacheDir = trim(shell_exec('composer config cache-dir'));

        shell_exec("mkdir -p $this->baseDir/data/composer");

        $env = $this->getComposeEnv();

        $tineDir = $this->getTineDir($io);

        // NOTE: we use docker run instead of getComposeCommand to avoid mutagen's read-only filesystem.
        //       The GIT_CONFIG env vars set `safe.directory = *` to bypass Git's "dubious ownership" check
        //       on macOS, where Docker volume mounts trigger ownership mismatches between host and container user.
        $cmd = 'docker run --rm --user ' . trim(shell_exec('id -u')) . ':' . trim(shell_exec('id -g')) .
            ' -e GIT_CONFIG_COUNT=1' .
            ' -e GIT_CONFIG_KEY_0=safe.directory' .
            ' -e GIT_CONFIG_VALUE_0=\\*' .
            ' -v ' . $tineDir . ':/usr/share/tine20' .
            ' -v ' . $tineDir . '/../tests:/usr/share/tests' .
            ' -v ' . $this->baseDir . '/data/composer:/.composer' .
            ' -v ' . $localCacheDir . ':/composercache' .
            ' ' . $env['WEB_IMAGE'] . ' sh -c "cd /usr/share/tine20; composer config --global cache-dir /composercache; composer ' . $input->getArgument('cmd') . '"';
        passthru($cmd, $result_code);

        return $result_code;
    }
}
