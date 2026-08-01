<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

final class PhpRuntime
{
    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? Config::phpControllerPath(), '/');
    }

    public static function targets(): array
    {
        return [
            'php-8.2' => [
                'label' => 'PHP 8.2',
                'container' => 'php8.2_container',
                'profile' => null,
                'create_command' => 'docker compose create php-8.2',
            ],
            'php-8.1' => [
                'label' => 'PHP 8.1',
                'container' => 'php8.1_container',
                'profile' => 'php-8.1',
                'create_command' => 'docker compose --profile php-8.1 create php-8.1',
            ],
            'php-8.0' => [
                'label' => 'PHP 8.0',
                'container' => 'php8.0_container',
                'profile' => 'php-8.0',
                'create_command' => 'docker compose --profile php-8.0 create php-8.0',
            ],
            'php-7.4' => [
                'label' => 'PHP 7.4',
                'container' => 'php7.4_container',
                'profile' => 'php-7.4',
                'create_command' => 'docker compose --profile php-7.4 create php-7.4',
            ],
        ];
    }

    public function statuses(): array
    {
        $allowedStates = ['running', 'stopped', 'not_created', 'busy', 'error'];
        $statuses = [];

        foreach (self::targets() as $service => $target) {
            $status = [
                'service' => $service,
                'state' => 'not_created',
                'message_key' => 'php_controller.status_unavailable',
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
            if (glob($this->basePath . '/requests/*__' . $service . '__*.json')) {
                $status['state'] = 'busy';
                $status['message_key'] = 'php_controller.processing';
            }
            $statuses[$service] = $status;
        }

        return $statuses;
    }

    public function request(string $service, string $action): string
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('php_controller.invalid_service', 400);
        }
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new HttpException('php_controller.invalid_action', 400);
        }

        $requestDir = $this->basePath . '/requests';
        if (!is_dir($requestDir) && !mkdir($requestDir, 0775, true) && !is_dir($requestDir)) {
            throw new HttpException('php_controller.request_failed', 500);
        }

        $requestId = bin2hex(random_bytes(16));
        $request = json_encode([
            'request_id' => $requestId,
            'service' => $service,
            'action' => $action,
            'requested_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $finalPath = $requestDir . '/' . $requestId . '__' . $service . '__' . $action . '.json';
        $tempPath = $finalPath . '.tmp';

        if (file_put_contents($tempPath, $request, LOCK_EX) === false || !rename($tempPath, $finalPath)) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
            throw new HttpException('php_controller.request_failed', 500);
        }

        return $requestId;
    }
}
