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
            if ($this->hasBlockingRequests($service)) {
                $status['state'] = 'busy';
                $status['message_key'] = 'php_controller.processing';
            }
            $statuses[$service] = $status;
        }

        return $statuses;
    }

    /** True when start/stop/restart/create/install-ext is queued — not modules probes. */
    public function hasBlockingRequests(string $service): bool
    {
        foreach (glob($this->basePath . '/requests/*__' . $service . '__*.json') ?: [] as $file) {
            if (preg_match('/__(?:start|stop|restart|create|install-ext)/', basename($file))) {
                return true;
            }
        }

        return false;
    }

    public function request(string $service, string $action, ?string $extension = null): string
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('php_controller.invalid_service', 400);
        }
        $allowed = ['start', 'stop', 'restart', 'create', 'modules', 'install-ext'];
        if (!in_array($action, $allowed, true)) {
            throw new HttpException('php_controller.invalid_action', 400);
        }
        if ($action === 'create' && ($targets[$service]['profile'] ?? null) === null) {
            throw new HttpException('php_controller.invalid_action', 400);
        }
        if ($action === 'install-ext') {
            if ($extension === null || !PhpExtensionCatalog::isCurated($extension) || !preg_match('/^[a-z0-9_]+$/', $extension)) {
                throw new HttpException('php_controller.invalid_extension', 400);
            }
            if (PhpExtensionCatalog::unsupportedOn($service, $extension)) {
                throw new HttpException('php_controller.unsupported_extension', 400);
            }
        } elseif ($extension !== null) {
            throw new HttpException('php_controller.invalid_action', 400);
        }

        $requestDir = $this->basePath . '/requests';
        if (!is_dir($requestDir) && !mkdir($requestDir, 0775, true) && !is_dir($requestDir)) {
            throw new HttpException('php_controller.request_failed', 500);
        }

        $requestId = bin2hex(random_bytes(16));
        $payload = [
            'request_id' => $requestId,
            'service' => $service,
            'action' => $action,
            'requested_at' => date(DATE_ATOM),
        ];
        if ($action === 'install-ext') {
            $payload['extension'] = $extension;
        }
        $request = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $suffix = $action === 'install-ext' ? ($action . '-' . $extension) : $action;
        $finalPath = $requestDir . '/' . $requestId . '__' . $service . '__' . $suffix . '.json';
        $tempPath = $finalPath . '.tmp';

        if (file_put_contents($tempPath, $request, LOCK_EX) === false || !rename($tempPath, $finalPath)) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
            throw new HttpException('php_controller.request_failed', 500);
        }

        return $requestId;
    }

    public function readModules(string $service): array
    {
        $defaults = [
            'service' => $service,
            'modules' => [],
            'updated_at' => '',
            'request_id' => '',
            'ok' => false,
        ];
        $file = $this->basePath . '/status/' . $service . '.modules.json';
        if (!is_file($file) || !is_readable($file)) {
            return $defaults;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || ($decoded['service'] ?? null) !== $service) {
            return $defaults;
        }
        $modules = $decoded['modules'] ?? [];
        if (!is_array($modules)) {
            $modules = [];
        }
        $modules = array_values(array_filter($modules, 'is_string'));

        return [
            'service' => $service,
            'modules' => $modules,
            'updated_at' => (string) ($decoded['updated_at'] ?? ''),
            'request_id' => (string) ($decoded['request_id'] ?? ''),
            'ok' => (bool) ($decoded['ok'] ?? false),
        ];
    }

    public function modulesStale(string $service, int $maxAgeSeconds = 30): bool
    {
        $info = $this->readModules($service);
        if ($info['updated_at'] === '') {
            return true;
        }
        $ts = strtotime($info['updated_at']);
        if ($ts === false) {
            return true;
        }

        return (time() - $ts) > $maxAgeSeconds;
    }
}
