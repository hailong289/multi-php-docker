<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;
use Manager\Support\ControllerRequests;
use Manager\Support\DockerLiveState;

final class NginxManagement
{
    public function __construct(
        private readonly string $controllerPath = '',
        private readonly string $runtimePath = '',
        private readonly string $logsPath = '',
    ) {
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
        if (is_file($file) && is_readable($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && in_array($decoded['state'] ?? null, ['running', 'stopped', 'not_created', 'busy', 'error'], true)) {
                $status = array_merge($status, array_intersect_key($decoded, $status));
            }
        }
        if (ControllerRequests::hasBlocking($base . '/requests', 'nginx', ['start', 'stop', 'restart'])) {
            $status['state'] = 'busy';
            $status['message_key'] = 'nginx.processing';
        } else {
            $status = DockerLiveState::apply(
                $status,
                'nginx_container',
                'php_controller.status_refreshed'
            );
        }
        return $status;
    }

    public function requestAction(string $action): string
    {
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new HttpException('nginx.invalid_action', 400);
        }
        $base = rtrim($this->controllerPath ?: Config::phpControllerPath(), '/');
        $dir = $base . '/requests';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException('nginx.request_failed', 500);
        }
        $id = bin2hex(random_bytes(16));
        $payload = json_encode([
            'request_id' => $id,
            'service' => 'nginx',
            'action' => $action,
            'requested_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $final = $dir . '/' . $id . '__nginx__' . $action . '.json';
        $temp = $final . '.tmp';
        if (file_put_contents($temp, $payload, LOCK_EX) === false || !rename($temp, $final)) {
            @unlink($temp);
            throw new HttpException('nginx.request_failed', 500);
        }
        return $id;
    }

    public function requestTest(): void
    {
        $runtime = rtrim($this->runtimePath ?: Config::runtimePath(), '/');
        if (!is_dir($runtime) && !mkdir($runtime, 0775, true) && !is_dir($runtime)) {
            throw new HttpException('error.runtime_directory', 500);
        }
        if (file_put_contents($runtime . '/nginx.test', date(DATE_ATOM), LOCK_EX) === false) {
            throw new HttpException('nginx.test_request_failed', 500);
        }
    }

    public function details(): array
    {
        $runtime = rtrim($this->runtimePath ?: Config::runtimePath(), '/');
        return [
            ...$this->status(),
            'test_status' => $this->readJson($runtime . '/nginx.test.status.json'),
            'reload_status' => $this->readJson($runtime . '/nginx.status.json'),
            'logs' => $this->logs(),
        ];
    }

    public function logs(): array
    {
        $runtime = rtrim($this->runtimePath ?: Config::runtimePath(), '/');
        $logs = rtrim($this->logsPath ?: Config::nginxLogsPath(), '/');
        $test = $runtime . '/nginx.test.log';
        $reload = $runtime . '/nginx.reload.log';
        $operation = (is_file($test) && (!is_file($reload) || filemtime($test) >= filemtime($reload))) ? $test : $reload;
        return [
            'operation' => $this->tailFile($operation),
            'error' => $this->tailFile($logs . '/error.log'),
            'access' => $this->tailFile($logs . '/access.log'),
        ];
    }

    /**
     * Truncate one global nginx manager log panel file.
     *
     * @param 'operation'|'error'|'access' $name
     * @return list<string> cleared basenames
     */
    public function clearGlobalLog(string $name): array
    {
        if (!in_array($name, ['operation', 'error', 'access'], true)) {
            throw new HttpException('nginx.global_clear_invalid', 400);
        }

        $runtime = rtrim($this->runtimePath ?: Config::runtimePath(), '/');
        $logs = rtrim($this->logsPath ?: Config::nginxLogsPath(), '/');
        $paths = match ($name) {
            'operation' => [
                $runtime . '/nginx.test.log',
                $runtime . '/nginx.reload.log',
            ],
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
