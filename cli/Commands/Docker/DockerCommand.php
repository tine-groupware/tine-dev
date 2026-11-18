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

    /** volume tine20-src of docker-compose.yml (project tine20), bound to the tine checkout, see checkSrcVolume() */
    public const SRC_VOLUME = 'tine20_tine20-src';
    /** the same for docker run of ./console (src:composer, ...), created by docker run, see srcMount() */
    public const RUN_SRC_VOLUME = 'tine-dev-tine20-src';
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

    /**
     * @param string $branch
     * @param string|null $gitDir tine git checkout, used to find the major branch of branches not in $imageMap
     * @return array
     */
    public static function getImages($branch, ?string $gitDir = null)
    {
        if (array_key_exists($branch, self::$imageMap)) {
            return self::$imageMap[$branch];
        }

        // feature branches (e.g. pu/cw/foo) get the images of the major branch they will be merged into
        $major = $gitDir ? self::findMajorBranch($gitDir, $branch) : null;
        if ($major !== null && array_key_exists($major, self::$imageMap)) {
            fwrite(STDERR, "branch $branch is not in the image map, using images of its major branch $major\n");
            return self::$imageMap[$major];
        }

        // no ci/ci-config.yml (yet)
        if (array_key_exists(basename($branch), self::$imageMap)) {
            return self::$imageMap[basename($branch)];
        }

        return end(self::$imageMap);
    }

    /**
     * The major branch of $branch as the CI uses it: MAJOR_COMMIT_REF_NAME =
     * CUSTOMER_MAJOR_COMMIT_REF_NAME . BASE_MAJOR_COMMIT_REF_NAME from ci/ci-config.yml of the branch,
     * e.g. "2026.11" or "metaways/main".
     *
     * @param string $gitDir
     * @param string $branch
     * @return string|null
     */
    public static function findMajorBranch(string $gitDir, string $branch): ?string
    {
        static $cache = [];
        if (array_key_exists("$gitDir:$branch", $cache)) {
            return $cache["$gitDir:$branch"];
        }

        $git = self::git($gitDir);
        // --branch BRANCH might not be checked out (yet)
        $rev = trim((string)shell_exec($git . 'rev-parse --verify --quiet '
            . escapeshellarg($branch . '^{commit}') . ' 2>/dev/null')) ?: 'HEAD';
        $config = (string)shell_exec($git . 'show ' . escapeshellarg("$rev:ci/ci-config.yml") . ' 2>/dev/null');

        $vars = [];
        foreach (['CUSTOMER_MAJOR_COMMIT_REF_NAME', 'BASE_MAJOR_COMMIT_REF_NAME'] as $var) {
            $vars[$var] = preg_match('/^\s*' . $var . ':\s*["\']?([^"\'\s#]*)/m', $config, $m) ? $m[1] : '';
        }

        $major = $vars['BASE_MAJOR_COMMIT_REF_NAME'] === '' ? null
            : $vars['CUSTOMER_MAJOR_COMMIT_REF_NAME'] . $vars['BASE_MAJOR_COMMIT_REF_NAME'];
        return $cache["$gitDir:$branch"] = $major;
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

    /**
     * Make sure the icon-set submodule is checked out at the commit recorded in HEAD (docker:up, src:changeBranch).
     * The url is synced from .gitmodules first: it can change between branches (icon-set moved from
     * git@github.com:tine20 to https://github.com/tine-groupware) and update / clone use the url in .git/config -
     * without ssh (devctl) the old url fails. If a plain update does not get there (e.g. corrupted module dir in
     * .git/modules or a stale index), remove worktree and module dir and re-clone.
     *
     * @return bool icon-set is at the wanted commit (or the branch has none)
     */
    public function ensureIconSet(ConsoleStyle $io): bool
    {
        $subPath = 'tine20/images/icon-set';
        // at the repo root: resolves the tine20 symlink to the canonical path and a (planted) nested repo
        // in a sub dir would otherwise be picked up
        $repoRoot = trim((string)shell_exec(self::git($this->srcDir) . 'rev-parse --show-toplevel 2>/dev/null'));
        if ($repoRoot === '') {
            $io->error('tine checkout not found: ' . $this->srcDir);
            return false;
        }

        $want = trim((string)shell_exec(self::git($repoRoot) . 'rev-parse --verify HEAD:' . $subPath . ' 2>/dev/null'));
        if ($want === '') {
            return true; // this branch has no icon-set gitlink
        }
        $have = fn() => trim((string)shell_exec(self::git($repoRoot . '/' . $subPath) . 'rev-parse --verify HEAD 2>/dev/null'));
        if ($have() === $want) {
            return true; // in sync
        }

        $io->infoWithTime('Init icon-set: ' . $repoRoot . '/' . $subPath . ' (want ' . substr($want, 0, 7) . ')');
        passthru(self::git($repoRoot) . 'submodule sync -- ' . $subPath . ' && '
            . self::git($repoRoot) . 'reset -q -- ' . $subPath . ' && '
            . self::git($repoRoot) . 'submodule update --init ' . $subPath);
        if ($have() === $want) {
            return true;
        }

        $io->warning('icon-set update failed, resetting submodule (remove worktree + module dir, re-clone) ...');
        passthru('rm -rf ' . escapeshellarg($repoRoot . '/' . $subPath) . ' '
            . escapeshellarg($repoRoot . '/.git/modules/' . $subPath));
        passthru(self::git($repoRoot) . 'submodule update --init ' . $subPath);

        if ($have() !== $want) {
            $io->error('icon-set is not at ' . substr($want, 0, 7) . ' (' . $repoRoot . '/' . $subPath . ')');
            return false;
        }
        return true;
    }

    /**
     * empties the logs for the AI coding clients (compose/aicoding.yml) after the web container was (re)started:
     * they would grow without limit on log level DEBUG. Creates them as tine20: whoever creates a log file first
     * owns it, and tine CLI commands run as root partly (tine20_install, exec), php-fpm runs as tine20.
     */
    public function prepareAiLogs(ConsoleStyle $io): void
    {
        if (!$this->active('aicoding')) {
            return;
        }
        $files = '/var/log/tine20/tine20.jsonl /var/log/tine20/php_errors.log';
        passthru($this->getComposeString() . ' exec -T web sh -c '
            . escapeshellarg("for f in $files; do : > \$f && chown tine20:tine20 \$f; done"), $result_code);
        if (0 !== $result_code) {
            $io->warning('could not prepare the logs for the AI coding clients (web not running?)');
        }
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

        $aiClients = array_filter($this->composeNames, fn($name) => str_starts_with($name, 'aicoding-'));
        if ($aiClients && !in_array('aicoding', $this->composeNames)) {
            $io->error('pullup.json: "' . implode('", "', $aiClients) . '" requires "aicoding" in composeFiles');
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

    /**
     * service list for `compose up`: empty (all services), but without DEVCTL_SKIP_SERVICES (set by devctl):
     * - the container ./console runs in, compose would stop/recreate it in the middle of the operation
     * - the AI clients, their env (e.g. ANTHROPIC_API_KEY) may only be set in the shell of the user, compose in
     *   devctl would recreate them without it
     */
    public function upServices(): string
    {
        $skip = preg_split('/\s+/', trim((string)getenv('DEVCTL_SKIP_SERVICES')), -1, PREG_SPLIT_NO_EMPTY);
        if (!$skip) {
            return '';
        }
        $services = array_filter(explode("\n", trim((string)shell_exec($this->getComposeString() . ' config --services'))));
        return ' ' . implode(' ', array_map('escapeshellarg', array_diff($services, $skip)));
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
            foreach (self::getImages($this->branch, $this->srcDir) as $service => $image) {
                $var = strtoupper($service) . '_IMAGE';
                $arch = stristr($image, 'dockerregistry.metaways.net') && self::isArm64() ? '-arm64' : '';

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

            // devctl (compose/aicoding.yml): host paths and identity
            $env['TINE_DEV_DIR'] = realpath($this->baseDir);
            $env['TINE20_DIR'] = $this->getSrcRoot();
            $env['TINE_DEV_USER'] = self::hostUser();
            $env['TINE_DEV_ARM64'] = self::isArm64() ? '1' : '0';

            $env['IMAGE_SUFFIX'] = self::isArm64() ? '-arm64' : '';
            $env['TINE20_DATABASE_TABLEPREFIX'] = $this->_getTablePrefix();

            $conf = $this->getComposeConf();
            if (isset($conf['builtImage'])) {
                $env['BUILT_IMAGE'] = $conf['builtImage'];
            }
        }
        return $env;
    }

    /**
     * tine checkout (root of SRC_VOLUME), symlinks resolved
     */
    public function getSrcRoot(): string
    {
        $root = realpath($this->srcDir);
        if ($root === false || !is_dir($root)) {
            exit("[ERROR] tine checkout {$this->srcDir} not found" . PHP_EOL);
        }
        return $root;
    }

    /**
     * The volume tine20-src (docker-compose.yml) is bound to the tine checkout (TINE20_DIR), compose creates it.
     *
     * Containers must not bind mount sub paths of the checkout: the checkout is writable for code running in the
     * containers (and AI coding clients), and the docker daemon follows symlinks in bind sources when it starts a
     * container - a symlink e.g. tine20/Tinebase/js/build -> ~/ would mount the host home dir. Mounts of the
     * volume with `volume.subpath` are resolved by the daemon beneath the volume root without following symlinks
     * and without a race (moby safepath), see compose/src-volumes.yml.
     *
     * Neither compose nor docker run recreate an existing volume when TINE20_DIR changes (e.g. another
     * checkout) - refuse to start instead of mounting the old checkout.
     */
    public function checkSrcVolume(ConsoleStyle $io): void
    {
        $root = $this->getSrcRoot();
        foreach ([self::SRC_VOLUME, self::RUN_SRC_VOLUME] as $volume) {
            $device = trim((string)shell_exec("docker volume inspect --format '{{index .Options \"device\"}}' "
                . escapeshellarg($volume) . ' 2>/dev/null'));
            if ($device !== '' && $device !== $root) {
                $io->error("volume $volume points to $device instead of $root, remove it: ./console docker:down"
                    . " && docker volume rm $volume");
                exit(1);
            }
        }
    }

    /**
     * docker run --mount option for $subPath of the tine checkout via RUN_SRC_VOLUME, bound to the checkout like
     * tine20-src (subpath resolved the same way). docker run creates it with these options if it doesn't exist.
     * Not tine20-src itself: compose creates that only on up, src:composer runs before (docker run would create
     * an empty volume of that name), and subpath isn't possible with anonymous volumes.
     */
    public function srcMount(ConsoleStyle $io, string $subPath, string $target, bool $readOnly = false): string
    {
        $this->checkSrcVolume($io);
        $this->ensureSrcDir($io, $subPath);
        // the csv parser of --mount needs quotes around values containing commas
        $device = '"volume-opt=device=' . str_replace('"', '""', $this->getSrcRoot()) . '"';
        return '--mount ' . escapeshellarg('type=volume,src=' . self::RUN_SRC_VOLUME . ",dst=$target,"
            . "volume-driver=local,volume-opt=type=none,volume-opt=o=bind,$device,volume-nocopy"
            . ($subPath === '' ? '' : ",volume-subpath=$subPath") . ($readOnly ? ',readonly' : ''));
    }

    /**
     * creates missing directories of $subPath beneath the tine checkout, refuses to cross symlinks
     */
    protected function ensureSrcDir(ConsoleStyle $io, string $subPath): void
    {
        $path = $this->getSrcRoot();
        foreach (array_filter(explode('/', $subPath), 'strlen') as $part) {
            if ($part === '..') {
                $io->error("invalid sub path $subPath");
                exit(1);
            }
            $path .= "/$part";
            if (is_link($path)) {
                $io->error("$path is a symlink, refusing to mount it");
                exit(1);
            }
            if (!file_exists($path) && !mkdir($path)) {
                $io->error("can't create $path");
                exit(1);
            }
        }
    }

    /**
     * To be called before containers are created/started: checks SRC_VOLUME and the compose config.
     *
     * - bind mounts of the tine checkout are only allowed for the checkout root (see checkSrcVolume)
     * - missing directories of SRC_VOLUME sub paths are created (bind mounts did that on their own)
     * - docker socket, privileged mode, capabilities, devices and host namespaces only for trusted services
     *   (pullup.json "trustedServices", default: traefik, devctl)
     */
    public function assertSafeMounts(ConsoleStyle $io): void
    {
        $this->checkSrcVolume($io);
        if (array_filter($this->composeNames, fn($name) => str_starts_with($name, 'aicoding-'))) {
            $this->ensureGitProtection($io);
        }

        $json = shell_exec($this->getComposeString() . ' config --format json');
        $config = json_decode((string)$json, true);
        if (!is_array($config) || !isset($config['services'])) {
            $io->error('can\'t read compose config');
            exit(1);
        }

        $conf = $this->getComposeConf();
        $trusted = $conf['trustedServices'] ?? ['traefik', 'devctl'];
        $root = $this->getSrcRoot();
        $volumes = $config['volumes'] ?? [];
        $errors = [];

        foreach ($config['services'] as $service => $def) {
            $isTrusted = in_array($service, $trusted, true);

            foreach ($def['volumes'] ?? [] as $mount) {
                $type = $mount['type'] ?? '';
                $source = $mount['source'] ?? '';
                if ($type === 'bind') {
                    $resolved = self::resolvePath($source);
                    if (str_starts_with($resolved, "$root/")) {
                        $errors[] = "$service: bind mount of $source ($resolved) - mount sub paths of the tine checkout"
                            . " via the tine20-src volume (see compose/src-volumes.yml)";
                    }
                    if (!$isTrusted && preg_match('#(^|/)docker\.sock$#', $resolved)) {
                        $errors[] = "$service: docker socket mount, add the service to pullup.json trustedServices";
                    }
                } elseif ($type === 'volume' && ($volumes[$source]['name'] ?? '') === self::SRC_VOLUME) {
                    $subPath = $mount['volume']['subpath'] ?? '';
                    if (!($mount['read_only'] ?? false)) {
                        $this->ensureSrcDir($io, $subPath);
                    }
                }
            }

            if ($isTrusted) {
                continue;
            }
            foreach (['privileged', 'cap_add', 'devices', 'security_opt'] as $key) {
                if (!empty($def[$key])) {
                    $errors[] = "$service: $key, add the service to pullup.json trustedServices";
                }
            }
            foreach (['pid', 'ipc', 'userns_mode', 'network_mode', 'uts', 'cgroup'] as $key) {
                if (($def[$key] ?? '') === 'host') {
                    $errors[] = "$service: $key: host, add the service to pullup.json trustedServices";
                }
            }
        }

        if ($errors) {
            $io->error(array_merge(['unsafe compose config:'], $errors));
            exit(1);
        }
    }

    /**
     * Prepares the tine git dir for the read-only mounts of AI coding clients (see compose/aicoding-claude-code.yml).
     *
     * The clients need a writable .git (commits), but everything in it that makes git run code on the host has to
     * be read-only for them: config (fsmonitor, filters, aliases, ...), hooks, modules (submodule git dirs),
     * worktrees and commondir - git reads config and hooks from $GIT_DIR/commondir if that file exists, so it
     * must exist (pointing to the git dir itself) to be mounted read-only.
     */
    public function ensureGitProtection(ConsoleStyle $io): void
    {
        $gitDir = $this->getSrcRoot() . '/.git';
        if (is_link($gitDir) || !is_dir($gitDir)) {
            $io->error("$gitDir is not a directory");
            exit(1);
        }

        foreach (['hooks', 'modules', 'worktrees'] as $dir) {
            $path = "$gitDir/$dir";
            if (is_link($path) || (!is_dir($path) && !mkdir($path))) {
                $io->error("$path is not a directory");
                exit(1);
            }
        }

        $commonDir = "$gitDir/commondir";
        if (is_link($commonDir) || (file_exists($commonDir) && trim((string)file_get_contents($commonDir)) !== '.')) {
            $io->error("unexpected $commonDir (git reads config and hooks from the dir it names), check and remove it");
            exit(1);
        }
        if (!file_exists($commonDir)) {
            file_put_contents($commonDir, ".\n");
        }
    }

    /**
     * realpath() for paths that might not exist (yet): resolves the longest existing prefix
     */
    public static function resolvePath(string $path): string
    {
        $rest = '';
        while (($real = realpath($path)) === false) {
            $parent = dirname($path);
            if ($parent === $path) {
                return $path . $rest;
            }
            $rest = '/' . basename($path) . $rest;
            $path = $parent;
        }
        return rtrim($real, '/') . $rest;
    }

    protected function _getTablePrefix(): string
    {
        return $this->tablePrefix ?? substr(str_replace(['.', '/', '-'], '', $this->branch),0,7) . '_';
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
