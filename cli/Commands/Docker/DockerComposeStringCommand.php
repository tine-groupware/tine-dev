<?php

namespace App\Commands\Docker;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DockerComposeStringCommand extends DockerCommand
{
    protected function configure()
    {
        parent::configure();
        
        $this
            ->setName('docker:composestring')
            ->setDescription('todo')
            ->setHelp('')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        echo($this->getComposeString());

        return 0;
    }
}

