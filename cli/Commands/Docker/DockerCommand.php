<?php

namespace App\Commands\Docker;

use App\Commands\BaseCommand;
use App\ConsoleStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;


class DockerCommand extends BaseCommand
{
    protected $composeNames;
    protected $composeFiles;
    protected $ignoreTineConfig;
    protected $skipBranchOption = false;
    protected $tablePrefix = null;
    protected $homeDir = null;
    protected array $composeCommand = ['docker', 'compose'];
    protected string $bchub_repo = 'https://github.com/tine-groupware/broadcasthub.git';
    protected string $elementWebRepo = 'https://github.com/ecclesias-de/element-web/';

    protected static $imageMap = [
        '2021.11' => [
            'web' => 'dockerregistry.metaways.net/tine20/tine20/dev:2021.11-7.4',
            'webpack' => 'dockerregistry.metaways.net/tine20/tine20/node:2022.11', // should be the same setup
        ],
        '2022.11' => [
            'web' => 'tinegroupware/dev:2022.11-8.0',
            'webpack' => 'dockerregistry.metaways.net/tine20/tine20/node:2022.11',
        ],
        '2023.11' => [
            'web' => 'tinegroupware/dev:2023.11-8.1',
            'webpack' => 'ghcr.io/tine-groupware/tine-dev/node:18.9.0-mw1',
        ],
        '2024.11' => [
            'web' => 'tinegroupware/dev:2024.11-8.3',
            'webpack' => 'ghcr.io/tine-groupware/tine-dev/node:18.9.0-mw1',
        ],
        '2025.11' => [
            'web' => 'tinegroupware/dev:main-8.3',
            'webpack' => 'ghcr.io/tine-groupware/tine-dev/node:18.9.0-mw1',
        ],
        '2026.11' => [
            'web' => 'tinegroupware/dev:main-8.5',
            'webpack' => 'ghcr.io/tine-groupware/tine-dev/node:26.1.0-mw1',
        ],
        // repo https://github.com/tine-groupware/tine
        'main' => [
            'web' => 'tinegroupware/dev:main-8.5',
            'webpack' => 'ghcr.io/tine-groupware/tine-dev/node:26.1.0-mw1',
        ],
        'mw' => [
            'web' => 'tinegroupware/dev:main-8.4',
            'webpack' => 'ghcr.io/tine-groupware/tine-dev/node:26.1.0-mw1',
        ],
        'metaways/main' => [
            'web' => 'tinegroupware/dev:main-8.4',
            'webpack' => 'ghcr.io/tine-groupware/tine-dev/node:26.1.0-mw1',
        ],
        'erzbistum/main' => [
            'web' => 'tinegroupware/dev:main-8.3',
            'webpack' => 'ghcr.io/tine-groupware/tine-dev/node:26.1.0-mw1',
        ],
    ];

    protected function configure()
    {
        parent::configure();

        if (isset($this->config['tine20']['tableprefix']) && strlen($this->config['tine20']['tableprefix']) < 9) {
            $this->tablePrefix = $this->config['tine20']['tableprefix'];
        }

        if (!$this->skipBranchOption) {
            $this->addOption(
                'branch',
                'b',
                InputArgument::OPTIONAL,
                'tine branch you want to run (e.g. --branch 2026.11, default: current branch)'
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new ConsoleStyle($input, $output);

        $dockerPath = shell_exec('which docker 2>/dev/null');
        if ($dockerPath === null || trim($dockerPath) === '') {
            $io->error('docker not found in PATH');
            exit(1);
        }

        parent::execute($input, $output);

        $this->initCompose($io);
    }

    public static function getImages($branch)
    {
        $major = basename($branch );
        if (array_key_exists($major, self::$imageMap)) {
            return self::$imageMap[$major];
        } else {
            return end(self::$imageMap);
        }
    }

    public function getBroadcasthubDir(ConsoleStyle $io)
    {
        if (($this->active('broadcasthub') || $this->active('broadcasthub-dev')) && ! is_file('broadcasthub/package.json')) {
            $input = $io->choice('broadcasthub dir is not linked. Should it be cloned and installed?', ['yes', 'no', 'ignore'], 'yes');

            switch($input) {
                case 'yes':
                    system('git clone ' . $this->bchub_repo . ' broadcasthub 2>&1');
                    $output = system('cd broadcasthub && npm install');

                    $io->notice($output);
                    break;

                case 'no':
                    $io->notice('link broadcasthub dir: ln -s /path/to/broadcasthub/repo broadcasthub');
                    exit;

                case 'ignore':
                    break;
            }
        }
    }

    public function getElementIntegrationDir(ConsoleStyle $io)
    {
        if ($this->active('matrix-element-dev') && ! is_file('element-web/package.json')) {
            $input = $io->choice('element-web dir is not linked. Should it be cloned and installed?', ['yes', 'no', 'ignore'], 'yes');

            switch($input) {
                case 'yes':
                    system('git clone --branch tine-integration ' . $this->elementWebRepo . ' element-web 2>&1');
                    break;

                case 'no':
                    $io->notice('link element-web dir: ln -s /path/to/element-web/repo element-web');
                    exit;

                case 'ignore':
                    break;
            }
        }
    }

    public function active($composeName)
    {
        return in_array($composeName, $this->composeNames);
    }

    public function initCompose(ConsoleStyle $io)
    {
        $conf = $this->getComposeConf();
        $this->composeCommand = in_array('mutagen', $conf['composeFiles']) ? ['mutagen-compose'] : $this->composeCommand;
        $this->composeFiles = ['docker-compose.yml'];
        if (file_exists('.env')) {
            $this->composeFiles[] = 'compose/env.yml';
        }
        $this->composeNames = [];
        $this->ignoreTineConfig = array_key_exists('ignoreConfig', $conf) && $conf['ignoreConfig'];

        if (array_key_exists('composeFiles', $conf)) {
            foreach ($conf['composeFiles'] as $compose) {
                $filename = 'compose/' . $compose . '.yml';
                if (file_exists($filename)) {
                    $this->composeNames[] = $compose;
                    $this->composeFiles[] = $filename;
                } else {
                    $io->error("pullup.json declares '$compose', but '$filename' file is missing");
                    exit();
                }
            }
        }

        if (in_array('build', $this->composeNames) && in_array('src-volumes', $this->composeNames)) {
            $io->error('pullup.json should not contain "build" and "src-volumes" at the same time');
            exit();
        }

        if (in_array('build', $this->composeNames) && $this->composeNames[0] !== 'build') {
            $io->error('"build" should be the first compose file in pullup.json');
            exit();
        }

        if (in_array('src-volumes', $this->composeNames) && $this->composeNames[0] !== 'src-volumes') {
            $io->error('"src-volumes" should be the first compose file in pullup.json');
            exit();
        }

        if (!in_array('build', $this->composeNames) && !in_array('src-volumes', $this->composeNames)) {
            // Silently add src-volumes.yml and put it in front
            $compose = 'src-volumes';
            $filename = 'compose/' . $compose . '.yml';
            if (file_exists($filename)) {
                // add at index 0
                array_unshift($this->composeNames, $compose);
                // index 0 is docker-compose.yml, so add at index 1
                array_splice($this->composeFiles, 1, 0, [$filename]);
            } else {
                $io->error("'$filename' file is missing");
                exit();
            }
        }

        $this->getComposeEnv();
    }

    public function anotherConfig(ConsoleStyle $io)
    {
        if (is_file($this->getTineDir($io) . '/config.inc.php')) {
            if ($this->ignoreTineConfig) {
                return;
            }

            $input = $io->choice('found a config.inc.php in your tine dir, this could cause trouble. Should it be removed?', ['yes', 'no', 'ignore']);

            switch($input) {
                case 'yes':
                    unlink($this->getTineDir($io) . '/config.inc.php');
                    $io->success('config.inc.php removed');
                    break;
                
                case 'no':
                    exit(1);

                case 'ignore':
                    $this->updateConfig($io, ['ignoreConfig' => true]);
                    break;
            }
        }
    }

    public function getComposeString()
    {
        $env = '';
        foreach ($this->getComposeEnv() as $k => $v) {
            $env .= "{$k}={$v} ";
        }

        return $env . join(' ', $this->composeCommand) . ' -f ' . join(' -f ', $this->composeFiles);
    }

    public function getComposeArray(): array
    {
        $cmd = $this->composeCommand;
        foreach ($this->composeFiles as $file) {
            $cmd[] = '-f';
            $cmd[] = $file;
        }
        return $cmd;
    }

    public function getComposeEnv(): array
    {
        static $env = [];
        if (empty($env)) {
            foreach (self::getImages($this->branch) as $service => $image) {
                $var = strtoupper($service) . '_IMAGE';
                $arch = stristr($image, 'dockerregistry.metaways.net') && stristr(shell_exec('uname -a'), 'arm64') ? '-arm64' : '';

                $env[$var] = "{$image}{$arch}";
            }
            $homeDir = rtrim(trim(shell_exec('realpath ~/')), '/');
            if (empty($homeDir) || !preg_match('#^/..+#', $homeDir)) {
                exit('home dir discovery failed');
            }
            shell_exec("touch $homeDir/.dockertine20web_bash_history");
            shell_exec("touch $homeDir/.dockertine20web_ash_history");
            $this->homeDir = $homeDir;
            $env['HOMEDIR'] = $homeDir;

            $env['IMAGE_SUFFIX'] = stristr(shell_exec('uname -a'), 'arm64') ? '-arm64' : '';
            $env['TINE20_DATABASE_TABLEPREFIX'] = $this->_getTablePrefix();

            $conf = $this->getComposeConf();
            if (isset($conf['builtImage'])) {
                $env['BUILT_IMAGE'] = $conf['builtImage'];
            }
        }
        return $env;
    }

    protected function _getTablePrefix(): string
    {
        return $this->tablePrefix ?? substr(str_replace(['.', '/'], '', $this->branch),0,7) . '_';
    }

    public function updateConfig(ConsoleStyle $io, $updates)
    {
        $conf = array_merge($this->getComposeConf(), $updates);
        $f = fopen($this->baseDir . '/pullup.json', 'w+');
        fwrite($f, json_encode($conf, JSON_PRETTY_PRINT));
        fclose($f);

        $this->initCompose($io);
    }

    public function getComposeConf(): array
    {
        $json = [];
        if (is_file($this->baseDir . '/pullup.json')) {
            $json = json_decode(file_get_contents($this->baseDir . '/pullup.json'), true);
            if (is_null($json)) {
                exit('[ERROR] "pullup.json" contains errors' . PHP_EOL . PHP_EOL);
            }
        } elseif (is_file($this->baseDir . '/.pullup.json')) {
            $json = json_decode(file_get_contents($this->baseDir . '/.pullup.json'), true);
            if (is_null($json)) {
                exit('[ERROR] ".pullup.json" contains errors' . PHP_EOL . PHP_EOL);
            }
        }

        return $json;
    }
}
