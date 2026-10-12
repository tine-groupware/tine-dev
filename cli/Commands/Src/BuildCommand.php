<?php

namespace App\Commands\Src;

use App\ConsoleStyle;
use App\Commands\Docker\DockerCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// TODO: Create a separate database for built image?
// TODO: Add dynamic --target argument instead of hard-coded '--target built'?

class BuildCommand extends DockerCommand
{
    protected const DEFAULT_TAG = 'tinegroupware/built:local';

    protected function configure()
    {
        $this
            ->setName('src:build')
            ->setDescription('build the production image (CI Dockerfile, target "built") locally')
            ->setHelp(<<<HELP
Builds the production image from the CI Dockerfile (target "built") in the linked
tine repo, so the production environment can be simulated locally.

The resulting image contains the complete runtime (nginx, php-fpm, supervisor, cron)
with the release app (compiled JS bundles, compiled translations, RELEASE mode)
baked into /usr/share/tine20.

After a successful build, add "build" (and remove "webpack" and "xdebug") to
composeFiles in pullup.json, then run ./console docker:up.
A custom --tag is persisted to pullup.json automatically.

Examples:
  ./console src:build
  ./console src:build --tag tinegroupware/built:mytest --release 2026.11.1
HELP
            )
            ->addOption('tag', 't', InputOption::VALUE_OPTIONAL, 'image tag (default: ' . self::DEFAULT_TAG . ')', null)
            ->addOption('php-version', null, InputOption::VALUE_OPTIONAL, 'PHP version (default: derived from branch image map)', null)
            ->addOption('codename', 'c', InputOption::VALUE_OPTIONAL, 'CODENAME build-arg (default: current branch)', null)
            ->addOption('revision', 'r', InputOption::VALUE_OPTIONAL, 'REVISION build-arg (default: short git HEAD)', null)
            ->addOption('release', null, InputOption::VALUE_OPTIONAL, 'RELEASE build-arg (default: local)', 'local');

        // To avoid confusion, we skip adding the --branch option, since there is no branch argument included in the docker command.
        $this->skipBranchOption = true;

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $io = new ConsoleStyle($input, $output);

        $repoDir = $this->srcDir;
        if (!is_file("$repoDir/ci/dockerimage/Dockerfile")) {
            $io->error("CI Dockerfile not found: $repoDir/ci/dockerimage/Dockerfile (tine repo too old?)");
            return 1;
        }
        if (!is_dir("$repoDir/.git")) {
            $io->error('.git dir required (icon-set submodule stage); use a git clone, not a deployed package');
            return 1;
        }

        $env = $this->getComposeEnv();
        $tag = (string)($input->getOption('tag') ?? self::DEFAULT_TAG);
        $phpVersion = (string)($input->getOption('php-version') ?? $this->detectPhpVersion($env['WEB_IMAGE']));
        $codename = (string)($input->getOption('codename') ?? $this->branch);
        $revision = trim((string)($input->getOption('revision')
            ?? shell_exec("git -C $repoDir rev-parse --short HEAD 2>/dev/null")));
        if ($revision === '') {
            $revision = 'unknown';
        }
        $release = (string)$input->getOption('release');

        $io->section('src:build');
        $io->table(['Key', 'Value'], [
            ['Context', $repoDir],
            ['Target', 'built'],
            ['Tag', $tag],
            ['PHP_IMAGE', 'php' . $phpVersion],
            ['RELEASE', $release],
            ['CODENAME', $codename],
            ['REVISION', $revision],
        ]);

        $cmd = 'cd ' . escapeshellarg($repoDir) . ' && docker build'
            . ' -f ci/dockerimage/Dockerfile'
            . ' --target built'
            . ' --build-arg PHP_IMAGE=php' . $phpVersion
            . ' --build-arg RELEASE=' . escapeshellarg($release)
            . ' --build-arg CODENAME=' . escapeshellarg($codename)
            . ' --build-arg REVISION=' . escapeshellarg($revision)
            . ' --tag ' . escapeshellarg($tag)
            . ' . && cd -';

        $io->infoWithTime("Running: $cmd");
        passthru($cmd, $result);
        if (0 !== $result) {
            $io->error("docker build failed with code $result");
            return $result;
        }

        $io->success("Built image: $tag");
        $this->updateConfig($io, ['builtImage' => $tag]);

        if (!in_array('build', $this->composeNames)) {
            $io->comment('next: add "build" (and remove "webpack" and "xdebug") to composeFiles in pullup.json, then run ./console docker:up');
        }

        // TODO: Install Tine here? -> see compose/build.yml -> TINE20_INSTALL variable.

        return 0;
    }

    /**
     * Derive the PHP version from the branch web image tag, e.g. tinegroupware/dev:main-8.3 -> 8.3
     */
    protected function detectPhpVersion($webImage): string
    {
        if (preg_match('/-(\d+\.\d+)$/', (string)$webImage, $m)) {
            return $m[1];
        }
        return '8.4';
    }
}