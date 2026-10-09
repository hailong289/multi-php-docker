<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;
use Manager\Support\DockerEndpoint;
use Manager\Support\DockerLiveState;

/**
 * Persisted Docker endpoint config (local | tcp_tls | ssh) under /runtime.
 */
final class DockerConnection
{
    public const MODE_LOCAL = 'local';
    public const MODE_TCP = 'tcp_tls';
    public const MODE_SSH = 'ssh';

    /** @var (callable(): array)|null */
    private mixed $loader = null;

    /** @var (callable(array): void)|null */
    private mixed $saver = null;

    /**
     * @param (callable(): array)|null $loader
     * @param (callable(array): void)|null $saver
     */
    public function __construct(?callable $loader = null, ?callable $saver = null)
    {
        $this->loader = $loader;
        $this->saver = $saver;
    }

    public static function configPath(): string
    {
        return rtrim(Config::runtimePath(), '/') . '/docker-connection.json';
    }

    public static function dockerEnvPath(): string
    {
        return rtrim(Config::phpControllerPath(), '/') . '/docker.env';
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
    public function load(): array
    {
        if (is_callable($this->loader)) {
            return self::normalize(($this->loader)());
        }

        $path = self::configPath();
        if (!is_file($path)) {
            return self::defaults();
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return self::defaults();
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::defaults();
        }

        return self::normalize(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   mode: string,
     *   tcp: array{host: string, port: int, tls: bool, ca: string, cert: string, key: string},
     *   ssh: array{user: string, host: string, port: int, identity_file: string},
     *   remote_project_path: string,
     *   updated_at: string,
     *   message_key: string
     * }
     */
    public function save(array $input): array
    {
        $config = self::normalize($input);
        $this->validate($config);
        $config['updated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');

        if (is_callable($this->saver)) {
            ($this->saver)($config);
        } else {
            $path = self::configPath();
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new HttpException('docker_connection.write_failed', 500);
            }
            try {
                $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            } catch (\JsonException) {
                throw new HttpException('docker_connection.write_failed', 500);
            }
            if (@file_put_contents($path, $json . "\n") === false) {
                throw new HttpException('docker_connection.write_failed', 500);
            }
        }

        $this->writeDockerEnv($config);
        DockerLiveState::resetCache();
        DockerEndpoint::reset();

        return $config + ['message_key' => 'docker_connection.saved'];
    }

    /**
     * @return array{
     *   mode: string,
     *   tcp: array{host: string, port: int, tls: bool, ca: string, cert: string, key: string},
     *   ssh: array{user: string, host: string, port: int, identity_file: string},
     *   remote_project_path: string,
     *   updated_at: string,
     *   effective: array{mode: string, label: string, project_path: ?string},
     *   reachable: bool|null
     * }
     */
    public function status(?bool $probe = true): array
    {
        $config = $this->load();
        $reachable = null;
        if ($probe) {
            $reachable = DockerEndpoint::ping($config);
        }

        return $config + [
            'effective' => [
                'mode' => $config['mode'],
                'label' => self::labelFor($config),
                'project_path' => self::projectPathFor($config),
            ],
            'reachable' => $reachable,
        ];
    }

    /**
     * @param array<string, mixed>|null $override
     * @return array{ok: bool, message_key: string, details?: string}
     */
    public function test(?array $override = null): array
    {
        $config = $override !== null ? self::normalize($override) : $this->load();
        $this->validate($config);
        if (!DockerEndpoint::ping($config)) {
            return [
                'ok' => false,
                'message_key' => 'docker_connection.test_failed',
            ];
        }

        return [
            'ok' => true,
            'message_key' => 'docker_connection.test_ok',
        ];
    }

    /**
     * @param array{
     *   mode: string,
     *   tcp: array{host: string, port: int, tls: bool, ca: string, cert: string, key: string},
     *   ssh: array{user: string, host: string, port: int, identity_file: string},
     *   remote_project_path: string,
     *   updated_at: string
     * } $config
     */
    public function validate(array $config): void
    {
        $mode = $config['mode'];
        if (!in_array($mode, [self::MODE_LOCAL, self::MODE_TCP, self::MODE_SSH], true)) {
            throw new HttpException('docker_connection.invalid_mode', 400);
        }

        if ($mode === self::MODE_TCP) {
            if (trim($config['tcp']['host']) === '') {
                throw new HttpException('docker_connection.tcp_host_required', 400);
            }
            if ($config['tcp']['port'] < 1 || $config['tcp']['port'] > 65535) {
                throw new HttpException('docker_connection.tcp_port_invalid', 400);
            }
            if ($config['tcp']['tls']) {
                foreach (['ca', 'cert', 'key'] as $field) {
                    $path = $config['tcp'][$field];
                    if ($path === '' || !is_file($path)) {
                        throw new HttpException('docker_connection.tls_files_required', 400);
                    }
                }
            }
        }

        if ($mode === self::MODE_SSH) {
            if (trim($config['ssh']['user']) === '' || trim($config['ssh']['host']) === '') {
                throw new HttpException('docker_connection.ssh_required', 400);
            }
            if ($config['ssh']['port'] < 1 || $config['ssh']['port'] > 65535) {
                throw new HttpException('docker_connection.ssh_port_invalid', 400);
            }
            $idFile = $config['ssh']['identity_file'];
            if ($idFile === '' || !is_file($idFile)) {
                throw new HttpException('docker_connection.ssh_identity_required', 400);
            }
        }

        if ($mode !== self::MODE_LOCAL && trim($config['remote_project_path']) === '') {
            throw new HttpException('docker_connection.remote_project_required', 400);
        }
    }

    /**
     * @param array{
     *   mode: string,
     *   tcp: array{host: string, port: int, tls: bool, ca: string, cert: string, key: string},
     *   ssh: array{user: string, host: string, port: int, identity_file: string},
     *   remote_project_path: string,
     *   updated_at: string
     * } $config
     */
    public function writeDockerEnv(array $config): void
    {
        $lines = [
            '# Generated by Server Manager — do not edit by hand',
        ];
        if ($config['mode'] === self::MODE_LOCAL) {
            $lines[] = 'DOCKER_HOST=unix:///var/run/docker.sock';
            $lines[] = 'unset DOCKER_TLS_VERIFY';
            $lines[] = 'unset DOCKER_CERT_PATH';
        } elseif ($config['mode'] === self::MODE_TCP) {
            $scheme = 'tcp';
            $host = $config['tcp']['host'];
            $port = (string) $config['tcp']['port'];
            $lines[] = 'DOCKER_HOST=' . $scheme . '://' . $host . ':' . $port;
            if ($config['tcp']['tls']) {
                $certDir = dirname($config['tcp']['cert']);
                $lines[] = 'DOCKER_TLS_VERIFY=1';
                $lines[] = 'DOCKER_CERT_PATH=' . $certDir;
            } else {
                // Any non-empty DOCKER_TLS_VERIFY, including 0, makes the CLI require certs.
                $lines[] = 'unset DOCKER_TLS_VERIFY';
                $lines[] = 'unset DOCKER_CERT_PATH';
            }
        } else {
            $user = $config['ssh']['user'];
            $host = $config['ssh']['host'];
            $port = (string) $config['ssh']['port'];
            $lines[] = 'DOCKER_HOST=ssh://' . $user . '@' . $host . ':' . $port;
            $lines[] = 'unset DOCKER_TLS_VERIFY';
            $lines[] = 'unset DOCKER_CERT_PATH';
            $lines[] = 'IDENTITY_FILE=' . $config['ssh']['identity_file'];
        }

        if ($config['mode'] !== self::MODE_LOCAL && $config['remote_project_path'] !== '') {
            $lines[] = 'HOST_PROJECT_PATH=' . $config['remote_project_path'];
        }

        $content = '';
        foreach ($lines as $line) {
            // docker.env is sourced by sh, so unset runs and clears TLS vars.
            $content .= $line . "\n";
        }

        $path = self::dockerEnvPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException('docker_connection.env_write_failed', 500);
        }
        if (@file_put_contents($path, $content) === false) {
            throw new HttpException('docker_connection.env_write_failed', 500);
        }
    }

    /**
     * @param array{
     *   mode: string,
     *   tcp: array{host: string, port: int, tls: bool, ca: string, cert: string, key: string},
     *   ssh: array{user: string, host: string, port: int, identity_file: string},
     *   remote_project_path: string,
     *   updated_at?: string
     * } $config
     */
    public static function projectPathFor(array $config): ?string
    {
        if ($config['mode'] !== self::MODE_LOCAL) {
            $remote = trim($config['remote_project_path']);

            return $remote !== '' ? $remote : null;
        }

        return null;
    }

    /**
     * @param array{
     *   mode: string,
     *   tcp: array{host: string, port: int, tls: bool, ca: string, cert: string, key: string},
     *   ssh: array{user: string, host: string, port: int, identity_file: string},
     *   remote_project_path: string
     * } $config
     */
    public static function labelFor(array $config): string
    {
        return match ($config['mode']) {
            self::MODE_TCP => 'tcp://' . $config['tcp']['host'] . ':' . $config['tcp']['port'],
            self::MODE_SSH => 'ssh://' . $config['ssh']['user'] . '@' . $config['ssh']['host'] . ':' . $config['ssh']['port'],
            default => 'unix://' . DockerLiveState::socketPath(),
        };
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
    public static function defaults(): array
    {
        return [
            'mode' => self::MODE_LOCAL,
            'tcp' => [
                'host' => '',
                'port' => 2376,
                'tls' => true,
                'ca' => '',
                'cert' => '',
                'key' => '',
            ],
            'ssh' => [
                'user' => '',
                'host' => '',
                'port' => 22,
                'identity_file' => '',
            ],
            'remote_project_path' => '',
            'updated_at' => '',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   mode: string,
     *   tcp: array{host: string, port: int, tls: bool, ca: string, cert: string, key: string},
     *   ssh: array{user: string, host: string, port: int, identity_file: string},
     *   remote_project_path: string,
     *   updated_at: string
     * }
     */
    public static function normalize(array $input): array
    {
        $base = self::defaults();
        $mode = (string) ($input['mode'] ?? $base['mode']);
        if (!in_array($mode, [self::MODE_LOCAL, self::MODE_TCP, self::MODE_SSH], true)) {
            $mode = self::MODE_LOCAL;
        }
        $tcpIn = is_array($input['tcp'] ?? null) ? $input['tcp'] : [];
        $sshIn = is_array($input['ssh'] ?? null) ? $input['ssh'] : [];

        return [
            'mode' => $mode,
            'tcp' => [
                'host' => trim((string) ($tcpIn['host'] ?? '')),
                'port' => max(1, min(65535, (int) ($tcpIn['port'] ?? 2376))),
                'tls' => array_key_exists('tls', $tcpIn) ? (bool) $tcpIn['tls'] : true,
                'ca' => trim((string) ($tcpIn['ca'] ?? '')),
                'cert' => trim((string) ($tcpIn['cert'] ?? '')),
                'key' => trim((string) ($tcpIn['key'] ?? '')),
            ],
            'ssh' => [
                'user' => trim((string) ($sshIn['user'] ?? '')),
                'host' => trim((string) ($sshIn['host'] ?? '')),
                'port' => max(1, min(65535, (int) ($sshIn['port'] ?? 22))),
                'identity_file' => trim((string) ($sshIn['identity_file'] ?? '')),
            ],
            'remote_project_path' => rtrim(trim((string) ($input['remote_project_path'] ?? '')), '/'),
            'updated_at' => (string) ($input['updated_at'] ?? ''),
        ];
    }
}
