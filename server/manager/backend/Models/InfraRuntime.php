<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\ActionLogReader;
use Manager\Support\AtomicFile;
use Manager\Support\Config;
use Manager\Support\ControllerRequests;
use Manager\Support\DockerExec;
use Manager\Support\DockerLiveState;

final class InfraRuntime
{
    private const SERVICES = [
        'mysql' => [
            'label' => 'MySQL',
            'container' => 'mysql_container',
            'profile' => 'mysql',
            'ports' => '3306',
        ],
        'redis' => [
            'label' => 'Redis',
            'container' => 'redis_container',
            'profile' => 'redis',
            'ports' => '6379',
        ],
        'rabbitmq' => [
            'label' => 'RabbitMQ',
            'container' => 'rabbitmq_container',
            'profile' => 'rabbitmq',
            'ports' => '5672, 15672',
        ],
    ];

    private readonly string $basePath;

    private ?PhpControllerDaemon $daemon;

    public function __construct(?string $basePath = null, ?PhpControllerDaemon $daemon = null)
    {
        $this->basePath = rtrim($basePath ?? Config::phpControllerPath(), '/');
        $this->daemon = $daemon;
    }

    private function daemon(): PhpControllerDaemon
    {
        return $this->daemon ??= new PhpControllerDaemon();
    }

    public static function targets(): array
    {
        $projectPath = rtrim(Config::projectPath(), '/');
        $targets = [];
        foreach (self::SERVICES as $service => $config) {
            $image = self::imageFromCompose($projectPath, $service);
            $imagePresent = $image !== null && DockerLiveState::available() && DockerExec::imageExists($image);
            $targets[$service] = [
                'label' => $config['label'],
                'container' => $config['container'],
                'profile' => $config['profile'],
                'ports' => $config['ports'],
                'compose_file' => 'compose/' . $service . '.yml',
                'create_command' => 'docker compose --profile ' . $config['profile'] . ' create ' . $service,
                'image' => $image,
                'image_present' => $imagePresent,
            ];
        }

        return $targets;
    }

    private static function imageFromCompose(string $projectPath, string $service): ?string
    {
        $path = $projectPath . '/compose/' . $service . '.yml';
        if (!is_readable($path)) {
            return null;
        }
        $parsed = ComposeFileParser::services((string) file_get_contents($path));
        foreach ($parsed as $entry) {
            if (($entry['name'] ?? '') === $service) {
                $image = $entry['image'] ?? null;

                return is_string($image) && $image !== '' ? $image : null;
            }
        }

        return null;
    }

    public function statuses(): array
    {
        $allowedStates = ['running', 'stopped', 'not_created', 'busy', 'error'];
        $statuses = [];

        foreach (self::targets() as $service => $target) {
            $status = [
                'service' => $service,
                'state' => 'not_created',
                'message_key' => 'services.status_unavailable',
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
            if ($this->daemon()->status()['state'] === 'running' && $this->hasBlockingRequests($service)) {
                $status['state'] = 'busy';
                $status['message_key'] = 'services.processing';
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
            ['start', 'stop', 'restart', 'create', 'pull-recreate', 'delete'],
        );
    }

    public function request(string $service, string $action): string
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('services.invalid_service', 400);
        }
        if (!in_array($action, ['start', 'stop', 'restart', 'create', 'pull-recreate'], true)) {
            throw new HttpException('services.invalid_action', 400);
        }

        $this->daemon()->assertRunning();

        $requestDir = $this->basePath . '/requests';
        if (!is_dir($requestDir) && !mkdir($requestDir, 0775, true) && !is_dir($requestDir)) {
            throw new HttpException('services.request_failed', 500);
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
            throw new HttpException('services.request_failed', 500);
        }

        return $requestId;
    }

    public function deleteContainer(string $service): void
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('services.invalid_service', 400);
        }

        $container = (string) $targets[$service]['container'];
        DockerLiveState::resetCache();
        if (!DockerExec::removeNamedContainer($container)) {
            throw new HttpException('services.delete_failed', 500);
        }
        DockerLiveState::resetCache();
        $this->persistStatus($service, 'not_created', 'services.deleted', '');
    }

    public function deleteImage(string $service): void
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('services.invalid_service', 400);
        }

        $container = (string) $targets[$service]['container'];
        DockerLiveState::resetCache();
        if (DockerExec::containerIdByName($container) !== null) {
            throw new HttpException('services.delete_image_container_exists', 400);
        }

        $image = $targets[$service]['image'] ?? null;
        if (!is_string($image) || $image === '') {
            throw new HttpException('services.no_image', 400);
        }
        if (!DockerExec::removeImage($image)) {
            throw new HttpException('services.delete_image_failed', 500);
        }
    }

    private function persistStatus(string $service, string $state, string $messageKey, string $requestId): void
    {
        $statusDir = $this->basePath . '/status';
        if (!is_dir($statusDir) && !mkdir($statusDir, 0775, true) && !is_dir($statusDir)) {
            throw new HttpException('services.request_failed', 500);
        }

        $payload = json_encode([
            'service' => $service,
            'state' => $state,
            'message_key' => $messageKey,
            'request_id' => $requestId,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        if (!AtomicFile::write($statusDir . '/' . $service . '.json', $payload)) {
            throw new HttpException('services.request_failed', 500);
        }
    }

    /**
     * @return array{
     *     service: string,
     *     state: string,
     *     message_key: string,
     *     request_id: string,
     *     available: bool,
     *     content: string,
     *     create_log: string,
     *     start_log: string,
     *     docker_log: string,
     *     updated_at: string
     * }
     */
    public function actionLogs(string $service, int $dockerTail = 120): array
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('services.invalid_service', 400);
        }

        $bundle = ActionLogReader::bundle(
            $this->basePath . '/status',
            $service,
            static fn (array $decoded): bool => ($decoded['service'] ?? null) === $service,
        );

        $docker = $this->logs($service, $dockerTail);
        $dockerLog = $docker['available'] ? (string) ($docker['content'] ?? '') : '';
        $content = $bundle['content'];
        if ($dockerLog !== '') {
            $content = $content !== '' ? $content . "\n\n=== container ===\n" . $dockerLog : "=== container ===\n" . $dockerLog;
        }

        return array_merge($bundle, [
            'service' => $service,
            'docker_log' => $dockerLog,
            'available' => $bundle['available'] || $dockerLog !== '',
            'content' => $content,
        ]);
    }

    /**
     * @return array{service: string, container: string, available: bool, content: string, truncated: bool, updated_at: string}
     */
    public function logs(string $service, int $tail = 300): array
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('services.invalid_service', 400);
        }
        $container = (string) $targets[$service]['container'];
        $tail = max(1, min(2000, $tail));
        $content = DockerExec::containerLogs($container, $tail);
        $available = $content !== null;
        $text = $available ? (string) $content : '';

        return [
            'service' => $service,
            'container' => $container,
            'available' => $available,
            'content' => $text,
            'truncated' => $available && strlen($text) >= 262144,
            'updated_at' => date(DATE_ATOM),
        ];
    }
}
