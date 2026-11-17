<?php

namespace App\Commands\Docker;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\ConsoleStyle;

class DockerWebpackRestartCommand extends DockerCommand
{

    protected function configure() {
        $this
            ->setName('docker:webpackrestart')
            ->setDescription('restart webpack. needed after large js changes e.g. other apps')
            ->setHelp('')
        ;

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $io = new ConsoleStyle($input, $output);

        $this->assertSafeMounts($io);

        // NOTE: we need to support node version (container) change
        passthru($this->getComposeString() . " stop webpack", $result_code);
        passthru($this->getComposeString() . " rm -f", $result_code);

        $io->infoWithTime('Restarting containers ...');

        passthru($this->getComposeString() . " up -d" . $this->upServices(), $result_code);
        // NOTE: `tail -1` as mutagen outputs volume status with ps
        $webRunning = function () {
            $webContainerId = trim((string)shell_exec("{$this->getComposeString()} ps -q web | tail -1"));
            // no web container (e.g. up failed): not running
            return $webContainerId !== '' && trim((string)shell_exec('docker ps -q --no-trunc --filter '
                . escapeshellarg("id=$webContainerId"))) !== '';
        };

        $tries = 0;
        // give web time to fail
        sleep(1);
        while (++$tries < 20 && !$webRunning()) {
            $io->info('waiting for web service to be available...');
            // give webpack time to finish compiling
            sleep(2);
            passthru($this->getComposeString() . " up -d" . $this->upServices(), $result_code);
            // give web time to fail
            sleep(1);
        }

        return $tries < 20 ? 0 : 1;
    }
}
