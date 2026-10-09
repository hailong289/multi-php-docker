<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Http\HttpException;
use Manager\Models\DockerConnection;

/**
 * Docker Engine Exec helpers over the Manager unix socket (TTY attach for terminals).
 */
final class DockerExec
{
    public static function containerIdByName(string $name): ?string
    {
        $name = ltrim($name, '/');
        if ($name === '' || !DockerLiveState::available()) {
            return null;
        }

        $raw = self::httpRequest('GET', '/containers/json?all=true');
        if ($raw === null) {
            return null;
        }

        try {
            $list = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $names = $item['Names'] ?? [];
            if (!is_array($names)) {
                continue;
            }
            foreach ($names as $n) {
                if (!is_string($n) || $n === '') {
                    continue;
                }
                if (ltrim($n, '/') === $name) {
                    $id = (string) ($item['Id'] ?? '');

                    return $id !== '' ? $id : null;
                }
            }
        }

        return null;
    }

    /**
     * Start a container by name via the Engine API.
     * 0 = socket unreachable; otherwise the HTTP status (204/304/404/5xx).
     */
    public static function startNamedContainer(string $name): int
    {
        $name = ltrim($name, '/');
        if ($name === '' || !DockerLiveState::available()) {
            return 0;
        }

        return DockerEndpoint::requestStatus(
            'POST',
            '/containers/' . rawurlencode($name) . '/start',
        );
    }

    /**
     * @param list<string> $cmd
     */
    public static function createExec(
        string $containerId,
        array $cmd,
        int $cols,
        int $rows,
        string $workingDir = '',
        bool $tty = true,
        bool $attachStdin = true,
        array $env = [],
    ): string {
        $body = [
            'AttachStdin' => $attachStdin,
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Tty' => $tty,
            'Cmd' => array_values($cmd),
        ];
        if ($tty) {
            $body['ConsoleSize'] = [$rows, $cols];
        }
        if ($workingDir !== '') {
            $body['WorkingDir'] = $workingDir;
        }
        if ($env !== []) {
            $body['Env'] = array_values($env);
        }
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $raw = self::httpRequest(
            'POST',
            '/containers/' . rawurlencode($containerId) . '/exec',
            $payload,
            'application/json',
            [200, 201],
        );
        if ($raw === null) {
            throw new HttpException('terminal.attach_failed', 502);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException('terminal.attach_failed', 502);
        }
        $id = is_array($data) ? (string) ($data['Id'] ?? '') : '';
        if ($id === '') {
            throw new HttpException('terminal.attach_failed', 502);
        }

        return $id;
    }

    public static function resizeExec(string $execId, int $cols, int $rows): void
    {
        $cols = max(40, min(300, $cols));
        $rows = max(10, min(120, $rows));
        $path = '/exec/' . rawurlencode($execId) . '/resize?h=' . $rows . '&w=' . $cols;
        self::httpRequest('POST', $path, '', null, [200, 201, 204, 404]);
    }

    /**
     * Open a hijacked attach stream for an exec (TTY → raw bytes; otherwise multiplexed).
     *
     * @return array{0: resource, 1: string} socket and any bytes already received after headers
     */
    public static function openAttach(string $execId, bool $tty = true): array
    {
        $sock = DockerLiveState::socketPath();
        $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 3.0);
        if ($fp === false) {
            throw new HttpException('terminal.docker_unavailable', 503);
        }

        stream_set_timeout($fp, 5);
        $body = json_encode(['Detach' => false, 'Tty' => $tty], JSON_THROW_ON_ERROR);
        $path = '/exec/' . rawurlencode($execId) . '/start';
        $request = "POST {$path} HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: Upgrade\r\n"
            . "Upgrade: tcp\r\n"
            . "\r\n"
            . $body;

        if (fwrite($fp, $request) === false) {
            fclose($fp);
            throw new HttpException('terminal.attach_failed', 502);
        }

        $buffer = '';
        $deadline = microtime(true) + 5.0;
        while (!str_contains($buffer, "\r\n\r\n")) {
            if (microtime(true) > $deadline) {
                fclose($fp);
                throw new HttpException('terminal.attach_failed', 502);
            }
            $chunk = fread($fp, 4096);
            if ($chunk === false || $chunk === '') {
                usleep(10000);
                continue;
            }
            $buffer .= $chunk;
            if (strlen($buffer) > 65536) {
                fclose($fp);
                throw new HttpException('terminal.attach_failed', 502);
            }
        }

        $parts = explode("\r\n\r\n", $buffer, 2);
        $headerBlock = $parts[0];
        $preface = $parts[1] ?? '';

        if (!preg_match('/^HTTP\/\d\.\d\s+(101|200)\b/', $headerBlock)) {
            fclose($fp);
            throw new HttpException('terminal.attach_failed', 502);
        }

        stream_set_blocking($fp, false);
        stream_set_timeout($fp, 0, 200000);

        return [$fp, $preface];
    }

    public static function inspectExec(string $execId): ?array
    {
        $raw = self::httpRequest('GET', '/exec/' . rawurlencode($execId) . '/json', null, null, [200]);
        if ($raw === null) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * One-shot non-TTY exec. Writes $stdin then half-closes, collects multiplexed stdout/stderr.
     *
     * @param list<string> $cmd
     * @return array{stdout: string, stderr: string, exit_code: int, timed_out: bool, truncated: bool}
     */
    public static function run(
        string $containerId,
        array $cmd,
        string $stdin = '',
        int $timeoutSeconds = 12,
        int $maxOutputBytes = 262144,
        string $workingDir = '',
        array $env = [],
    ): array {
        if (!DockerLiveState::available()) {
            throw new HttpException('php_controller.run_unavailable', 503);
        }

        $timeoutSeconds = max(1, min(30, $timeoutSeconds));
        $maxOutputBytes = max(1024, min(1048576, $maxOutputBytes));

        try {
            $execId = self::createExec(
                $containerId,
                $cmd,
                80,
                24,
                $workingDir,
                false,
                $stdin !== '',
                $env,
            );
            [$fp, $preface] = self::openAttach($execId, false);
        } catch (HttpException $e) {
            if (in_array($e->errorKey(), ['php_controller.run_unavailable', 'terminal.docker_unavailable'], true)) {
                throw new HttpException('php_controller.run_unavailable', 503);
            }
            throw new HttpException('php_controller.run_failed', 502);
        }

        if ($stdin !== '' && fwrite($fp, $stdin) === false) {
            fclose($fp);
            throw new HttpException('php_controller.run_failed', 502);
        }
        if ($stdin !== '') {
            @stream_socket_shutdown($fp, STREAM_SHUT_WR);
        }

        [$stdout, $stderr, $timedOut, $truncated] = self::collectMultiplexed(
            $fp,
            $preface,
            $timeoutSeconds,
            $maxOutputBytes,
        );

        $info = self::inspectExec($execId);
        $running = is_array($info) && !empty($info['Running']);
        $exit = is_array($info) ? (int) ($info['ExitCode'] ?? -1) : -1;
        if ($running) {
            $timedOut = true;
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $timedOut && $running ? -1 : $exit,
            'timed_out' => $timedOut,
            'truncated' => $truncated,
        ];
    }

    /**
     * Split Docker multiplexed attach frames (Tty=false).
     *
     * @return array{0: string, 1: string, 2: string} stdout, stderr, remainder
     */
    public static function splitMultiplexed(string $data, int $maxOutputBytes = 1048576): array
    {
        $stdout = '';
        $stderr = '';
        $offset = 0;
        $len = strlen($data);
        $cap = $maxOutputBytes;
        while ($offset + 8 <= $len) {
            $header = substr($data, $offset, 8);
            $type = ord($header[0]);
            $size = unpack('N', substr($header, 4, 4));
            $payloadSize = is_array($size) ? (int) ($size[1] ?? 0) : 0;
            if ($payloadSize < 0 || $payloadSize > 16 * 1024 * 1024) {
                break;
            }
            if ($offset + 8 + $payloadSize > $len) {
                break;
            }
            $payload = substr($data, $offset + 8, $payloadSize);
            $offset += 8 + $payloadSize;
            if ($type === 1) {
                $stdout .= $payload;
                if (strlen($stdout) > $cap) {
                    $stdout = substr($stdout, 0, $cap);
                }
            } elseif ($type === 2) {
                $stderr .= $payload;
                if (strlen($stderr) > $cap) {
                    $stderr = substr($stderr, 0, $cap);
                }
            }
        }

        return [$stdout, $stderr, substr($data, $offset)];
    }

    /**
     * Decode docker logs API bytes (multiplexed stdout/stderr, or raw TTY).
     */
    public static function decodeLogStream(string $data, int $maxBytes = 262144): string
    {
        if ($data === '') {
            return '';
        }
        $len = strlen($data);
        $looksMultiplexed = $len >= 8
            && in_array(ord($data[0]), [1, 2], true)
            && $data[1] === "\0"
            && $data[2] === "\0"
            && $data[3] === "\0";
        if (!$looksMultiplexed) {
            $out = $data;
            if (strlen($out) > $maxBytes) {
                $out = substr($out, -$maxBytes);
            }
            if (!preg_match('//u', $out)) {
                $out = (string) iconv('UTF-8', 'UTF-8//IGNORE', $out);
            }

            return $out;
        }

        $out = '';
        $offset = 0;
        while ($offset + 8 <= $len) {
            $header = substr($data, $offset, 8);
            $type = ord($header[0]);
            $size = unpack('N', substr($header, 4, 4));
            $payloadSize = is_array($size) ? (int) ($size[1] ?? 0) : 0;
            if ($payloadSize < 0 || $payloadSize > 16 * 1024 * 1024 || $offset + 8 + $payloadSize > $len) {
                break;
            }
            if ($type === 1 || $type === 2) {
                $out .= substr($data, $offset + 8, $payloadSize);
                if (strlen($out) > $maxBytes) {
                    $out = substr($out, -$maxBytes);
                }
            }
            $offset += 8 + $payloadSize;
        }
        if (!preg_match('//u', $out)) {
            $out = (string) iconv('UTF-8', 'UTF-8//IGNORE', $out);
        }

        return $out;
    }

    /**
     * Last stdout/stderr lines for a container name (`docker logs`).
     */
    public static function containerLogs(string $containerName, int $tail = 300): ?string
    {
        $id = self::containerIdByName($containerName);
        if ($id === null) {
            return null;
        }
        $tail = max(1, min(2000, $tail));
        $path = '/containers/' . rawurlencode($id)
            . '/logs?stdout=1&stderr=1&timestamps=1&tail=' . $tail;
        $raw = self::httpRequest('GET', $path, null, null, [200]);
        if ($raw === null) {
            return null;
        }

        return self::decodeLogStream($raw);
    }

    /**
     * Force-remove a container by name. Returns true when absent or removed.
     */
    public static function removeNamedContainer(string $name): bool
    {
        $name = ltrim($name, '/');
        if ($name === '' || !DockerLiveState::available()) {
            return false;
        }

        $id = self::containerIdByName($name);
        if ($id === null) {
            return true;
        }

        $removed = self::httpRequest(
            'DELETE',
            '/containers/' . rawurlencode($id) . '?force=1',
            null,
            null,
            [200, 204, 404],
        ) !== null;

        DockerLiveState::resetCache();

        return $removed || self::containerIdByName($name) === null;
    }

    public static function stopNamedContainer(string $name): bool
    {
        $name = ltrim($name, '/');
        if ($name === '' || !DockerLiveState::available()) {
            return false;
        }

        $id = self::containerIdByName($name);
        if ($id === null) {
            return true;
        }

        $stopped = self::httpRequest(
            'POST',
            '/containers/' . rawurlencode($id) . '/stop',
            null,
            null,
            [204, 304, 404],
        ) !== null;
        DockerLiveState::resetCache();

        if (!$stopped) {
            return false;
        }

        $state = DockerLiveState::stateFor($name);

        return $state === null || $state === 'stopped' || $state === 'not_created';
    }

    public static function restartNamedContainer(string $name): bool
    {
        $name = ltrim($name, '/');
        if ($name === '' || !DockerLiveState::available()) {
            return false;
        }

        $id = self::containerIdByName($name);
        if ($id === null) {
            return false;
        }

        $restarted = self::httpRequest(
            'POST',
            '/containers/' . rawurlencode($id) . '/restart',
            null,
            null,
            [204, 404],
        ) !== null;
        DockerLiveState::resetCache();

        return $restarted && DockerLiveState::stateFor($name) === 'running';
    }

    /**
     * Inspect a container by name. Returns decoded Engine JSON or null.
     *
     * @return array<string, mixed>|null
     */
    public static function inspectNamedContainer(string $name): ?array
    {
        $name = ltrim($name, '/');
        if ($name === '' || !DockerLiveState::available()) {
            return null;
        }

        $id = self::containerIdByName($name);
        if ($id === null) {
            return null;
        }

        $raw = self::httpRequest(
            'GET',
            '/containers/' . rawurlencode($id) . '/json',
            null,
            null,
            [200],
        );
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Host path bound into a container mount destination (Engine view).
     */
    public static function mountSource(string $containerName, string $destination): ?string
    {
        $inspect = self::inspectNamedContainer($containerName);
        if ($inspect === null) {
            return null;
        }
        $mounts = $inspect['Mounts'] ?? [];
        if (!is_array($mounts)) {
            return null;
        }
        foreach ($mounts as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            if (($mount['Destination'] ?? '') !== $destination) {
                continue;
            }
            $src = $mount['Source'] ?? '';

            return is_string($src) && $src !== '' ? $src : null;
        }

        return null;
    }

    /**
     * Bind path the Linux engine accepts.
     * macOS and Linux paths are unchanged. Docker Desktop on Windows shares
     * the drive at /run/desktop/mnt/host/<drive>/...; a D:\ or D:/ short-form
     * volume is rejected with "too many colons".
     */
    public static function daemonBindPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('/^([A-Za-z]):\/(.*)$/', $path, $m) === 1) {
            $drive = strtolower($m[1]);
            $rest = ltrim($m[2], '/');

            return '/run/desktop/mnt/host/' . $drive . '/' . $rest;
        }

        return $path;
    }

    public static function resolveHostProjectPath(): ?string
    {
        $remote = DockerConnection::projectPathFor(DockerEndpoint::config());
        if ($remote !== null) {
            return $remote;
        }

        $fromMount = self::mountSource('manager_container', '/var/host-project');
        if ($fromMount !== null) {
            return $fromMount;
        }

        $envPath = Config::projectPath() . '/.env';
        if (!is_file($envPath)) {
            return null;
        }
        $content = (string) @file_get_contents($envPath);
        if ($content === '') {
            return null;
        }
        if (!preg_match('/^HOST_PROJECT_PATH=(.*)$/m', $content, $m)) {
            return null;
        }
        $value = trim($m[1], " \t\"'");
        if ($value === '' || $value === '/project' || $value === '.') {
            return null;
        }

        return $value;
    }

    public static function composeProjectName(): string
    {
        foreach (['manager_container', 'nginx_container', 'php_controller_container'] as $name) {
            $inspect = self::inspectNamedContainer($name);
            if ($inspect === null) {
                continue;
            }
            $labels = is_array($inspect['Config'] ?? null) ? ($inspect['Config']['Labels'] ?? null) : null;
            if (!is_array($labels)) {
                continue;
            }
            $project = $labels['com.docker.compose.project'] ?? '';
            if (is_string($project) && $project !== '') {
                return $project;
            }
        }

        $host = self::resolveHostProjectPath();

        return $host !== null ? basename(rtrim($host, '/\\')) : 'web';
    }

    /**
     * Create/start a compose service via an ephemeral docker:cli helper
     * (Manager has the Engine socket but not the Compose CLI).
     */
    public static function composeUpService(string $service, int $timeoutSeconds = 120): bool
    {
        $service = preg_replace('/[^a-zA-Z0-9._-]/', '', $service) ?? '';
        if ($service === '' || !DockerLiveState::available()) {
            return false;
        }

        $hostProject = self::resolveHostProjectPath();
        if ($hostProject === null) {
            return false;
        }
        $hostProject = self::daemonBindPath($hostProject);

        // Local project bind for reading compose files inside the helper.
        $localProject = self::daemonBindPath(
            self::mountSource('manager_container', '/var/host-project')
                ?? Config::projectPath()
        );

        $project = self::composeProjectName();
        // Mirror scripts/php/php-controller.sh prepare_compose_tmp: bind sources must be
        // host paths. Compose running inside a helper otherwise emits /project/... binds.
        $script = <<<'SH'
set -eu
mkdir -p "${DOCKER_CONFIG:-/tmp/docker-config}"
host_project="${HOST_PROJECT_PATH:?}"
# Same rule as php-controller.sh to_daemon_bind_path. Unix paths are unchanged.
host_project=$(printf '%s' "$host_project" | tr '\\' '/')
case "$host_project" in
  [A-Za-z]:/*)
    drive=$(printf '%s' "$host_project" | cut -c1 | tr '[:upper:]' '[:lower:]')
    rest=$(printf '%s' "$host_project" | sed 's|^[A-Za-z]:/*||')
    host_project="/run/desktop/mnt/host/${drive}/${rest}"
    ;;
esac
tmp_dir="/tmp/compose-up.$$"
mkdir -p "$tmp_dir/compose"
rewrite_compose_paths() {
  repl=$(printf '%s' "$host_project" | sed -e 's/[\\&|]/\\&/g')
  sed \
    -e "s|- \\./|${repl}/|g" \
    -e 's|project_directory:[[:space:]]*\.[[:space:]]*$|project_directory: /project|' \
    -e 's|context:[[:space:]]*\.[[:space:]]*$|context: /project|' \
    -e 's|context:[[:space:]]*"\."[[:space:]]*$|context: /project|' \
    -e "s|context:[[:space:]]*'\\.'[[:space:]]*$|context: /project|" \
    -e 's|context:[[:space:]]*\./|context: /project/|g' \
    "$1"
}
rewrite_compose_paths /project/docker-compose.yml > "$tmp_dir/docker-compose.yml"
for f in /project/compose/*.yml; do
  [ -f "$f" ] || continue
  rewrite_compose_paths "$f" > "$tmp_dir/compose/$(basename "$f")"
done
cd /project
set -- docker compose -p "$COMPOSE_PROJECT_NAME"
if [ -f /project/.env ]; then
  set -- "$@" --env-file /project/.env
fi
set -- "$@" -f "$tmp_dir/docker-compose.yml" --project-directory /project up -d --no-deps "$COMPOSE_SERVICE"
exec "$@"
SH;

        $env = array_merge(
            DockerEndpoint::dockerCliEnv(),
            [
                'HOST_PROJECT_PATH=' . $hostProject,
                'COMPOSE_PROJECT_NAME=' . $project,
                'COMPOSE_SERVICE=' . $service,
            ],
        );
        $binds = array_values(array_unique(array_merge(
            DockerEndpoint::dockerCliBinds(),
            [$localProject . ':/project'],
        )));

        $exit = self::runEphemeral([
            'Image' => 'docker:cli',
            'Cmd' => ['/bin/sh', '-c', $script],
            'Env' => $env,
            'WorkingDir' => '/project',
            'HostConfig' => [
                'Binds' => $binds,
                'AutoRemove' => false,
            ],
        ], $timeoutSeconds);

        DockerLiveState::resetCache();

        return $exit === 0;
    }

    /**
     * Create + start a one-shot container, wait for exit, remove it.
     *
     * @param array<string, mixed> $config Engine container create body
     */
    public static function runEphemeral(array $config, int $timeoutSeconds = 120): int
    {
        if (!file_exists(DockerEndpoint::localSocketPath())) {
            return -1;
        }

        $timeoutSeconds = max(5, min(300, $timeoutSeconds));
        try {
            $body = json_encode($config, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return -1;
        }

        $local = \Manager\Models\DockerConnection::defaults();
        $created = DockerEndpoint::request(
            'POST',
            '/containers/create',
            $body,
            'application/json',
            [201],
            $local,
        );
        if ($created === null) {
            return -1;
        }
        try {
            $decoded = json_decode($created, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return -1;
        }
        $id = is_array($decoded) ? (string) ($decoded['Id'] ?? '') : '';
        if ($id === '') {
            return -1;
        }

        $startCode = DockerEndpoint::requestStatus(
            'POST',
            '/containers/' . rawurlencode($id) . '/start',
            $local,
        );
        if ($startCode !== 204 && $startCode !== 304) {
            DockerEndpoint::request('DELETE', '/containers/' . rawurlencode($id) . '?force=1', null, null, [204, 404], $local);

            return -1;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $exit = -1;
        while (microtime(true) < $deadline) {
            $raw = DockerEndpoint::request(
                'GET',
                '/containers/' . rawurlencode($id) . '/json',
                null,
                null,
                [200, 404],
                $local,
            );
            if ($raw === null) {
                usleep(200000);
                continue;
            }
            try {
                $info = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                usleep(200000);
                continue;
            }
            if (!is_array($info)) {
                usleep(200000);
                continue;
            }
            $state = is_array($info['State'] ?? null) ? $info['State'] : [];
            if (!empty($state['Running'])) {
                usleep(250000);
                continue;
            }
            $exit = (int) ($state['ExitCode'] ?? -1);
            break;
        }

        DockerEndpoint::request('DELETE', '/containers/' . rawurlencode($id) . '?force=1', null, null, [204, 404], $local);

        return $exit;
    }

    public static function imageExists(string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '' || !DockerLiveState::available()) {
            return false;
        }

        return DockerImageIndex::contains($ref);
    }

    /** Force-remove an image by name:tag. Returns true when absent or removed. */
    public static function removeImage(string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '' || !DockerLiveState::available()) {
            return false;
        }
        DockerImageIndex::resetCache();
        if (!self::imageExists($ref)) {
            return true;
        }

        $removed = self::httpRequest(
            'DELETE',
            '/images/' . rawurlencode($ref) . '?force=1',
            null,
            null,
            [200, 204, 404],
        ) !== null;
        DockerImageIndex::resetCache();

        return $removed && !self::imageExists($ref);
    }

    /**
     * @param resource $fp
     * @return array{0: string, 1: string, 2: bool, 3: bool} stdout, stderr, timed_out, truncated
     */
    private static function collectMultiplexed(
        $fp,
        string $preface,
        int $timeoutSeconds,
        int $maxOutputBytes,
    ): array {
        stream_set_blocking($fp, false);
        $buffer = $preface;
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $truncated = false;
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if (strlen($stdout) + strlen($stderr) >= $maxOutputBytes) {
                $truncated = true;
                break;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }
            $read = [$fp];
            $write = null;
            $except = null;
            $sec = (int) $remaining;
            $usec = (int) round(($remaining - $sec) * 1000000);
            if ($usec >= 1000000) {
                $sec++;
                $usec -= 1000000;
            }
            $n = @stream_select($read, $write, $except, $sec, max(0, $usec));
            if ($n === false) {
                break;
            }
            if ($n === 0) {
                $timedOut = true;
                break;
            }
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($fp)) {
                    break;
                }
                continue;
            }
            $buffer .= $chunk;
            [$out, $err, $rest] = self::splitMultiplexed($buffer, $maxOutputBytes);
            $stdout .= $out;
            $stderr .= $err;
            $buffer = $rest;
            if (strlen($stdout) > $maxOutputBytes) {
                $stdout = substr($stdout, 0, $maxOutputBytes);
                $truncated = true;
                break;
            }
            if (strlen($stderr) > $maxOutputBytes) {
                $stderr = substr($stderr, 0, $maxOutputBytes);
                $truncated = true;
                break;
            }
        }

        if ($buffer !== '' && !$truncated && $stdout === '' && $stderr === '') {
            $stdout = $buffer;
        }

        fclose($fp);

        return [$stdout, $stderr, $timedOut, $truncated];
    }

    /**
     * @param list<int>|null $okStatuses
     */
    private static function httpRequest(
        string $method,
        string $path,
        ?string $body = null,
        ?string $contentType = null,
        ?array $okStatuses = null,
    ): ?string {
        return DockerEndpoint::request($method, $path, $body, $contentType, $okStatuses);
    }

    private static function decodeChunked(string $body): string
    {
        $out = '';
        $offset = 0;
        $len = strlen($body);
        while ($offset < $len) {
            $nl = strpos($body, "\r\n", $offset);
            if ($nl === false) {
                break;
            }
            $sizeLine = substr($body, $offset, $nl - $offset);
            if (str_contains($sizeLine, ';')) {
                $sizeLine = explode(';', $sizeLine, 2)[0];
            }
            $size = hexdec(trim($sizeLine));
            $offset = $nl + 2;
            if ($size === 0) {
                break;
            }
            if ($offset + $size > $len) {
                break;
            }
            $out .= substr($body, $offset, $size);
            $offset += $size;
            if (substr($body, $offset, 2) === "\r\n") {
                $offset += 2;
            }
        }

        return $out;
    }
}
