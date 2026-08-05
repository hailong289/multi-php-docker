<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

final class EnvConfig
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? Config::envPath();
    }

    public function all(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new HttpException('error.env_missing', 500);
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new HttpException('error.env_read', 500);
        }

        try {
            $servers = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException('error.env_object', 500);
        }

        if (!is_array($servers)) {
            throw new HttpException('error.env_object', 500);
        }

        return $servers;
    }

    public function save(array $servers): void
    {
        uksort($servers, static function (string $left, string $right): int {
            return self::keyNumber($left) <=> self::keyNumber($right);
        });

        $json = json_encode(
            $servers,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new HttpException('error.env_open', 500);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new HttpException('error.env_lock', 500);
            }
            if (!ftruncate($handle, 0) || rewind($handle) === false) {
                throw new HttpException('error.env_prepare', 500);
            }
            if (fwrite($handle, $json) !== strlen($json)) {
                throw new HttpException('error.env_write', 500);
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    public function nextKey(array $servers): string
    {
        $highest = 0;
        foreach (array_keys($servers) as $key) {
            if (preg_match('/^SERVER_NAME(\d+)$/', (string) $key, $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'SERVER_NAME' . ($highest + 1);
    }

    public function validate(array $input, array $servers, ?string $currentKey = null): array
    {
        $versions = PhpVersionCatalog::versions();
        $appName = trim((string) ($input['app_name'] ?? ''));
        $domainName = strtolower(trim((string) ($input['domain_name'] ?? '')));
        $serverPath = rtrim(trim((string) ($input['server_path'] ?? '')), '/');
        $phpVersion = (string) ($input['php_version'] ?? '');
        $enabled = self::normalizeEnabled($input['enabled'] ?? $input['ENABLED'] ?? true);
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
                'ENABLED' => $enabled,
            ],
            'php_version' => $phpVersion,
        ];
    }

    public static function isEnabled(array $server): bool
    {
        return self::normalizeEnabled($server['ENABLED'] ?? true);
    }

    public function requiredProfiles(array $servers): array
    {
        $profiles = [];
        foreach ($servers as $server) {
            if (!self::isEnabled($server)) {
                continue;
            }
            $version = PhpVersionCatalog::versionFromContainer((string) ($server['CONTAINER_PHP_VERSION'] ?? ''));
            $profile = PhpVersionCatalog::versions()[$version]['profile'] ?? null;
            if ($profile !== null) {
                $profiles[$profile] = true;
            }
        }
        $result = array_keys($profiles);
        sort($result);
        return $result;
    }

    public function applyCommand(array $servers): string
    {
        $profiles = $this->requiredProfiles($servers);
        $profileFlags = implode(' ', array_map(
            static fn (string $profile): string => '--profile ' . $profile,
            $profiles
        ));

        return 'docker compose' . ($profileFlags !== '' ? ' ' . $profileFlags : '') . ' up -d' . "\n"
            . 'docker compose restart nginx';
    }

    private static function normalizeEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function keyNumber(string $key): int
    {
        return preg_match('/^SERVER_NAME(\d+)$/', $key, $matches) ? (int) $matches[1] : PHP_INT_MAX;
    }
}
