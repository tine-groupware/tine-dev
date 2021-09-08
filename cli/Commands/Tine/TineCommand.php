<?php

namespace App\Commands\Tine;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\ConsoleStyle;

class TineCommand extends Command{
    
    private static $instance;

    private $composeNames = [];
    private $composeFiles;
    private $ignoreTineConfig;

    public function getComposeString() {
        return 'docker-compose -f ' . join(' -f ', $this->composeFiles);
    }

    public function active($composeName) {
        return in_array($composeName, $this->composeNames);
    }

    public function initCompose() {
        if (is_file('pullup.json')) {
            $conf = json_decode(file_get_contents('pullup.json'), true);
        } else {
            $conf = json_decode(file_get_contents('.pullup.json'), true);
        }

        $this->composeFiles = ['docker-compose.yml'];
        $this->composeNames = [];
        $this->ignoreTineConfig = array_key_exists('ignoreConfig', $conf) and $conf['ignoreConfig'];

        if (array_key_exists('composeFiles', $conf)) {
            foreach ($conf['composeFiles'] as $compose) {
                $filename = 'compose/' . $compose . '.yml';
                if (file_exists($filename)) {
                    $this->composeNames[] = $compose;
                    $this->composeFiles[] = $filename;
                } else {
                    echo "$compose unknown";
                }
            }
        }
    }

    public function mailstackInit($io) {
        $out = system($this->getComposeString() . ' run mailstack init');

        if ('' == $out) {
            $io->success("mailstack init successful");
            return 0;
        }
        return 1;
    }

    public function mailstackReset($io) {
        $out = system($this->getComposeString() . ' run mailstack reset');

        if ('' == $out) {
            $io->success("mailstack reset successful");
            return 0;
        }
        return 1;
    }

    public function setupCli($cmd) {
        passthru($this->getComposeString() . ' exec --user tine20 web sh -c "cd /usr/share/tine20/ && php setup.php --config /etc/tine20/config.inc.php ' . $cmd . '"', $err);

        return $err;
    }

    public function tineCli($cmd) {
        passthru($this->getComposeString() . ' exec --user tine20 web sh -c "cd /usr/share/tine20/ && php tine20.php --config /etc/tine20/config.inc.php ' . $cmd . '"', $err);

        return $err;
    }
}
