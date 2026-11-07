<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\ComposeEnv;
use Manager\Support\ComposeJobs;
use Manager\Support\Config;
use Manager\Support\ContainerControl;
use Manager\Support\DockerLiveState;
use Manager\Support\HostPorts;
use Manager\Support\JsonFile;

final class NginxManagement
{
    private readonly ComposeJobs $jobs;

    public function __construct(
        private readonly string $controllerPath = '',
        private readonly string $runtimePath = '',
        private readonly string $logsPath = '',
        ?ComposeJobs $jobs = null,
        private readonly string $envPath = '',
    ) {
        $this->jobs = $jobs ?? new ComposeJobs();
    }

    public function status(): array
    {
        $base = rtrim($this->controllerPath ?: Config::phpControllerPath(), '/');
        $status = [
            'service' => 'nginx',
            'container' => 'nginx_container',
            'state' => 'not_created',
            'message_key' => 'nginx.controller_unavailable',
            'request_id' => '',
            'updated_at' => '',
        ];
        $file = $base . '/status/nginx.json';
        $decoded = JsonFile::readObject($file);
        if (is_array($decoded) && in_array($decoded['state'] ?? null, ['running', 'stopped', 'not_created', 'busy', 'error'], true)) {
            $status = array_merge($status, array_intersect_key($decoded, $status));
        }
        if ($this->jobs->blocks($base, 'nginx')) {
            $status['state'] = 'busy';
            $status['message_key'] = 'nginx.processing';
        } else {
            $status = DockerLiveState::apply(
                $status,
                'nginx_container',
                'php_controller.status_refreshed'
            );
        }
        $env = $this->envPath ?: ComposeEnv::path();
        // Containers created before NGINX_SCALE existed: pin scale so the next `compose up` keeps them.
        if ($status['state'] !== 'not_created' && $status['state'] !== 'busy' && ComposeEnv::get('NGINX_SCALE', $env) === null) {
            ComposeEnv::set(['NGINX_SCALE' => '1'], $env);
        }

        return [...$status, ...$this->ports()];
    }

    /** @return array{http_port: int, https_port: int} */
    public function ports(): array
    {
        $ports = HostPorts::read('nginx', $this->envPath ?: null);

        return ['http_port' => $ports['http'], 'https_port' => $ports['https']];
    }

    /**
     * Saves host ports to .env; recreates nginx when it exists so the new mapping applies.
     *
     * @return string request id of the recreate job, '' when nginx is not created yet
     */
    public function updatePorts(mixed $http, mixed $https): string
    {
        $base = rtrim($this->controllerPath ?: Config::phpControllerPath(), '/');
        if ($this->jobs->blocks($base, 'nginx')) {
            throw new HttpException('php_controller.busy', 409);
        }
        HostPorts::save('nginx', ['http' => $http, 'https' => $https], $this->envPath ?: null);
        // A stopped container keeps its old port mapping on `docker start`, so recreate it too.
        if (!in_array(DockerLiveState::stateFor('nginx_container'), ['running', 'stopped'], true)) {
            return '';
        }

        return $this->jobs->begin($base, [
            'service' => 'nginx',
            'action' => 'recreate',
        ]);
    }

    public function requestAction(string $action): string
    {
        if (!in_array($action, ['start', 'stop', 'restart', 'create'], true)) {
            throw new HttpException('nginx.invalid_action', 400);
        }
        $base = rtrim($this->controllerPath ?: Config::phpControllerPath(), '/');
        if (in_array($action, ['start', 'stop', 'restart'], true)) {
            $state = ContainerControl::apply('nginx_container', $action);
            if ($state === null) {
                $key = match ($action) {
                    'stop' => 'nginx.stop_failed',
                    'restart' => 'nginx.restart_failed',
                    default => 'nginx.start_failed',
                };
                throw new HttpException($key, 502);
            }
            if (!ContainerControl::writeStatus($base, 'nginx', $state, 'php_controller.action_success')) {
                throw new HttpException('nginx.request_failed', 500);
            }

            return bin2hex(random_bytes(16));
        }

        // scale 0 makes `compose create nginx` a no-op, so opt in before the job runs.
        if (!ComposeEnv::set(['NGINX_SCALE' => '1'], $this->envPath ?: ComposeEnv::path())) {
            throw new HttpException('nginx.request_failed', 500);
        }

        return $this->jobs->begin($base, [
            'service' => 'nginx',
            'action' => $action,
        ]);
    }

    public function details(): array
    {
        $runtime = rtrim($this->runtimePath ?: Config::runtimePath(), '/');
        return [
            ...$this->status(),
            'reload_status' => $this->readJson($runtime . '/nginx.status.json'),
            'logs' => $this->logs(),
        ];
    }

    public function logs(): array
    {
        $runtime = rtrim($this->runtimePath ?: Config::runtimePath(), '/');
        $logs = rtrim($this->logsPath ?: Config::nginxLogsPath(), '/');

        return [
            'action' => $this->tailFile($this->newestControllerActionLog()),
            'operation' => $this->tailFile($runtime . '/nginx.reload.log'),
            'error' => $this->tailFile($logs . '/error.log'),
            'access' => $this->tailFile($logs . '/access.log'),
        ];
    }

    /**
     * Newest php-controller nginx.last-{start|restart|stop}.log (contains docker logs on failed start).
     */
    private function newestControllerActionLog(): string
    {
        $base = rtrim($this->controllerPath ?: Config::phpControllerPath(), '/') . '/status';
        $best = '';
        $bestMtime = -1;
        foreach (['nginx.last-create.log', 'nginx.last-recreate.log', 'nginx.last-start.log', 'nginx.last-restart.log', 'nginx.last-stop.log'] as $name) {
            $path = $base . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $mtime = (int) filemtime($path);
            if ($mtime >= $bestMtime) {
                $bestMtime = $mtime;
                $best = $path;
            }
        }

        return $best;
    }

    /**
     * Truncate one global nginx manager log panel file.
     *
     * @param 'action'|'operation'|'error'|'access' $name
     * @return list<string> cleared basenames
     */
    public function clearGlobalLog(string $name): array
    {
        if (!in_array($name, ['action', 'operation', 'error', 'access'], true)) {
            throw new HttpException('nginx.global_clear_invalid', 400);
        }

        $runtime = rtrim($this->runtimePath ?: Config::runtimePath(), '/');
        $logs = rtrim($this->logsPath ?: Config::nginxLogsPath(), '/');
        $controllerStatus = rtrim($this->controllerPath ?: Config::phpControllerPath(), '/') . '/status';
        $paths = match ($name) {
            'action' => [
                $controllerStatus . '/nginx.last-create.log',
                $controllerStatus . '/nginx.last-recreate.log',
                $controllerStatus . '/nginx.last-start.log',
                $controllerStatus . '/nginx.last-restart.log',
                $controllerStatus . '/nginx.last-stop.log',
            ],
            'operation' => [$runtime . '/nginx.reload.log'],
            'error' => [$logs . '/error.log'],
            'access' => [$logs . '/access.log'],
        };

        $cleared = [];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            if (!is_writable($path)) {
                throw new HttpException('nginx.global_clear_failed', 500);
            }
            if (file_put_contents($path, '') === false) {
                throw new HttpException('nginx.global_clear_failed', 500);
            }
            $cleared[] = basename($path);
        }

        if ($cleared === []) {
            throw new HttpException('nginx.global_log_missing', 404);
        }

        return $cleared;
    }

    /**
     * Domains that have per-vhost nginx log files ({domain}_access.log / _error.log).
     *
     * @return list<array{domain: string, access: array{available: bool, size: int, updated_at: string}, error: array{available: bool, size: int, updated_at: string}}>
     */
    public function domainLogList(): array
    {
        $logs = rtrim($this->logsPath ?: Config::nginxLogsPath(), '/');
        $domains = [];

        if (is_dir($logs)) {
            foreach (glob($logs . '/*_access.log') ?: [] as $path) {
                $base = basename($path);
                $domain = substr($base, 0, -strlen('_access.log'));
                if ($this->isSafeDomain($domain)) {
                    $domains[$domain] = true;
                }
            }
            foreach (glob($logs . '/*_error.log') ?: [] as $path) {
                $base = basename($path);
                $domain = substr($base, 0, -strlen('_error.log'));
                if ($this->isSafeDomain($domain)) {
                    $domains[$domain] = true;
                }
            }
        }

        $list = [];
        foreach (array_keys($domains) as $domain) {
            $accessPath = $logs . '/' . $domain . '_access.log';
            $errorPath = $logs . '/' . $domain . '_error.log';
            $list[] = [
                'domain' => $domain,
                'access' => $this->logMeta($accessPath),
                'error' => $this->logMeta($errorPath),
            ];
        }

        usort($list, static fn (array $a, array $b): int => strcasecmp($a['domain'], $b['domain']));

        return $list;
    }

    /**
     * @return array{domain: string, access: array{available: bool, content: string, updated_at: string, size: int}, error: array{available: bool, content: string, updated_at: string, size: int}}
     */
    public function domainLogs(string $domain, int $lines = 200): array
    {
        if (!$this->isSafeDomain($domain)) {
            throw new HttpException('nginx.domain_invalid', 400);
        }

        $logs = rtrim($this->logsPath ?: Config::nginxLogsPath(), '/');
        $accessPath = $logs . '/' . $domain . '_access.log';
        $errorPath = $logs . '/' . $domain . '_error.log';

        if (!is_file($accessPath) && !is_file($errorPath)) {
            throw new HttpException('nginx.domain_logs_missing', 404);
        }

        $access = $this->tailFile($accessPath, $lines);
        $error = $this->tailFile($errorPath, $lines);
        $access['size'] = is_file($accessPath) ? (int) filesize($accessPath) : 0;
        $error['size'] = is_file($errorPath) ? (int) filesize($errorPath) : 0;

        return [
            'domain' => $domain,
            'access' => $access,
            'error' => $error,
        ];
    }

    /**
     * Truncate per-domain access and/or error log files.
     *
     * @param 'access'|'error'|'both' $which
     * @return list<string> cleared basenames
     */
    public function clearDomainLogs(string $domain, string $which = 'both'): array
    {
        if (!$this->isSafeDomain($domain)) {
            throw new HttpException('nginx.domain_invalid', 400);
        }
        if (!in_array($which, ['access', 'error', 'both'], true)) {
            throw new HttpException('nginx.domain_clear_invalid', 400);
        }

        $logs = rtrim($this->logsPath ?: Config::nginxLogsPath(), '/');
        $targets = [];
        if ($which === 'access' || $which === 'both') {
            $targets[] = $logs . '/' . $domain . '_access.log';
        }
        if ($which === 'error' || $which === 'both') {
            $targets[] = $logs . '/' . $domain . '_error.log';
        }

        $cleared = [];
        foreach ($targets as $path) {
            if (!is_file($path)) {
                continue;
            }
            if (!is_writable($path)) {
                throw new HttpException('nginx.domain_clear_failed', 500);
            }
            if (file_put_contents($path, '') === false) {
                throw new HttpException('nginx.domain_clear_failed', 500);
            }
            $cleared[] = basename($path);
        }

        if ($cleared === []) {
            throw new HttpException('nginx.domain_logs_missing', 404);
        }

        return $cleared;
    }

    private function isSafeDomain(string $domain): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,253}$/', $domain);
    }

    /** @return array{available: bool, size: int, updated_at: string} */
    private function logMeta(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['available' => false, 'size' => 0, 'updated_at' => ''];
        }

        return [
            'available' => true,
            'size' => (int) filesize($path),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
        ];
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function tailFile(string $path, int $lines = 200): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['available' => false, 'content' => '', 'updated_at' => ''];
        }
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['available' => false, 'content' => '', 'updated_at' => ''];
        }
        $offset = max(0, $size - 1048576);
        fseek($handle, $offset);
        if ($offset > 0) {
            fgets($handle);
        }
        $content = (string) stream_get_contents($handle);
        fclose($handle);
        $rows = preg_split('/\r\n|\n|\r/', $content) ?: [];
        if ($rows !== [] && end($rows) === '') {
            array_pop($rows);
        }
        $content = implode(PHP_EOL, array_slice($rows, -$lines));
        if (!preg_match('//u', $content)) {
            $content = (string) iconv('UTF-8', 'UTF-8//IGNORE', $content);
        }
        return [
            'available' => true,
            'content' => $content,
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
        ];
    }
}
