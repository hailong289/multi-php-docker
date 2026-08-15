<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\AtomicFile;
use Manager\Support\Config;
use Manager\Support\ControllerRequests;
use Manager\Support\DockerLiveState;

final class SupervisorRuntime
{
    private readonly string $basePath;
    private readonly string $projectPath;

    public function __construct(?string $basePath = null, ?string $projectPath = null)
    {
        $this->basePath = rtrim($basePath ?? Config::phpControllerPath(), '/');
        $this->projectPath = rtrim($projectPath ?? Config::projectPath(), '/');
    }

    public static function targets(?string $projectPath = null): array
    {
        $targets = [];
        foreach (array_keys(PhpVersionCatalog::versions($projectPath)) as $phpService) {
            $service = PhpVersionId::supervisorService($phpService);
            $targets[$service] = [
                'label' => 'Supervisor · ' . PhpVersionId::label($phpService),
                'container' => PhpVersionId::supervisorContainer($phpService),
                'profile' => $service,
                'php_service' => $phpService,
                'log_dir' => PhpVersionId::supervisorLogRelative($phpService),
                'conf_dir' => PhpVersionId::supervisorConfDir($phpService),
                'create_command' => 'docker compose --profile ' . $service . ' create ' . $service,
            ];
        }

        return $targets;
    }

    public function statuses(): array
    {
        $allowedStates = ['running', 'stopped', 'not_created', 'busy', 'error'];
        $statuses = [];

        foreach (self::targets($this->projectPath) as $service => $target) {
            $status = [
                'service' => $service,
                'state' => 'not_created',
                'message_key' => 'supervisor.status_unavailable',
                'request_id' => '',
                'updated_at' => '',
            ];
            $statusFile = $this->basePath . '/status/' . $service . '.json';
            if (is_file($statusFile) && is_readable($statusFile)) {
                $decoded = json_decode((string) file_get_contents($statusFile), true);
                if (
                    is_array($decoded)
                    && ($decoded['service'] ?? null) === $service
                    && in_array(($decoded['state'] ?? null), $allowedStates, true)
                ) {
                    $status = array_merge($status, array_intersect_key($decoded, $status));
                }
            }
            if ($this->hasBlockingRequests($service)) {
                $status['state'] = 'busy';
                $status['message_key'] = 'supervisor.processing';
            } else {
                $status = DockerLiveState::apply(
                    $status,
                    (string) ($target['container'] ?? ''),
                    'php_controller.status_refreshed'
                );
            }
            $statuses[$service] = $status;
        }

        return $statuses;
    }

    public function hasBlockingRequests(string $service): bool
    {
        return ControllerRequests::hasBlocking(
            $this->basePath . '/requests',
            $service,
            ['start', 'stop', 'restart', 'create'],
        );
    }

    public function request(string $service, string $action): string
    {
        $targets = self::targets($this->projectPath);
        if (!isset($targets[$service])) {
            throw new HttpException('supervisor.invalid_service', 400);
        }
        if (!in_array($action, ['start', 'stop', 'restart', 'create'], true)) {
            throw new HttpException('supervisor.invalid_action', 400);
        }

        $requestDir = $this->basePath . '/requests';
        if (!is_dir($requestDir) && !mkdir($requestDir, 0775, true) && !is_dir($requestDir)) {
            throw new HttpException('supervisor.request_failed', 500);
        }

        $requestId = bin2hex(random_bytes(16));
        $payload = json_encode([
            'request_id' => $requestId,
            'service' => $service,
            'action' => $action,
            'requested_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $finalPath = $requestDir . '/' . $requestId . '__' . $service . '__' . $action . '.json';
        if (!AtomicFile::write($finalPath, $payload)) {
            throw new HttpException('supervisor.request_failed', 500);
        }

        return $requestId;
    }

    public function details(string $service, ?string $logFile = null): array
    {
        $targets = self::targets($this->projectPath);
        if (!isset($targets[$service])) {
            throw new HttpException('supervisor.invalid_service', 400);
        }
        $target = $targets[$service];
        $statuses = $this->statuses();
        $files = $this->listLogFiles($target['log_dir']);
        $selected = $logFile;
        if ($selected === null || $selected === '') {
            $selected = in_array('supervisord.log', $files, true) ? 'supervisord.log' : ($files[0] ?? '');
        }
        if ($selected !== '' && !in_array($selected, $files, true)) {
            throw new HttpException('supervisor.invalid_log', 400);
        }

        return [
            ...$target,
            ...($statuses[$service] ?? []),
            'log_files' => $files,
            'selected_log' => $selected,
            'log' => $selected !== ''
                ? $this->tailFile($this->projectPath . '/' . $target['log_dir'] . '/' . $selected)
                : ['available' => false, 'content' => '', 'updated_at' => ''],
        ];
    }

    /** @return list<string> */
    private function listLogFiles(string $logDirRel): array
    {
        $dir = $this->projectPath . '/' . $logDirRel;
        if (!is_dir($dir) || !is_readable($dir)) {
            return [];
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!str_ends_with(strtolower($name), '.log')) {
                continue;
            }
            if (!is_file($dir . '/' . $name)) {
                continue;
            }
            $files[] = $name;
        }
        usort($files, static function (string $a, string $b): int {
            if ($a === 'supervisord.log') {
                return -1;
            }
            if ($b === 'supervisord.log') {
                return 1;
            }

            return strcasecmp($a, $b);
        });

        return $files;
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

    public function clearLog(string $service, string $logFile): void
    {
        $targets = self::targets($this->projectPath);
        if (!isset($targets[$service])) {
            throw new HttpException('supervisor.invalid_service', 400);
        }
        $logFile = basename(str_replace('\\', '/', $logFile));
        if ($logFile === '' || !str_ends_with(strtolower($logFile), '.log')) {
            throw new HttpException('supervisor.invalid_log', 400);
        }
        $files = $this->listLogFiles($targets[$service]['log_dir']);
        if (!in_array($logFile, $files, true)) {
            throw new HttpException('supervisor.invalid_log', 400);
        }
        $path = $this->projectPath . '/' . $targets[$service]['log_dir'] . '/' . $logFile;
        if (!is_file($path) || !is_writable($path)) {
            throw new HttpException('supervisor.clear_failed', 500);
        }
        if (file_put_contents($path, '') === false) {
            throw new HttpException('supervisor.clear_failed', 500);
        }
    }
}
