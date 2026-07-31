<?php

declare(strict_types=1);

function manager_php_versions(): array
{
    return [
        'php-8.2' => [
            'label' => 'PHP 8.2 (default)',
            'container' => 'php8.2_container',
            'source_prefix' => '/var/www/source_php8.2',
            'profile' => null,
        ],
        'php-8.1' => [
            'label' => 'PHP 8.1',
            'container' => 'php8.1_container',
            'source_prefix' => '/var/www/source_php8.1',
            'profile' => 'php-8.1',
        ],
        'php-8.0' => [
            'label' => 'PHP 8.0',
            'container' => 'php8.0_container',
            'source_prefix' => '/var/www/source_php8.0',
            'profile' => 'php-8.0',
        ],
        'php-7.4' => [
            'label' => 'PHP 7.4',
            'container' => 'php7.4_container',
            'source_prefix' => '/var/www/source_php7.4',
            'profile' => 'php-7.4',
        ],
    ];
}

function manager_load_servers(string $path, string $locale = 'en'): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException(manager_translate($locale, 'error.env_missing'));
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException(manager_translate($locale, 'error.env_read'));
    }

    $servers = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($servers)) {
        throw new RuntimeException(manager_translate($locale, 'error.env_object'));
    }

    return $servers;
}

function manager_save_servers(string $path, array $servers, string $locale = 'en'): void
{
    uksort($servers, static function (string $left, string $right): int {
        return manager_key_number($left) <=> manager_key_number($right);
    });

    $json = json_encode(
        $servers,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException(manager_translate($locale, 'error.env_open'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(manager_translate($locale, 'error.env_lock'));
        }
        if (!ftruncate($handle, 0) || rewind($handle) === false) {
            throw new RuntimeException(manager_translate($locale, 'error.env_prepare'));
        }
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException(manager_translate($locale, 'error.env_write'));
        }
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function manager_key_number(string $key): int
{
    return preg_match('/^SERVER_NAME(\d+)$/', $key, $matches) ? (int) $matches[1] : PHP_INT_MAX;
}

function manager_next_key(array $servers): string
{
    $highest = 0;
    foreach (array_keys($servers) as $key) {
        if (preg_match('/^SERVER_NAME(\d+)$/', (string) $key, $matches)) {
            $highest = max($highest, (int) $matches[1]);
        }
    }
    return 'SERVER_NAME' . ($highest + 1);
}

function manager_version_from_container(string $container): string
{
    foreach (manager_php_versions() as $version => $config) {
        if ($config['container'] === $container) {
            return $version;
        }
    }
    return 'php-8.2';
}

function manager_validate_server(array $input, array $servers, ?string $currentKey = null): array
{
    $versions = manager_php_versions();
    $appName = trim((string) ($input['app_name'] ?? ''));
    $domainName = strtolower(trim((string) ($input['domain_name'] ?? '')));
    $serverPath = rtrim(trim((string) ($input['server_path'] ?? '')), '/');
    $phpVersion = (string) ($input['php_version'] ?? '');
    $errors = [];

    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/', $appName)) {
        $errors['app_name'] = ['key' => 'validation.app_name'];
    }

    if ($domainName === '' || filter_var($domainName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        $errors['domain_name'] = ['key' => 'validation.domain'];
    }

    if (!isset($versions[$phpVersion])) {
        $errors['php_version'] = ['key' => 'validation.php_version'];
    } else {
        $prefix = $versions[$phpVersion]['source_prefix'];
        if (
            ($serverPath !== $prefix && !str_starts_with($serverPath, $prefix . '/'))
            || str_contains($serverPath, '..')
            || !preg_match('#^/var/www/[a-zA-Z0-9._/-]+$#', $serverPath)
            || preg_match('/[\x00-\x1F\x7F]/', $serverPath)
        ) {
            $errors['server_path'] = [
                'key' => 'validation.safe_path',
                'parameters' => ['prefix' => $prefix],
            ];
        }
    }

    foreach ($servers as $key => $server) {
        if ($key === $currentKey) {
            continue;
        }
        if (strcasecmp((string) ($server['APP_NAME'] ?? ''), $appName) === 0) {
            $errors['app_name'] = ['key' => 'validation.duplicate_app'];
        }
        if (strcasecmp((string) ($server['DOMAIN_NAME'] ?? ''), $domainName) === 0) {
            $errors['domain_name'] = ['key' => 'validation.duplicate_domain'];
        }
    }

    return [
        'errors' => $errors,
        'server' => [
            'APP_NAME' => $appName,
            'DOMAIN_NAME' => $domainName,
            'SERVER_PATH' => $serverPath,
            'CONTAINER_PHP_VERSION' => $versions[$phpVersion]['container'] ?? '',
        ],
        'php_version' => $phpVersion,
    ];
}

function manager_required_profiles(array $servers): array
{
    $profiles = [];
    foreach ($servers as $server) {
        $version = manager_version_from_container((string) ($server['CONTAINER_PHP_VERSION'] ?? ''));
        $profile = manager_php_versions()[$version]['profile'];
        if ($profile !== null) {
            $profiles[$profile] = true;
        }
    }
    $result = array_keys($profiles);
    sort($result);
    return $result;
}

function manager_php_controller_targets(): array
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

function manager_request_php_action(string $basePath, string $service, string $action): string
{
    $targets = manager_php_controller_targets();
    if (!isset($targets[$service])) {
        throw new InvalidArgumentException('php_controller.invalid_service');
    }
    if (!in_array($action, ['start', 'stop', 'restart'], true)) {
        throw new InvalidArgumentException('php_controller.invalid_action');
    }

    $requestDir = rtrim($basePath, '/') . '/requests';
    if (!is_dir($requestDir) && !mkdir($requestDir, 0775, true) && !is_dir($requestDir)) {
        throw new RuntimeException('php_controller.request_failed');
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
        throw new RuntimeException('php_controller.request_failed');
    }

    return $requestId;
}

function manager_load_php_statuses(string $basePath): array
{
    $basePath = rtrim($basePath, '/');
    $allowedStates = ['running', 'stopped', 'not_created', 'busy', 'error'];
    $statuses = [];

    foreach (manager_php_controller_targets() as $service => $target) {
        $status = [
            'service' => $service,
            'state' => 'not_created',
            'message_key' => 'php_controller.status_unavailable',
            'request_id' => '',
            'updated_at' => '',
        ];
        $statusFile = $basePath . '/status/' . $service . '.json';
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
        if (glob($basePath . '/requests/*__' . $service . '__*.json')) {
            $status['state'] = 'busy';
            $status['message_key'] = 'php_controller.processing';
        }
        $statuses[$service] = $status;
    }

    return $statuses;
}
