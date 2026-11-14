<?php

namespace App\Commands\Docker;

use App\ConsoleStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DockerCheckMountsCommand extends DockerCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('docker:checkmounts')
            ->setDescription('create the tine20-src volume and check the compose config for unsafe mounts/options')
            ->setHelp('runs before every docker:up, see DockerCommand::assertSafeMounts()')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $io = new ConsoleStyle($input, $output);

        $this->assertSafeMounts($io);
        $io->success('compose config ok');

        return 0;
    }
}
