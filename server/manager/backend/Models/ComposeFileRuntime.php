<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\ActionLogReader;
use Manager\Support\AtomicFile;
use Manager\Support\Config;
use Manager\Support\ControllerRequests;
use Manager\Support\DockerLiveState;

/** Queue build/pull/create for arbitrary compose/*.yml fragments (postgres, kafka, …). */
final class ComposeFileRuntime
{
    private const ACTIONS = ['create', 'start'];

    private readonly string $basePath;

    private readonly string $projectPath;

    private ?PhpControllerDaemon $daemon;

    public function __construct(?string $basePath = null, ?string $projectPath = null, ?PhpControllerDaemon $daemon = null)
    {
        $this->basePath = rtrim($basePath ?? Config::phpControllerPath(), '/');
        $this->projectPath = rtrim($projectPath ?? Config::projectPath(), '/');
        $this->daemon = $daemon;
    }

    public static function queueKey(string $filename): string
    {
        return 'compose-file__' . basename(str_replace(['\\', "\0"], '', $filename));
    }

    private function daemon(): PhpControllerDaemon
    {
        return $this->daemon ??= new PhpControllerDaemon();
    }

    public function request(string $filename, string $action): string
    {
        $name = $this->safeFilename($filename);
        if (!in_array($action, self::ACTIONS, true)) {
            throw new HttpException('services.compose_invalid_action', 400);
        }
        $path = $this->projectPath . '/compose/' . $name;
        if (!is_file($path)) {
            throw new HttpException('services.compose_missing', 404);
        }
        $services = ComposeFileParser::services((string) file_get_contents($path));
        if ($services === []) {
            throw new HttpException('services.compose_no_services', 422);
        }

        $this->daemon()->assertRunning();

        $requestDir = $this->basePath . '/requests';
        if (!is_dir($requestDir) && !mkdir($requestDir, 0775, true) && !is_dir($requestDir)) {
            throw new HttpException('services.request_failed', 500);
        }

        $requestId = bin2hex(random_bytes(16));
        $queueKey = self::queueKey($name);
        $payload = json_encode([
            'request_id' => $requestId,
            'compose_file' => $name,
            'queue_key' => $queueKey,
            'action' => $action,
            'requested_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $finalPath = $requestDir . '/' . $requestId . '__' . $queueKey . '__' . $action . '.json';
        if (!AtomicFile::write($finalPath, $payload)) {
            throw new HttpException('services.request_failed', 500);
        }

        return $requestId;
    }

    /**
     * @return array{
     *     compose_file: string,
     *     queue_key: string,
     *     state: string,
     *     message_key: string,
     *     request_id: string,
     *     available: bool,
     *     content: string,
     *     create_log: string,
     *     start_log: string,
     *     updated_at: string
     * }
     */
    public function actionLogs(string $filename): array
    {
        $name = $this->safeFilename($filename);
        $queueKey = self::queueKey($name);
        $bundle = ActionLogReader::bundle(
            $this->basePath . '/status',
            $queueKey,
            static fn (array $decoded): bool => ($decoded['queue_key'] ?? null) === $queueKey,
        );

        return array_merge([
            'compose_file' => $name,
            'queue_key' => $queueKey,
        ], $bundle);
    }

    public function hasBlockingRequests(string $filename): bool
    {
        return ControllerRequests::hasBlocking(
            $this->basePath . '/requests',
            self::queueKey($filename),
            self::ACTIONS,
        );
    }

    /**
     * @param list<array{name: string, profile: ?string, container: ?string, has_build: bool}> $services
     */
    public function statusForServices(string $filename, array $services): array
    {
        $name = $this->safeFilename($filename);
        $queueKey = self::queueKey($name);
        $status = [
            'compose_file' => $name,
            'queue_key' => $queueKey,
            'state' => 'not_created',
            'message_key' => 'services.status_unavailable',
            'request_id' => '',
            'updated_at' => '',
            'services' => $services,
            'has_build' => self::hasBuild($services),
            'included' => (new ComposeInclude($this->projectPath))->isIncluded($name),
        ];

        $statusFile = $this->basePath . '/status/' . $queueKey . '.json';
        $fileState = null;
        if (is_file($statusFile) && is_readable($statusFile)) {
            $decoded = json_decode((string) file_get_contents($statusFile), true);
            if (is_array($decoded) && ($decoded['queue_key'] ?? null) === $queueKey) {
                $status = array_merge($status, array_intersect_key($decoded, $status));
                $fileState = $decoded['state'] ?? null;
            }
        }

        if ($this->daemon()->status()['state'] === 'running' && $this->hasBlockingRequests($name)) {
            $status['state'] = 'busy';
            $status['message_key'] = 'services.processing';

            return $status;
        }

        if ($fileState === 'error') {
            $status['state'] = 'error';
            $status['message_key'] = (string) ($status['message_key'] ?: 'php_controller.action_failed');

            return $status;
        }

        $states = [];
        foreach ($services as $service) {
            $container = (string) ($service['container'] ?? '');
            if ($container === '') {
                continue;
            }
            $live = DockerLiveState::stateFor($container);
            if ($live === 'running') {
                $states[] = 'running';
            } elseif ($live === 'stopped') {
                $states[] = 'stopped';
            } elseif ($live === 'not_created') {
                $states[] = 'not_created';
            }
        }
        if ($states === []) {
            return $status;
        }
        if (in_array('running', $states, true)) {
            $status['state'] = 'running';
        } elseif (in_array('stopped', $states, true)) {
            $status['state'] = 'stopped';
        } else {
            $status['state'] = 'not_created';
        }
        $status['message_key'] = 'php_controller.status_refreshed';

        return $status;
    }

    /**
     * @param list<array{name: string, profile: ?string, container: ?string, has_build: bool}> $services
     */
    public static function hasBuild(array $services): bool
    {
        foreach ($services as $service) {
            if (!empty($service['has_build'])) {
                return true;
            }
        }

        return false;
    }

    private function safeFilename(string $filename): string
    {
        $name = basename(str_replace(['\\', "\0"], '', $filename));
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\.(yml|yaml)$/', $name)) {
            throw new HttpException('services.compose_invalid_name', 400);
        }

        return $name;
    }
}
