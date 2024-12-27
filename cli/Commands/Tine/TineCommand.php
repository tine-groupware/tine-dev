<?php

namespace App\Commands\Tine;

use App\Commands\Docker\DockerCommand;

class TineCommand extends DockerCommand
{
    public function mailstackInit($io)
    {
        $io->info("Initializing mailstack ...");

        $out = system($this->getComposeString() . ' run --rm mailstack init');

        if ('' == $out) {
            $io->success("mailstack init successful");
            return 0;
        }
        return 1;
    }

    public function mailstackReset($io)
    {
        $io->info("Resetting mailstack ...");

        $out = system($this->getComposeString() . ' run --rm mailstack reset');

        if ('' == $out) {
            $io->success("mailstack reset successful");
            return 0;
        }
        return 1;
    }

    public function setupCli($cmd)
    {
        passthru($this->getComposeString()
            . ' exec --user tine20 web sh -c "cd /usr/share/tine20/ && php setup.php ' . $cmd . '"', $result_code);

        return $result_code;
    }

    public function tineCli($cmd)
    {
        //--config \$TINE20_CONFIG_PATH
        passthru($this->getComposeString()
            . ' exec --user tine20 web sh -c "cd /usr/share/tine20/ && php tine20.php ' . $cmd . '"', $result_code);

        return $result_code;
    }

    public function tineInstall($io, $inputOptions) {       
        if(empty($inputOptions)) {
            if ($this->active('mailstack') || $this->active('mailstack-mac')) {
                $this->mailstackInit($io);
                $this->mailstackReset($io);
            }
    
            passthru($this->getComposeString() . ' exec -T cache sh -c "redis-cli flushall"', $result_code);
            $io->info("Installing tine ...");
            passthru($this->getComposeString() . ' exec -T web tine20_install', $result_code);
        } else {
            passthru($this->getComposeString() . ' exec --user tine20 -T web sh -c "cd tine20 && php setup.php --install "'
                . implode(" ", $inputOptions), $result_code);
        }

        if (0 !== $result_code) {
            $io->error('Install tine failed!');
            return $result_code;
        }

        if (file_exists('tine20/scripts/postInstallDocker.sh')) {
            $io->info("Running postInstallDocker.sh ... ");
            passthru($this->getComposeString()
                . ' exec -T web sh -c "/usr/share/scripts/postInstallDocker.sh"', $result_code);
        }

        if ($this->active('broadcasthub') || $this->active('broadcasthub-dev')) {
            // Key authTokenChanels needs to be set in config,
            // table tine20_auth_token will be created:
            //    'authTokenChanels' => [
            //        'records' => [
            //            'name' => 'broadcasthub'
            //        ],
            //    ],
            passthru($this->getComposeString() . ' exec --user tine20 -T web sh -c "cd tine20 && php setup.php --add_auth_token -- user=tine20admin id=longlongid auth_token=longlongtoken valid_until=' . date('Y-m-d', strtotime('+1 year', time())) . ' channels=broadcasthub"', $result_code);
        }

        return $result_code;
    }

    public function tineUninstall($io, ?array $inputOptions) {
        passthru($this->getComposeString() . ' exec -T --user tine20 web sh -c "cd /usr/share/tine20 && php setup.php --uninstall "'
            . implode(" ", $inputOptions ?? []), $result_code);

        if ($this->active('mailstack') || $this->active('mailstack-mac')) {
            if (empty($inputOptions)) {
                $this->mailstackReset($io);
            } else {
                $io->success("skipping mailstack reset - uninstall options set");
            }
        }
        
        return $result_code;
    }
}

