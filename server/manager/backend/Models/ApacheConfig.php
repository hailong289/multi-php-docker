<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;
use Manager\Support\DockerLiveState;
use Manager\Support\HostPorts;

/**
 * apache/httpd.conf (editable) and the generated vhosts in apache/sites (read-only here).
 * Reloads go through runtime/apache.reload, which scripts/apache/entrypoint.sh checks with `httpd -t` first.
 */
final class ApacheConfig
{
    private const MAX_BYTES = 524288;

    private readonly string $projectPath;
    private readonly string $runtimePath;

    public function __construct(?string $projectPath = null, ?string $runtimePath = null)
    {
        $this->projectPath = rtrim($projectPath ?? Config::projectPath(), '/');
        $this->runtimePath = rtrim($runtimePath ?? Config::runtimePath(), '/');
    }

    public function details(): array
    {
        $config = $this->projectPath . '/apache/httpd.conf';
        $vhosts = [];
        foreach (glob($this->projectPath . '/apache/sites/*.conf') ?: [] as $file) {
            $vhosts[] = ['name' => basename($file), 'content' => (string) file_get_contents($file)];
        }
        $log = $this->runtimePath . '/apache.reload.log';

        $ports = HostPorts::read('apache');

        return [
            'http_port' => $ports['http'],
            'https_port' => $ports['https'],
            'config' => is_file($config) ? (string) file_get_contents($config) : '',
            'config_updated_at' => is_file($config) ? date(DATE_ATOM, (int) filemtime($config)) : null,
            'vhosts' => $vhosts,
            'reload_log' => is_file($log) ? (string) file_get_contents($log) : '',
            'reload_log_updated_at' => is_file($log) ? date(DATE_ATOM, (int) filemtime($log)) : null,
        ];
    }

    public function saveConfig(string $content): void
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new HttpException('apache.config_too_large', 422);
        }
        if (!preg_match('//u', $content)) {
            throw new HttpException('apache.config_invalid_utf8', 422);
        }
        $path = $this->projectPath . '/apache/httpd.conf';
        if (!is_file($path) || !is_writable($path)) {
            throw new HttpException('apache.config_not_writable', 500);
        }
        // Written in place: the file is bind-mounted on its own, so a rename would leave Apache on the old inode.
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new HttpException('apache.config_not_writable', 500);
        }
    }

    /**
     * Saves host ports to .env; recreates Apache when it exists so the new mapping applies.
     *
     * @return string request id of the recreate job, '' when the container is not created
     */
    public function updatePorts(mixed $http, mixed $https): string
    {
        HostPorts::save('apache', ['http' => $http, 'https' => $https]);
        if (!in_array(DockerLiveState::stateFor('apache_container'), ['running', 'stopped'], true)) {
            return '';
        }

        return (new ComposeFileRuntime(projectPath: $this->projectPath))->request('apache.yml', 'recreate');
    }

    public function requestReload(): void
    {
        if (!is_dir($this->runtimePath) && !mkdir($this->runtimePath, 0775, true) && !is_dir($this->runtimePath)) {
            throw new HttpException('error.runtime_directory', 500);
        }
        if (file_put_contents($this->runtimePath . '/apache.reload', date(DATE_ATOM), LOCK_EX) === false) {
            throw new HttpException('error.reload_request', 500);
        }
    }
}
