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

        $commands = [
            'openssl req -x509 -newkey rsa:4096 -keyout ca.key -out ca.pem -days 3650 -nodes -subj "/CN=* tine-dev ca"',
            'openssl req -newkey rsa:4096 -out fullchain.csr -sha256 -nodes -subj "/CN=*.local.tine-dev.de" -addext "subjectAltName=DNS:*.local.tine-dev.de,DNS:local.tine-dev.de"',
            'openssl x509 -req -in fullchain.csr -CA ca.pem -CAkey ca.key -out fullchain.pem -sha256 -days 3650 -extfile extfile',
            'which shred && shred ca.key',
            'rm -rf fullchain.csr ca.key'
        ];

        foreach ($commands as $command) {
            passthru('which openssl', $result_code);
            if ($result_code === 0) {
                passthru("cd configs/traefik; {$command}", $result_code);
            } else {
                passthru("docker run --rm --user $(id -u) -v $(pwd)/configs/traefik:/workdir --workdir /workdir --entrypoint sh alpine/openssl -c '{$command}'");
            }
        }

        return $result_code;
    }
}