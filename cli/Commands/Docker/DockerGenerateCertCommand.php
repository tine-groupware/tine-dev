<?php

namespace App\Commands\Docker;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use App\ConsoleStyle;

class DockerGenerateCertCommand extends DockerCommand
{
    
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('docker:generateCert')
            ->setDescription('generate self signed certificate for traefik')
            ->setHelp('')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $io = new ConsoleStyle($input, $output);

        $command = 'req -x509 -newkey rsa:4096 -out fullchain.pem -sha256 -days 3650 -nodes -subj "/CN=*.local.tine-dev.de"';

        passthru('which openssl', $result_code);
        if ($result_code === 0) {
            passthru("cd configs/traefik; openssl {$command}", $result_code);
        } else {
            passthru("docker run --rm --user $(id -u) -v $(pwd)/configs/traefik:/workdir --workdir /workdir alpine/openssl {$command}");
        }

        return $result_code;
    }
}
