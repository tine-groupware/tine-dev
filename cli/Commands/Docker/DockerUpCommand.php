<?php

namespace App\Commands\Docker;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use App\ConsoleStyle;

class DockerUpCommand extends DockerCommand
{
    
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('docker:up')
            ->setDescription('start docker setup.  pulls/builds images, creates containers, starts containers and shows logs')
            ->setHelp('')
            ->addArgument(
                'container',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'names of additional containers'
            )
            ->addOption(
                'detached',
                'd',
                InputOption::VALUE_NONE,
                'set detached mode'
            )
            ->addOption(
                'default',
                'D',
                InputOption::VALUE_NONE,
                'start docker with the default containers'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $io = new ConsoleStyle($input, $output);

        if ($input->getOption('default') && is_file('pullup.json')) {
            $io->info('default options does nothing, if you want to delete your pullup.json file do it yourself!');
        }

        $inputContainer = $input->getArgument('container');

        $tinedir = $this->getTineDir($io);
        $this->getBroadcasthubDir($io);
        $this->anotherConfig($io);

        $this->set_up_tls_certs($io);

        // TODO improve this / use console commands
        $io->info('Init vendor: ' . $tinedir . '/tine20/vendor');
        passthru('./console src:composer install');

        if (in_array('compose/webpack.yml', $this->composeFiles)
            && ! is_dir($tinedir . '/Tinebase/js/node_modules' )
        ) {
            $io->info('Init node_modules: ' . $tinedir . '/tine20/Tinebase/js/node_modules');
            passthru('./console src:npminstall');
        }
        if (! is_dir($tinedir . '/images/icon-set' )) {
            $io->info('Init icon-set: ' . $tinedir . '/tine20/images/icon-set');
            passthru('cd ' . $tinedir . ' && git submodule init && git submodule update && cd -');
        }

        if (!empty($inputContainer)) {
            $this->updateConfig(['composeFiles' => $inputContainer]);
        }

        $io->info('Starting containers ...');

        passthru($this->getComposeString() . ' up --remove-orphans' .
            ($input->getOption('detached') === true ? ' -d' : ''), $result_code);

        return $result_code;
    }


    protected function set_up_tls_certs(ConsoleStyle $io) {
        $configFile = 'configs/traefik/dynamic/certs.yaml';
        $prefix = $this->ensure_tls_cert($io);

        if (is_file($configFile)) {
            unlink($configFile);
        }

        if ($prefix !== null) {
            file_put_contents($configFile, "
tls:
  certificates:
    - certFile: /etc/traefik/{$prefix}fullchain.pem
      keyFile: /etc/traefik/{$prefix}privkey.pem
            ");
        }
    }

    protected function ensure_tls_cert(ConsoleStyle $io) {
        $certFolder = 'configs/traefik/';
        $letsencryptPrefix = 'letsencrypt.';

        // in case the letsencrypt.privkey.pem.aes file has been updated we need to delete the decrypted privkey
        if (($letsencryptMTime = @filemtime("{$certFolder}{$letsencryptPrefix}privkey.pem.aes")) &&
                ($privKeyMTime = @filemtime("{$certFolder}{$letsencryptPrefix}privkey.pem")) &&
                $letsencryptMTime > $privKeyMTime) {
            $io->info('found outdated letsencrypt tls certificate, deleting');
            unlink("{$certFolder}{$letsencryptPrefix}privkey.pem");
        }

        foreach (['', $letsencryptPrefix] as $prefix) {
            if(is_file("{$certFolder}{$prefix}privkey.pem")) {
                $io->info('found tls certificate');
                return $prefix;
            }
        }

        $result_code = 0;
        $output = null;
        exec('which openssl', $null, $result_code);

        if ($result_code === 0) {
            $output = "";
            exec("cat {$certFolder}{$letsencryptPrefix}privkey.pem.aes | openssl enc -aes-256-cbc -a -d -salt -pbkdf2 -pass pass:well-known 1> {$certFolder}{$letsencryptPrefix}privkey.pem", $output, $result_code);
            $io->debug($output);
            if ($result_code === 0) {
                $io->info('decrypted tls certificate');
                return $letsencryptPrefix;
            }
        } else {
            $io->info('openssl binary not found. Openssl is needed to decrypt the lets encrypt private key');
        }

        return null;
    }
}
