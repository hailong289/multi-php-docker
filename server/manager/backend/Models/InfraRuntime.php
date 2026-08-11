<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\AtomicFile;
use Manager\Support\Config;
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

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? Config::phpControllerPath(), '/');
    }

    public static function targets(): array
    {
        $targets = [];
        foreach (self::SERVICES as $service => $config) {
            $targets[$service] = [
                'label' => $config['label'],
                'container' => $config['container'],
                'profile' => $config['profile'],
                'ports' => $config['ports'],
                'compose_file' => 'compose/' . $service . '.yml',
                'create_command' => 'docker compose --profile ' . $config['profile'] . ' create ' . $service,
            ];
        }

        return $targets;
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
            if ($this->hasBlockingRequests($service)) {
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
        foreach (glob($this->basePath . '/requests/*__' . $service . '__*.json') ?: [] as $file) {
            if (preg_match('/__(?:start|stop|restart|create|pull-recreate)/', basename($file))) {
                return true;
            }
        }

        return false;
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
}
