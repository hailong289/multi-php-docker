<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

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
        if (glob($base . '/requests/*__nginx__*.json')) {
            $status['state'] = 'busy';
            $status['message_key'] = 'nginx.processing';
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
