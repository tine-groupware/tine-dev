<?php
/**
 * devctl - MCP server (streamable HTTP) offering a fixed set of tine-dev operations to AI coding clients.
 *
 * AI coding clients get no docker access at all. devctl has the docker socket and runs ./console of the tine-dev
 * checkout (mounted at its host path, so compose resolves bind sources like on the host), clients can only call
 * the tools below. Run by the PHP built-in server (router script), see Dockerfile and compose/aicoding.yml.
 *
 * Responses of tools/call are sent as SSE stream with keepalive comments: operations like change_branch take
 * minutes, HTTP clients time out on idle connections.
 */

declare(strict_types=1);

const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MAX_OUTPUT = 20000;
const KEEPALIVE = 15;
const CONTAINERS = ['web', 'e2etests'];
const TINE_COMMANDS = ['install', 'uninstall', 'reinstall', 'update', 'demodata', 'clearcache', 'awaitdb', 'test',
    'setuptest'];
const PROJECT = 'tine20';

function tools(): array
{
    $none = ['type' => 'object', 'properties' => new stdClass()];
    return [
        [
            'name' => 'change_branch',
            'description' => 'Apply the branch currently checked out in the tine repo (do the git checkout yourself'
                . ' first): images and db table prefix of the branch, composer install, npm install, restart of the'
                . ' containers, cache clear (./console src:changeBranch). Takes minutes. A branch never installed'
                . ' before needs tine_console install (and demodata) afterwards.',
            'inputSchema' => $none,
        ],
        [
            'name' => 'up',
            'description' => 'Start/recreate the containers of the stack (./console docker:up -d).',
            'inputSchema' => $none,
        ],
        [
            'name' => 'restart_webpack',
            'description' => 'Restart the webpack dev server, needed after large js changes (./console docker:webpackrestart).',
            'inputSchema' => $none,
        ],
        [
            'name' => 'exec',
            'description' => 'Run a shell command (sh -c) in the tine web container (tine source at /usr/share/tine20,'
                . ' e.g. phpunit, tine20.php cli) or in the e2e test runner (tests at /tine20/tests/e2etests, e.g.'
                . ' npm test src/test/...). Returns exit code and output (stdout+stderr, last 20k chars).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'container' => ['type' => 'string', 'enum' => CONTAINERS],
                    'command' => ['type' => 'string'],
                    'workdir' => ['type' => 'string', 'description' => 'working directory in the container'],
                ],
                'required' => ['container', 'command'],
            ],
        ],
        [
            'name' => 'tine_console',
            'description' => 'Run a tine command of ./console (tine:<command>), e.g. install, demodata, clearcache,'
                . ' test (phpunit), with optional arguments/options.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'enum' => TINE_COMMANDS],
                    'args' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['command'],
            ],
        ],
        [
            'name' => 'ps',
            'description' => 'List the containers of the stack with status and image.',
            'inputSchema' => $none,
        ],
        [
            'name' => 'logs',
            'description' => 'Last log lines of a service of the stack (e.g. web, webpack, db, e2etests).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string'],
                    'tail' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 200],
                ],
                'required' => ['service'],
            ],
        ],
        [
            'name' => 'push',
            'description' => 'Push the branch checked out in the tine repo to its remote (DEVCTL_GIT_REMOTE, default origin) - only own branches '
                . 'pu/<developer>/... (developer: DEVCTL_DEVELOPER). Commit first. Returns the git output incl. the '
                . 'link to create a merge request.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'force' => ['type' => 'boolean', 'default' => false,
                        'description' => 'force push with lease (after rebase/amend of the own branch)'],
                ],
            ],
        ],
    ];
}

class ToolError extends Exception
{
}

/**
 * @return string tool output
 */
function callTool(string $name, array $args, callable $keepalive): string
{
    $console = ['./console'];
    switch ($name) {
        case 'change_branch':
            return locked(fn() => run([...$console, 'src:changeBranch', '-n'], $keepalive));
        case 'up':
            return locked(fn() => run([...$console, 'docker:up', '-d', '-n'], $keepalive));
        case 'restart_webpack':
            return locked(fn() => run([...$console, 'docker:webpackrestart', '-n'], $keepalive));
        case 'exec':
            $container = (string)($args['container'] ?? '');
            if (!in_array($container, CONTAINERS, true)) {
                throw new ToolError('container must be one of ' . implode(', ', CONTAINERS));
            }
            $cmd = ['docker', 'exec'];
            if (isset($args['workdir']) && $args['workdir'] !== '') {
                $cmd[] = '-w';
                $cmd[] = (string)$args['workdir'];
            }
            array_push($cmd, containerId($container), 'sh', '-c', (string)($args['command'] ?? ''));
            return run($cmd, $keepalive);
        case 'tine_console':
            $command = (string)($args['command'] ?? '');
            if (!in_array($command, TINE_COMMANDS, true)) {
                throw new ToolError('command must be one of ' . implode(', ', TINE_COMMANDS));
            }
            $extra = $args['args'] ?? [];
            if (!is_array($extra) || array_filter($extra, fn($a) => !is_string($a) || strlen($a) > 1000)) {
                throw new ToolError('args must be a list of strings');
            }
            return run([...$console, "tine:$command", ...array_values($extra), '-n'], $keepalive);
        case 'ps':
            return run(['docker', 'ps', '-a', '--filter', 'label=com.docker.compose.project=' . PROJECT,
                '--format', 'table {{.Label "com.docker.compose.service"}}\t{{.Names}}\t{{.Status}}\t{{.Image}}'],
                $keepalive);
        case 'logs':
            $tail = max(1, min(5000, (int)($args['tail'] ?? 200)));
            return run(['docker', 'logs', '--tail', (string)$tail, containerId((string)($args['service'] ?? ''))],
                $keepalive);
        case 'push':
            return locked(fn() => push((bool)($args['force'] ?? false), $keepalive));
    }
    throw new ToolError("unknown tool $name");
}

/**
 * Pushes the checked out branch of the tine repo to origin with DEVCTL_GIT_TOKEN, which the client never sees.
 *
 * The client controls the checkout but not .git/config and hooks (read-only for it, see
 * compose/aicoding-claude-code.yml): the remote url comes from there and is checked against DEVCTL_GIT_URL_PREFIX,
 * so the token can't be sent elsewhere. Hooks and fsmonitor are disabled anyway. Only pu/<DEVCTL_DEVELOPER>/...,
 * protected branches are protected by gitlab as well.
 */
function push(bool $force, callable $keepalive): string
{
    $token = (string)getenv('DEVCTL_GIT_TOKEN');
    $developer = (string)getenv('DEVCTL_DEVELOPER');
    $urlPrefix = (string)(getenv('DEVCTL_GIT_URL_PREFIX') ?: 'https://gitlab.metaways.net/');
    $remote = (string)(getenv('DEVCTL_GIT_REMOTE') ?: 'origin');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $remote)) {
        throw new ToolError("invalid DEVCTL_GIT_REMOTE '$remote'");
    }
    if ($token === '' || !preg_match('/^[a-z0-9]+$/', $developer)) {
        throw new ToolError('push is not configured: DEVCTL_GIT_TOKEN and DEVCTL_DEVELOPER (e.g. cw) in .env of tine-dev');
    }
    $repo = realpath(getenv('TINE_DEV_DIR') . '/tine20');
    if ($repo === false) {
        throw new ToolError('tine checkout not found');
    }

    $git = ['git', '-c', 'safe.directory=*', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
        '-c', 'credential.helper=', '-c', 'protocol.allow=never', '-c', 'protocol.https.allow=always', '-C', $repo];
    $gitOut = function (array $args) use ($git): string {
        return trim((string)shell_exec(implode(' ', array_map('escapeshellarg', [...$git, ...$args])) . ' 2>/dev/null'));
    };

    $branch = $gitOut(['symbolic-ref', '--short', 'HEAD']);
    $prefix = "pu/$developer/";
    if (!str_starts_with($branch, $prefix) || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $branch)
        || str_contains($branch, '..') || str_ends_with($branch, '.lock') || str_ends_with($branch, '/')) {
        throw new ToolError("only own branches $prefix... can be pushed, checked out: '$branch'");
    }
    $url = $gitOut(['remote', 'get-url', '--push', $remote]);
    if (!str_starts_with($url, $urlPrefix) || preg_match('#^https://[^/]*@#', $url)) {
        throw new ToolError("push url of $remote '$url' doesn't start with $urlPrefix (DEVCTL_GIT_URL_PREFIX)");
    }

    // the token comes from the environment via askpass, never on the command line or in a file
    $askpass = '/tmp/devctl-askpass.sh';
    if (!is_file($askpass)) {
        file_put_contents($askpass, "#!/bin/sh\ncase \"\$1\" in Username*) echo oauth2 ;; *) echo \"\$DEVCTL_GIT_TOKEN\" ;; esac\n");
        chmod($askpass, 0700);
    }
    putenv("GIT_ASKPASS=$askpass");
    putenv('GIT_TERMINAL_PROMPT=0');

    $args = ['push'];
    if ($force) {
        $args[] = "--force-with-lease=refs/heads/$branch";
    }
    // the remote (not the url): --force-with-lease compares with its remote-tracking branch. get-url --push above
    // resolves pushurl and insteadOf like push does.
    array_push($args, $remote, "HEAD:refs/heads/$branch");
    return str_replace($token, '***', run([...$git, ...$args], $keepalive));
}

function containerId(string $service): string
{
    if (!preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $service)) {
        throw new ToolError("invalid service $service");
    }
    $ids = trim((string)shell_exec('docker ps -aq --filter ' . escapeshellarg('label=com.docker.compose.project=' . PROJECT)
        . ' --filter ' . escapeshellarg("label=com.docker.compose.service=$service")));
    if ($ids === '' || str_contains($ids, "\n")) {
        throw new ToolError("expected one container of service $service, got: '$ids' (see ps)");
    }
    return $ids;
}

/**
 * operations changing the stack must not run concurrently
 */
function locked(callable $fn): string
{
    $lock = fopen('/tmp/devctl.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        throw new ToolError('another operation (change_branch, up, restart_webpack) is running');
    }
    try {
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * runs $cmd (no shell) in the tine-dev dir, returns "exit code" + output (stdout+stderr, ANSI stripped, tail)
 */
function run(array $cmd, callable $keepalive): string
{
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
        getenv('TINE_DEV_DIR') ?: null);
    if (!is_resource($proc)) {
        throw new ToolError('failed to start ' . $cmd[0]);
    }
    stream_set_blocking($pipes[1], false);
    $output = '';
    $lastPing = time();
    while (true) {
        $read = [$pipes[1]];
        $write = $except = null;
        $ready = stream_select($read, $write, $except, KEEPALIVE);
        // the client sees nothing of the output until the end, ping it regularly - also while output flows
        if (time() - $lastPing >= KEEPALIVE) {
            $lines = preg_split('/[\r\n]+/', trim(preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', substr($output, -2000))));
            $keepalive(substr(trim((string)end($lines)), 0, 200));
            $lastPing = time();
        }
        if ($ready === 0) {
            continue;
        }
        $chunk = fread($pipes[1], 65536);
        if ($chunk === '' || $chunk === false) {
            if (feof($pipes[1])) {
                break;
            }
            continue;
        }
        $output .= $chunk;
        if (strlen($output) > 4 * MAX_OUTPUT) {
            $output = substr($output, -2 * MAX_OUTPUT);
        }
    }
    fclose($pipes[1]);
    $code = proc_close($proc);

    $output = preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e\][^\a]*\a|\r(?!\n)/', '', $output);
    if (strlen($output) > MAX_OUTPUT) {
        $output = "[... output truncated ...]\n" . substr($output, -MAX_OUTPUT);
    }
    return "exit code: $code\n\n$output";
}

// ---------------------------------------------------------------------------------------------------- http

function sendJson(int $status, $body = null): void
{
    http_response_code($status);
    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}

function rpcResult($id, $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function rpcError($id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/mcp') {
    sendJson(404, ['error' => 'not found']);
    return true;
}

$token = (string)getenv('DEVCTL_TOKEN');
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
$auth = $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($token === '' || !hash_equals("Bearer $token", $auth)) {
    sendJson(401, ['error' => 'unauthorized']);
    return true;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    // no server initiated messages
    header('Allow: POST');
    sendJson(405, ['error' => 'method not allowed']);
    return true;
}

$msg = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0' || !isset($msg['method'])) {
    sendJson(400, rpcError(null, -32600, 'invalid request (batches are not supported)'));
    return true;
}

$id = $msg['id'] ?? null;
$params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

if (!array_key_exists('id', $msg)) {
    // notification (e.g. notifications/initialized)
    sendJson(202);
    return true;
}

switch ($msg['method']) {
    case 'initialize':
        $version = in_array($params['protocolVersion'] ?? '', PROTOCOL_VERSIONS, true)
            ? $params['protocolVersion'] : PROTOCOL_VERSIONS[0];
        sendJson(200, rpcResult($id, [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => new stdClass()],
            'serverInfo' => ['name' => 'tine-dev-devctl', 'version' => '1.0.0'],
            'instructions' => 'Controls the tine-dev docker stack: switch branches, (re)start containers, run'
                . ' commands in the tine web container and the e2e test runner. There is no other docker access.',
        ]));
        return true;
    case 'ping':
        sendJson(200, rpcResult($id, new stdClass()));
        return true;
    case 'tools/list':
        sendJson(200, rpcResult($id, ['tools' => tools()]));
        return true;
    case 'tools/call':
        break;
    default:
        sendJson(200, rpcError($id, -32601, "method not found: {$msg['method']}"));
        return true;
}

// tools/call: SSE stream, keepalive comments while the operation runs, finish the operation even if the client
// is gone (e.g. half done change_branch)
ignore_user_abort(true);
set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_flush();
}
http_response_code(200);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
echo ": started\n\n";
flush();
// clients abort tool calls without response or progress (Claude Code: 300s): send progress notifications if the
// client asked for them (progressToken), an SSE comment otherwise
$progressToken = $params['_meta']['progressToken'] ?? null;
$keepalive = function (string $message = '') use ($progressToken) {
    static $progress = 0;
    if ($progressToken === null) {
        echo ": keepalive\n\n";
    } else {
        $notification = ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [
            'progressToken' => $progressToken,
            'progress' => ++$progress,
            'message' => $message !== '' ? $message : 'running',
        ]];
        echo "event: message\ndata: "
            . json_encode($notification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            . "\n\n";
    }
    flush();
};

try {
    $text = callTool((string)($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : [],
        $keepalive);
    $isError = !str_starts_with($text, 'exit code: 0' . "\n");
    $response = rpcResult($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError]);
} catch (ToolError $e) {
    $response = rpcResult($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
} catch (Throwable $e) {
    $response = rpcError($id, -32603, get_class($e) . ': ' . $e->getMessage());
}

echo "event: message\ndata: "
    . json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
flush();
return true;
