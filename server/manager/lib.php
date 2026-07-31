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

function manager_load_servers(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('env.json does not exist or is not readable.');
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read env.json.');
    }

    $servers = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($servers)) {
        throw new RuntimeException('env.json must contain a JSON object.');
    }

    return $servers;
}

function manager_save_servers(string $path, array $servers): void
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
        throw new RuntimeException('Unable to open env.json for writing.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock env.json.');
        }
        if (!ftruncate($handle, 0) || rewind($handle) === false) {
            throw new RuntimeException('Unable to prepare env.json for writing.');
        }
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Unable to write the complete env.json file.');
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
        $errors['app_name'] = 'Use 1-64 letters, numbers, dots, underscores, or hyphens.';
    }

    if ($domainName === '' || filter_var($domainName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        $errors['domain_name'] = 'Enter a valid hostname, for example my-app.test.';
    }

    if (!isset($versions[$phpVersion])) {
        $errors['php_version'] = 'Select a supported PHP version.';
    } else {
        $prefix = $versions[$phpVersion]['source_prefix'];
        if (
            ($serverPath !== $prefix && !str_starts_with($serverPath, $prefix . '/'))
            || str_contains($serverPath, '..')
            || !preg_match('#^/var/www/[a-zA-Z0-9._/-]+$#', $serverPath)
            || preg_match('/[\x00-\x1F\x7F]/', $serverPath)
        ) {
            $errors['server_path'] = 'Use a safe path inside ' . $prefix . ' without spaces or special characters.';
        }
    }

    foreach ($servers as $key => $server) {
        if ($key === $currentKey) {
            continue;
        }
        if (strcasecmp((string) ($server['APP_NAME'] ?? ''), $appName) === 0) {
            $errors['app_name'] = 'This application name already exists.';
        }
        if (strcasecmp((string) ($server['DOMAIN_NAME'] ?? ''), $domainName) === 0) {
            $errors['domain_name'] = 'This domain already exists.';
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
