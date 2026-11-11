<?php

namespace App\Commands\Docker;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use App\ConsoleStyle;

const MICRO_VM_CERT_NAME = '/var/run/secrets/opencode-instance/service/tls';

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
        $this->getElementIntegrationDir($io);
        $this->anotherConfig($io);

        $this->set_up_tls_certs($io);

        // composer install, npm install and icon-set init are getting baked into the image during build mode, so skip if applicable.
        if (!$this->active('build')) {
            $io->infoWithTime('Init vendor: ' . $tinedir . '/vendor');
            passthru('./console src:composer install');

            if (in_array('compose/webpack.yml', $this->composeFiles)
                && !is_dir($tinedir . '/Tinebase/js/node_modules')
            ) {
                $io->infoWithTime('Init node_modules: ' . $tinedir . '/Tinebase/js/node_modules');
                passthru('./console src:npminstall', $npminstallResultCode);
                if (0 !== $npminstallResultCode) {
                    $io->error("src:npminstall failed with code $npminstallResultCode, not starting containers");
                    return $npminstallResultCode;
                }
            }

            $this->ensureIconSet($io);
        }

        if (!empty($inputContainer)) {
            $this->updateConfig($io, ['composeFiles' => $inputContainer]);
        }

        $this->assertSafeMounts($io);

        $io->infoWithTime('Starting containers ...');

        $cmd = $this->getComposeString() . ' up --remove-orphans' . ($input->getOption('detached') === true ? ' -d' : '') . $this->upServices();

        $io->infoWithTime("Running: $cmd");
        passthru($cmd, $result_code);

        // in the foreground (without -d) up only returns when the containers are stopped
        if (0 === $result_code && $input->getOption('detached') === true) {
            $this->prepareAiLogs($io);
        }

        return $result_code;
    }

    /**
     * Make sure the icon-set submodule is checked out at the commit recorded in HEAD.
     * If a plain update does not get there (e.g. corrupted module dir in .git/modules
     * or a stale index), remove worktree and module dir and re-clone.
     */
    protected function ensureIconSet(ConsoleStyle $io)
    {
        $subPath = 'tine20/images/icon-set';
        // at the repo root: resolves the tine20 symlink to the canonical path and a (planted) nested repo
        // in a sub dir would otherwise be picked up
        $repoRoot = trim((string)shell_exec(self::git($this->srcDir) . 'rev-parse --show-toplevel 2>/dev/null'));

        $want = trim((string)shell_exec(self::git($repoRoot) . 'rev-parse --verify HEAD:' . $subPath . ' 2>/dev/null'));
        if ($want === '') {
            return; // this branch has no icon-set gitlink
        }

        $have = trim((string)shell_exec(self::git($repoRoot . '/' . $subPath) . 'rev-parse --verify HEAD 2>/dev/null'));
        if ($have === $want) {
            return; // in sync
        }

        $io->infoWithTime('Init icon-set: ' . $repoRoot . '/' . $subPath . ' (commit bump, want ' . substr($want, 0, 7) . ')');
        passthru(self::git($repoRoot) . 'reset -q -- ' . $subPath . ' && ' . self::git($repoRoot) . 'submodule update --init ' . $subPath);

        $have = trim((string)shell_exec(self::git($repoRoot . '/' . $subPath) . 'rev-parse --verify HEAD 2>/dev/null'));
        if ($have === $want) {
            return;
        }

        $io->warning('icon-set update failed, resetting submodule (remove worktree + module dir, re-clone) ...');
        passthru('rm -rf ' . escapeshellarg($repoRoot . '/' . $subPath) . ' ' . escapeshellarg($repoRoot . '/.git/modules/' . $subPath));
        passthru(self::git($repoRoot) . 'submodule update --init ' . $subPath);
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

        if (is_file(MICRO_VM_CERT_NAME . '.crt') && is_file(MICRO_VM_CERT_NAME . '.key')) {
            # files need to be copied. symlinks cant be mounted. 
            if (!copy(MICRO_VM_CERT_NAME.'.crt', "{$certFolder}microvm.fullchain.pem")) {
                $io->error('failed to copy microvm crt');
            }
            if (!copy(MICRO_VM_CERT_NAME.'.key', "{$certFolder}microvm.privkey.pem")) {
                $io->error('failed to copy microvm key');
            }

            $io->info('found microvm tls certificate');
            return "microvm.";
        }

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
