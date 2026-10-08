<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Models\DockerConnection;

/**
 * Docker Engine transport: local unix socket, TCP(+TLS), or SSH via local docker:cli helper.
 */
final class DockerEndpoint
{
    /** @var array<string, mixed>|null */
    private static ?array $cachedConfig = null;

    public static function reset(): void
    {
        self::$cachedConfig = null;
    }

    /**
     * @return array{
     *   mode: string,
     *   tcp: array{host: string, port: int, tls: bool, ca: string, cert: string, key: string},
     *   ssh: array{user: string, host: string, port: int, identity_file: string},
     *   remote_project_path: string,
     *   updated_at: string
     * }
     */
    public static function config(): array
    {
        if (self::$cachedConfig !== null) {
            return self::$cachedConfig;
        }
        self::$cachedConfig = (new DockerConnection())->load();

        return self::$cachedConfig;
    }

    public static function mode(): string
    {
        return self::config()['mode'];
    }

    public static function isRemote(): bool
    {
        return self::mode() !== DockerConnection::MODE_LOCAL;
    }

    public static function localSocketPath(): string
    {
        $configured = getenv('MANAGER_DOCKER_SOCK');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return '/var/run/docker.sock';
    }

    /**
     * Whether the configured endpoint can be used (files exist / config complete).
     * Does not guarantee the remote daemon is up.
     */
    public static function available(?array $config = null): bool
    {
        $config ??= self::config();
        $mode = $config['mode'];

        if ($mode === DockerConnection::MODE_LOCAL) {
            return file_exists(self::localSocketPath());
        }

        if ($mode === DockerConnection::MODE_TCP) {
            if (trim((string) $config['tcp']['host']) === '') {
                return false;
            }
            if (!empty($config['tcp']['tls'])) {
                foreach (['ca', 'cert', 'key'] as $f) {
                    if (!is_file((string) $config['tcp'][$f])) {
                        return false;
                    }
                }
            }

            return true;
        }

        if ($mode === DockerConnection::MODE_SSH) {
            return trim((string) $config['ssh']['user']) !== ''
                && trim((string) $config['ssh']['host']) !== ''
                && is_file((string) $config['ssh']['identity_file'])
                && file_exists(self::localSocketPath());
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public static function ping(?array $config = null): bool
    {
        $config ??= self::config();
        if (!self::available($config)) {
            return false;
        }

        if (($config['mode'] ?? '') === DockerConnection::MODE_SSH) {
            $out = self::sshDocker(['info', '--format', '{{.ServerVersion}}'], $config, 20);

            return is_string($out) && trim($out) !== '';
        }

        $body = self::request('GET', '/_ping', null, null, [200], $config, 2.0);

        return $body !== null;
    }

    /**
     * Engine HTTP request. For SSH mode, only a small set of paths are emulated via docker CLI.
     *
     * @param list<int>|null $okStatuses
     * @param array<string, mixed>|null $config
     */
    public static function request(
        string $method,
        string $path,
        ?string $body = null,
        ?string $contentType = null,
        ?array $okStatuses = null,
        ?array $config = null,
        float $timeout = 5.0,
    ): ?string {
        $config ??= self::config();
        if (!self::available($config)) {
            return null;
        }

        if (($config['mode'] ?? '') === DockerConnection::MODE_SSH) {
            return self::sshRequest($method, $path, $okStatuses ?? [200], $config);
        }

        $fp = self::openStream($config, $timeout);
        if ($fp === false) {
            return null;
        }

        stream_set_timeout($fp, (int) max(1, ceil($timeout)));
        $okStatuses ??= [200];
        $hostHeader = 'localhost';
        if (($config['mode'] ?? '') === DockerConnection::MODE_TCP) {
            $hostHeader = (string) $config['tcp']['host'];
        }
        $headers = [
            "{$method} {$path} HTTP/1.0",
            'Host: ' . $hostHeader,
            'Connection: close',
        ];
        if ($body !== null) {
            if ($contentType !== null) {
                $headers[] = 'Content-Type: ' . $contentType;
            }
            $headers[] = 'Content-Length: ' . (string) strlen($body);
        }
        $request = implode("\r\n", $headers) . "\r\n\r\n" . ($body ?? '');
        if (fwrite($fp, $request) === false) {
            fclose($fp);

            return null;
        }

        $response = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($response) || $response === '') {
            return null;
        }

        $parts = explode("\r\n\r\n", $response, 2);
        if (count($parts) < 2) {
            return null;
        }
        if (!preg_match('/^HTTP\/\d\.\d\s+(\d{3})\b/', $parts[0], $m)) {
            return null;
        }
        $code = (int) $m[1];
        if (!in_array($code, $okStatuses, true)) {
            return null;
        }

        $respBody = $parts[1];
        if (preg_match('/^Transfer-Encoding:\s*chunked\b/mi', $parts[0])) {
            $respBody = self::decodeChunked($respBody);
        }

        return $respBody;
    }

    /**
     * HTTP status for start-like calls (local/tcp). SSH returns 204/404/0.
     *
     * @param array<string, mixed>|null $config
     */
    public static function requestStatus(
        string $method,
        string $path,
        ?array $config = null,
        float $timeout = 5.0,
    ): int {
        $config ??= self::config();
        if (!self::available($config)) {
            return 0;
        }

        if (($config['mode'] ?? '') === DockerConnection::MODE_SSH) {
            if (preg_match('#^/containers/([^/]+)/start$#', $path, $m)) {
                $name = rawurldecode($m[1]);
                $out = self::sshDocker(['start', $name], $config, 30);

                return $out !== null ? 204 : 500;
            }

            return 500;
        }

        $fp = self::openStream($config, $timeout);
        if ($fp === false) {
            return 0;
        }
        stream_set_timeout($fp, (int) max(1, ceil($timeout)));
        $hostHeader = ($config['mode'] ?? '') === DockerConnection::MODE_TCP
            ? (string) $config['tcp']['host']
            : 'localhost';
        $request = "{$method} {$path} HTTP/1.0\r\nHost: {$hostHeader}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        if (fwrite($fp, $request) === false) {
            fclose($fp);

            return 0;
        }
        $response = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($response) || $response === '') {
            return 0;
        }
        if (!preg_match('/^HTTP\/\d\.\d\s+(\d{3})\b/', $response, $m)) {
            return 0;
        }

        return (int) $m[1];
    }

    /**
     * Env vars for ephemeral helpers that talk to the target Docker via DOCKER_HOST.
     *
     * @param array<string, mixed>|null $config
     * @return list<string>
     */
    public static function dockerCliEnv(?array $config = null): array
    {
        $config ??= self::config();
        $env = ['DOCKER_CONFIG=/tmp/docker-config'];
        if ($config['mode'] === DockerConnection::MODE_LOCAL) {
            return $env;
        }
        if ($config['mode'] === DockerConnection::MODE_TCP) {
            $env[] = 'DOCKER_HOST=tcp://' . $config['tcp']['host'] . ':' . $config['tcp']['port'];
            if (!empty($config['tcp']['tls'])) {
                $env[] = 'DOCKER_TLS_VERIFY=1';
                $env[] = 'DOCKER_CERT_PATH=' . dirname((string) $config['tcp']['cert']);
            }

            return $env;
        }
        $env[] = 'DOCKER_HOST=ssh://' . $config['ssh']['user'] . '@' . $config['ssh']['host'] . ':' . $config['ssh']['port'];

        return $env;
    }

    /**
     * Extra binds for ephemeral helpers (certs / ssh key / local sock).
     *
     * @param array<string, mixed>|null $config
     * @return list<string>
     */
    public static function dockerCliBinds(?array $config = null): array
    {
        $config ??= self::config();
        $binds = [];
        // Always need local sock to spawn the helper itself when using SSH/TCP from Manager.
        $localSock = self::localSocketPath();
        if (file_exists($localSock)) {
            $binds[] = $localSock . ':/var/run/docker.sock';
        }
        if ($config['mode'] === DockerConnection::MODE_TCP && !empty($config['tcp']['tls'])) {
            $certDir = dirname((string) $config['tcp']['cert']);
            $binds[] = $certDir . ':' . $certDir . ':ro';
        }
        if ($config['mode'] === DockerConnection::MODE_SSH) {
            $id = (string) $config['ssh']['identity_file'];
            $binds[] = $id . ':/root/.ssh/id_remote:ro';
        }

        return $binds;
    }

    /**
     * @param array<string, mixed> $config
     * @return resource|false
     */
    private static function openStream(array $config, float $timeout)
    {
        $mode = $config['mode'] ?? DockerConnection::MODE_LOCAL;
        if ($mode === DockerConnection::MODE_LOCAL) {
            $sock = self::localSocketPath();

            return @stream_socket_client('unix://' . $sock, $errno, $errstr, $timeout);
        }

        $host = (string) $config['tcp']['host'];
        $port = (int) $config['tcp']['port'];
        if (!empty($config['tcp']['tls'])) {
            $ctx = stream_context_create([
                'ssl' => [
                    'cafile' => (string) $config['tcp']['ca'],
                    'local_cert' => (string) $config['tcp']['cert'],
                    'local_pk' => (string) $config['tcp']['key'],
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            return @stream_socket_client(
                'ssl://' . $host . ':' . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $ctx,
            );
        }

        return @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
    }

    /**
     * @param list<int> $okStatuses
     * @param array<string, mixed> $config
     */
    private static function sshRequest(string $method, string $path, array $okStatuses, array $config): ?string
    {
        // Emulate the Engine routes Manager actually uses.
        if ($method === 'GET' && $path === '/_ping') {
            $out = self::sshDocker(['info', '--format', '{{.ServerVersion}}'], $config, 20);

            return ($out !== null && in_array(200, $okStatuses, true)) ? 'OK' : null;
        }

        if ($method === 'GET' && str_starts_with($path, '/containers/json')) {
            $out = self::sshDocker(['ps', '-a', '--format', '{{json .}}'], $config, 30);
            if ($out === null) {
                return null;
            }
            $items = [];
            foreach (preg_split('/\r?\n/', trim($out)) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                try {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (!is_array($row)) {
                    continue;
                }
                $names = [];
                $name = (string) ($row['Names'] ?? '');
                foreach (explode(',', $name) as $n) {
                    $n = trim($n);
                    if ($n !== '') {
                        $names[] = '/' . ltrim($n, '/');
                    }
                }
                $state = strtolower((string) ($row['State'] ?? ''));
                $items[] = [
                    'Id' => (string) ($row['ID'] ?? ''),
                    'Names' => $names,
                    'State' => $state === 'running' ? 'running' : $state,
                ];
            }
            try {
                return json_encode($items, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        if ($method === 'GET' && preg_match('#^/containers/([^/]+)/json$#', $path, $m)) {
            $id = rawurldecode($m[1]);
            $out = self::sshDocker(['inspect', $id], $config, 30);
            if ($out === null) {
                return null;
            }
            try {
                $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
                return null;
            }
            try {
                return json_encode($decoded[0], JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        if ($method === 'GET' && preg_match('#^/containers/([^/]+)/logs#', $path, $m)) {
            $id = rawurldecode($m[1]);
            $tail = 300;
            if (preg_match('/[?&]tail=(\d+)/', $path, $tm)) {
                $tail = max(1, min(2000, (int) $tm[1]));
            }
            $out = self::sshDocker(['logs', '--tail', (string) $tail, $id], $config, 30);

            return $out;
        }

        if ($method === 'POST' && preg_match('#^/containers/([^/]+)/(stop|restart)$#', $path, $m)) {
            $id = rawurldecode($m[1]);
            $action = $m[2];
            $out = self::sshDocker([$action, $id], $config, 60);
            if ($out === null && !in_array(204, $okStatuses, true)) {
                return null;
            }

            return in_array(204, $okStatuses, true) || in_array(200, $okStatuses, true) ? '' : null;
        }

        if ($method === 'DELETE' && preg_match('#^/containers/([^/]+)#', $path, $m)) {
            $id = rawurldecode($m[1]);
            $out = self::sshDocker(['rm', '-f', $id], $config, 60);

            return $out !== null || in_array(204, $okStatuses, true) || in_array(404, $okStatuses, true) ? '' : null;
        }

        if ($method === 'GET' && preg_match('#^/images/([^/]+)/json$#', $path, $m)) {
            $ref = rawurldecode($m[1]);
            $out = self::sshDocker(['image', 'inspect', $ref], $config, 30);

            return $out !== null ? $out : null;
        }

        if ($method === 'DELETE' && preg_match('#^/images/([^/?]+)#', $path, $m)) {
            $ref = rawurldecode($m[1]);
            $out = self::sshDocker(['rmi', '-f', $ref], $config, 60);

            return $out !== null ? '' : null;
        }

        return null;
    }

    /**
     * Run `docker ...` against remote via local docker:cli + DOCKER_HOST=ssh://...
     *
     * @param list<string> $dockerArgs
     * @param array<string, mixed> $config
     */
    public static function sshDocker(array $dockerArgs, array $config, int $timeoutSeconds = 30): ?string
    {
        if (!file_exists(self::localSocketPath())) {
            return null;
        }

        $idFile = (string) ($config['ssh']['identity_file'] ?? '');
        if ($idFile === '' || !is_file($idFile)) {
            return null;
        }

        $user = (string) $config['ssh']['user'];
        $host = (string) $config['ssh']['host'];
        $port = (int) $config['ssh']['port'];
        $quotedArgs = [];
        foreach ($dockerArgs as $arg) {
            $quotedArgs[] = escapeshellarg($arg);
        }
        $dockerCmd = 'docker ' . implode(' ', $quotedArgs);
        $script = <<<'SH'
set -eu
mkdir -p /root/.ssh /tmp/docker-config
chmod 700 /root/.ssh
cp /root/.ssh/id_remote /root/.ssh/id_rsa
chmod 600 /root/.ssh/id_rsa
printf '%s\n' 'Host *' '  StrictHostKeyChecking accept-new' '  IdentityFile /root/.ssh/id_rsa' > /root/.ssh/config
export DOCKER_CONFIG=/tmp/docker-config
export DOCKER_HOST="ssh://${SSH_USER}@${SSH_HOST}:${SSH_PORT}"
# docker:cli may lack openssh; install quietly when needed.
if ! command -v ssh >/dev/null 2>&1; then
  apk add --no-cache openssh-client >/dev/null 2>&1 || true
fi
eval "$DOCKER_CMD"
SH;

        // Use Docker Engine on LOCAL socket to run the helper (always local sock).
        $helperConfig = [
            'Image' => 'docker:cli',
            'Cmd' => ['/bin/sh', '-c', $script],
            'Env' => [
                'SSH_USER=' . $user,
                'SSH_HOST=' . $host,
                'SSH_PORT=' . (string) $port,
                'DOCKER_CMD=' . $dockerCmd,
            ],
            'HostConfig' => [
                'Binds' => [
                    self::localSocketPath() . ':/var/run/docker.sock',
                    $idFile . ':/root/.ssh/id_remote:ro',
                ],
                'AutoRemove' => false,
            ],
        ];

        return self::runLocalEphemeralCapture($helperConfig, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function runLocalEphemeralCapture(array $config, int $timeoutSeconds): ?string
    {
        // Speak to LOCAL engine only (ignore remote mode for spawning helper).
        $prev = self::$cachedConfig;
        self::$cachedConfig = DockerConnection::defaults();
        try {
            try {
                $body = json_encode($config, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            $created = self::request('POST', '/containers/create', $body, 'application/json', [201], DockerConnection::defaults(), 5.0);
            if ($created === null) {
                return null;
            }
            try {
                $decoded = json_decode($created, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            $id = is_array($decoded) ? (string) ($decoded['Id'] ?? '') : '';
            if ($id === '') {
                return null;
            }
            $start = self::requestStatus('POST', '/containers/' . rawurlencode($id) . '/start', DockerConnection::defaults(), 5.0);
            if ($start !== 204 && $start !== 304) {
                self::request('DELETE', '/containers/' . rawurlencode($id) . '?force=1', null, null, [204, 404], DockerConnection::defaults());

                return null;
            }

            $deadline = microtime(true) + max(5, $timeoutSeconds);
            while (microtime(true) < $deadline) {
                $raw = self::request('GET', '/containers/' . rawurlencode($id) . '/json', null, null, [200], DockerConnection::defaults());
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
                $state = is_array($info['State'] ?? null) ? $info['State'] : [];
                if (!empty($state['Running'])) {
                    usleep(250000);
                    continue;
                }
                $exit = (int) ($state['ExitCode'] ?? -1);
                $logs = self::request(
                    'GET',
                    '/containers/' . rawurlencode($id) . '/logs?stdout=1&stderr=1&timestamps=0&tail=2000',
                    null,
                    null,
                    [200],
                    DockerConnection::defaults(),
                );
                self::request('DELETE', '/containers/' . rawurlencode($id) . '?force=1', null, null, [204, 404], DockerConnection::defaults());
                if ($exit !== 0) {
                    return null;
                }

                return is_string($logs) ? self::stripDockerLogHeaders($logs) : '';
            }
            self::request('DELETE', '/containers/' . rawurlencode($id) . '?force=1', null, null, [204, 404], DockerConnection::defaults());

            return null;
        } finally {
            self::$cachedConfig = $prev;
        }
    }

    private static function stripDockerLogHeaders(string $data): string
    {
        if ($data === '') {
            return '';
        }
        // Soft-decode multiplexed docker log frames when present.
        $len = strlen($data);
        if ($len < 8 || ord($data[0]) > 2) {
            return $data;
        }
        $out = '';
        $offset = 0;
        while ($offset + 8 <= $len) {
            $size = unpack('N', substr($data, $offset + 4, 4));
            $payloadSize = is_array($size) ? (int) ($size[1] ?? 0) : 0;
            if ($payloadSize < 0 || $offset + 8 + $payloadSize > $len) {
                break;
            }
            $out .= substr($data, $offset + 8, $payloadSize);
            $offset += 8 + $payloadSize;
        }

        return $out !== '' ? $out : $data;
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
