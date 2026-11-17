<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\ActionLogReader;
use Manager\Support\AtomicFile;
use Manager\Support\ComposeJobs;
use Manager\Support\Config;
use Manager\Support\ContainerControl;
use Manager\Support\DockerExec;
use Manager\Support\DockerImageIndex;
use Manager\Support\DockerLiveState;
use Manager\Support\HostPorts;
use Manager\Support\JsonFile;

final class InfraRuntime
{
    private const SERVICES = [
        'mysql' => [
            'label' => 'MySQL',
            'container' => 'mysql_container',
            'profile' => 'mysql',
        ],
        'postgres' => [
            'label' => 'PostgreSQL',
            'container' => 'postgres_container',
            'profile' => 'postgres',
        ],
        'redis' => [
            'label' => 'Redis',
            'container' => 'redis_container',
            'profile' => 'redis',
        ],
        'rabbitmq' => [
            'label' => 'RabbitMQ',
            'container' => 'rabbitmq_container',
            'profile' => 'rabbitmq',
        ],
        'kafka' => [
            'label' => 'Kafka',
            'container' => 'kafka_container',
            'profile' => 'kafka',
        ],
        'mailpit' => [
            'label' => 'Mailpit',
            'container' => 'mailpit_container',
            'profile' => 'mailpit',
        ],
        'minio' => [
            'label' => 'MinIO',
            'container' => 'minio_container',
            'profile' => 'minio',
        ],
    ];

    private readonly string $basePath;

    private readonly ComposeJobs $jobs;

    public function __construct(?string $basePath = null, ?ComposeJobs $jobs = null)
    {
        $this->basePath = rtrim($basePath ?? Config::phpControllerPath(), '/');
        $this->jobs = $jobs ?? new ComposeJobs();
    }

    public static function targets(): array
    {
        $projectPath = rtrim(Config::projectPath(), '/');
        $targets = [];
        foreach (self::SERVICES as $service => $config) {
            $image = self::imageFromCompose($projectPath, $service);
            $imagePresent = $image !== null && DockerImageIndex::contains($image);
            $hostPorts = HostPorts::read($service);
            $targets[$service] = [
                'label' => $config['label'],
                'container' => $config['container'],
                'profile' => $config['profile'],
                'ports' => implode(', ', $hostPorts),
                'host_ports' => $hostPorts,
                'ports_configurable' => HostPorts::configurable($service, $projectPath . '/compose/' . $service . '.yml'),
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

        foreach (self::SERVICES as $service => $config) {
            $status = [
                'service' => $service,
                'state' => 'not_created',
                'message_key' => 'services.status_unavailable',
                'request_id' => '',
                'updated_at' => '',
            ];
            $statusFile = $this->basePath . '/status/' . $service . '.json';
            $decoded = JsonFile::readObject($statusFile);
            if (
                is_array($decoded)
                && ($decoded['service'] ?? null) === $service
                && in_array(($decoded['state'] ?? null), $allowedStates, true)
            ) {
                $status = array_merge($status, array_intersect_key($decoded, $status));
            }
            if ($this->hasBlockingRequests($service)) {
                $status['state'] = 'busy';
                $status['message_key'] = 'services.processing';
            } else {
                $status = DockerLiveState::apply(
                    $status,
                    (string) ($config['container'] ?? ''),
                    'php_controller.status_refreshed'
                );
            }
            $statuses[$service] = $status;
        }

        return $statuses;
    }

    public function hasBlockingRequests(string $service): bool
    {
        return $this->jobs->blocks($this->basePath, $service);
    }

    public function request(string $service, string $action): string
    {
        if (!isset(self::SERVICES[$service])) {
            throw new HttpException('services.invalid_service', 400);
        }
        if (!in_array($action, ['start', 'stop', 'restart', 'create', 'pull-recreate'], true)) {
            throw new HttpException('services.invalid_action', 400);
        }

        if (in_array($action, ['start', 'stop', 'restart'], true)) {
            $state = ContainerControl::apply((string) self::SERVICES[$service]['container'], $action);
            if ($state === null) {
                $detail = ContainerControl::lastDetail();
                if ($detail !== '') {
                    throw new HttpException('services.container_control_failed', 502, [], ['detail' => $detail]);
                }
                throw new HttpException('services.request_failed', 502);
            }
            if (!ContainerControl::writeStatus($this->basePath, $service, $state, 'php_controller.action_success')) {
                throw new HttpException('services.request_failed', 500);
            }

            return bin2hex(random_bytes(16));
        }

        return $this->jobs->begin($this->basePath, [
            'service' => $service,
            'action' => $action,
        ]);
    }

    /**
     * Saves host ports to .env; recreates the container when it exists so the new mapping applies.
     *
     * @param array<string, mixed> $ports key => port, keys from HostPorts::MAP
     * @return string request id of the recreate job, '' when the container is not created
     */
    public function updatePorts(string $service, array $ports, ?string $envPath = null, ?string $composeFile = null): string
    {
        if (!isset(self::SERVICES[$service])) {
            throw new HttpException('services.invalid_service', 400);
        }
        $composeFile ??= rtrim(Config::projectPath(), '/') . '/compose/' . $service . '.yml';
        if (!HostPorts::configurable($service, $composeFile)) {
            throw new HttpException('ports.not_configurable', 400);
        }
        if ($this->hasBlockingRequests($service)) {
            throw new HttpException('php_controller.busy', 409);
        }
        HostPorts::save($service, $ports, $envPath);
        // A stopped container keeps its old port mapping on `docker start`, so recreate it too.
        if (!in_array(DockerLiveState::stateFor((string) self::SERVICES[$service]['container']), ['running', 'stopped'], true)) {
            return '';
        }

        return $this->jobs->begin($this->basePath, [
            'service' => $service,
            'action' => 'recreate',
        ]);
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
