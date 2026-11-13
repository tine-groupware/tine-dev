<?php

namespace App\Commands\Tine;

use App\Commands\Docker\DockerCommand;

class TineCommand extends DockerCommand
{
    const MAILSTACK_DOVECOT_DB = 'dovecot';
    const MAILSTACK_POSTFIX_DB = 'postfix';

    /**
     * Runs SQL against the db container via the mysql client of the web container.
     *
     * The mailstack used to be managed by a one-off `compose run mailstack ...` container. That
     * needs container create/start/remove permissions, which the docker socket proxy used by
     * AI-agent containers (compose/opencode.yml) intentionally doesn't grant - only exec into web.
     *
     * @param string|null $db      database to use, null for none
     * @param string|null $sqlFile host path piped to stdin
     * @param string|null $query   passed via -e
     */
    protected function webMysql(?string $db, ?string $sqlFile = null, ?string $query = null): int
    {
        $password = getenv('MYSQL_ROOT_PASSWORD') ?: 'root';
        $cmd = $this->getComposeString() . ' exec -T -e MYSQL_PWD=' . escapeshellarg($password)
            . ' web mysql -hdb -uroot';
        if (null !== $query) {
            $cmd .= ' -e ' . escapeshellarg($query);
        }
        if (null !== $db) {
            $cmd .= ' ' . escapeshellarg($db);
        }
        if (null !== $sqlFile) {
            if (!is_readable($sqlFile)) {
                throw new \RuntimeException("mailstack: sql file not readable: $sqlFile");
            }
            $cmd .= ' < ' . escapeshellarg($sqlFile);
        }

        passthru($cmd, $result_code);
        return $result_code;
    }

    /**
     * same as dockerfiles/mailstack/control/scripts/init (createdb + createtables)
     */
    public function mailstackInit($io)
    {
        $io->infoWithTime("Initializing mailstack ...");

        $user = getenv('MYSQL_USER') ?: 'tine20';
        $password = getenv('MYSQL_PASSWORD') ?: 'tine20pw';
        $queries = [
            'CREATE DATABASE IF NOT EXISTS ' . self::MAILSTACK_DOVECOT_DB,
            'CREATE DATABASE IF NOT EXISTS ' . self::MAILSTACK_POSTFIX_DB,
            "CREATE USER IF NOT EXISTS '$user'@'%' IDENTIFIED BY '" . addslashes($password) . "'",
            'GRANT ALL PRIVILEGES ON ' . self::MAILSTACK_DOVECOT_DB . ".* TO '$user'@'%'",
            'GRANT ALL PRIVILEGES ON ' . self::MAILSTACK_POSTFIX_DB . ".* TO '$user'@'%'",
        ];

        if (0 !== $this->webMysql(null, null, implode('; ', $queries)) || 0 !== $this->mailstackCreateTables()) {
            $io->error("mailstack init failed");
            return 1;
        }

        $io->success("mailstack init successful");
        return 0;
    }

    /**
     * same as dockerfiles/mailstack/control/scripts/reset (droptables + createtables)
     */
    public function mailstackReset($io)
    {
        $io->infoWithTime("Resetting mailstack ...");

        $files = 'dockerfiles/mailstack/control/files';
        if (0 !== $this->webMysql(self::MAILSTACK_DOVECOT_DB, "$files/dovecot_clear.sql")
            || 0 !== $this->webMysql(self::MAILSTACK_POSTFIX_DB, "$files/postfix_clear.sql")
            || 0 !== $this->mailstackCreateTables()
        ) {
            $io->error("mailstack reset failed");
            return 1;
        }

        $io->success("mailstack reset successful");
        return 0;
    }

    /**
     * same as dockerfiles/mailstack/control/scripts/createtables
     */
    protected function mailstackCreateTables(): int
    {
        foreach ([
            [self::MAILSTACK_DOVECOT_DB, 'tine20/etc/sql/dovecot_tables.sql'],
            [self::MAILSTACK_POSTFIX_DB, 'tine20/etc/sql/postfix_tables.sql'],
            [self::MAILSTACK_POSTFIX_DB, 'configs/mailstack/scripts/postfix_init_virtual_domains.sql'],
        ] as [$db, $file]) {
            if (0 !== ($result_code = $this->webMysql($db, $file))) {
                return $result_code;
            }
        }
        return 0;
    }

    /**
     * flushes redis via php in the web container (no exec into the cache container needed)
     */
    public function flushCache(): int
    {
        passthru($this->getComposeString() . ' exec -T web php -d xdebug.mode=off -r '
            . escapeshellarg('$r = new Redis(); $r->connect("cache", 6379); exit($r->flushAll() ? 0 : 1);'),
            $result_code);
        return $result_code;
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
    
            $this->flushCache();
            $io->infoWithTime("Installing tine ...");
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
            $io->infoWithTime("Running postInstallDocker.sh ... ");
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

