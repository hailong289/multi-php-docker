<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

/**
 * List/read/write/delete *.yml|*.yaml under compose/.
 * Core service files (mysql|postgres|redis|rabbitmq).yml cannot be deleted.
 */
final class InfraCompose
{
    private const MAX_BYTES = 524288;

    /** @var list<string> */
    public const CORE_FILES = ['mysql.yml', 'postgres.yml', 'redis.yml', 'rabbitmq.yml'];

    private readonly string $projectPath;

    public function __construct(?string $projectPath = null)
    {
        $this->projectPath = rtrim($projectPath ?? Config::projectPath(), '/');
    }

    public function composeDirAbsolute(): string
    {
        $path = $this->projectPath . '/compose';
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new HttpException('services.compose_dir_create_failed', 500);
        }
        $real = realpath($path);
        if ($real === false) {
            throw new HttpException('services.compose_dir_invalid', 500);
        }

        return $real;
    }

    /** Relative path under the project root for a known infra service. */
    public function relativePath(string $service): string
    {
        $targets = InfraRuntime::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('services.invalid_service', 400);
        }

        return 'compose/' . $service . '.yml';
    }

    public function isCoreFile(string $name): bool
    {
        return in_array(strtolower($name), self::CORE_FILES, true);
    }

    /** Core infra + installer-managed PHP/supervisor fragments cannot be deleted. */
    public function isProtectedFile(string $name): bool
    {
        if ($this->isCoreFile($name)) {
            return true;
        }
        $lower = strtolower($name);

        return (bool) preg_match('/^(php-[0-9]|supervisor).*\.(yml|yaml)$/', $lower);
    }

    /** Map mysql.yml → mysql when it is a managed infra service. */
    public function serviceForFile(string $name): ?string
    {
        if (!preg_match('/^(mysql|postgres|redis|rabbitmq)\.ya?ml$/i', $name, $m)) {
            return null;
        }

        return strtolower($m[1]);
    }

    public function defaultContent(): string
    {
        return <<<'YAML'
# Optional Compose fragment. Include it from docker-compose.yml if needed.
services:
  # example:
  #   profiles: ["example"]
  #   image: example:latest
  #   container_name: example_container
  #   networks:
  #     - app-network

# networks:
#   app-network:
#     external: true
YAML;
    }

    /** @return list<array{name: string, relative_path: string, size: int, updated_at: string, core: bool, service: ?string}> */
    public function list(): array
    {
        $dir = $this->composeDirAbsolute();
        $files = [];
        $paths = array_merge(
            glob($dir . '/*.yml') ?: [],
            glob($dir . '/*.yaml') ?: []
        );
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            if (!$this->isSafeName($name)) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'relative_path' => 'compose/' . $name,
                'size' => (int) filesize($path),
                'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
                'core' => $this->isCoreFile($name),
                'protected' => $this->isProtectedFile($name),
                'service' => $this->serviceForFile($name),
            ];
        }
        usort($files, static function (array $a, array $b): int {
            if ($a['core'] !== $b['core']) {
                return $a['core'] ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $files;
    }

    /**
     * @return array{name: string, relative_path: string, content: string, size: int, updated_at: string, core: bool, service: ?string}
     */
    public function readFile(string $name): array
    {
        $path = $this->resolvePath($name, false);
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException('services.compose_missing', 404);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new HttpException('services.compose_read_failed', 500);
        }
        if (!preg_match('//u', $content)) {
            $content = (string) iconv('UTF-8', 'UTF-8//IGNORE', $content);
        }
        $base = basename($path);

        return [
            'name' => $base,
            'relative_path' => 'compose/' . $base,
            'content' => $content,
            'size' => strlen($content),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
            'core' => $this->isCoreFile($base),
            'protected' => $this->isProtectedFile($base),
            'service' => $this->serviceForFile($base),
        ];
    }

    /**
     * @return array{name: string, relative_path: string, size: int, updated_at: string, core: bool, service: ?string, created: bool}
     */
    public function writeFile(string $name, string $content, bool $create): array
    {
        $path = $this->resolvePath($name, $create);
        if ($create && is_file($path)) {
            throw new HttpException('services.compose_exists', 409);
        }
        if (!$create && !is_file($path)) {
            throw new HttpException('services.compose_missing', 404);
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new HttpException('services.compose_too_large', 422);
        }
        if ($content === '' || !preg_match('//u', $content)) {
            throw new HttpException('services.compose_invalid_utf8', 422);
        }

        $dir = dirname($path);
        if (!is_writable($dir) || (is_file($path) && !is_writable($path))) {
            throw new HttpException('services.compose_not_writable', 500);
        }

        $temp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            throw new HttpException('services.compose_write_failed', 500);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new HttpException('services.compose_write_failed', 500);
        }
        clearstatcache(true, $path);
        $base = basename($path);

        return [
            'name' => $base,
            'relative_path' => 'compose/' . $base,
            'size' => (int) filesize($path),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
            'core' => $this->isCoreFile($base),
            'protected' => $this->isProtectedFile($base),
            'service' => $this->serviceForFile($base),
            'created' => $create,
        ];
    }

    public function deleteFile(string $name): void
    {
        $path = $this->resolvePath($name, false);
        $base = basename($path);
        if ($this->isProtectedFile($base)) {
            throw new HttpException('services.compose_core_protected', 403);
        }
        if (!is_file($path)) {
            throw new HttpException('services.compose_missing', 404);
        }
        if (!is_writable($path) || !unlink($path)) {
            throw new HttpException('services.compose_delete_failed', 500);
        }
    }

    /** @return array{service: string, relative_path: string, content: string, size: int, updated_at: string} */
    public function read(string $service): array
    {
        $name = $service . '.yml';
        $file = $this->readFile($name);

        return [
            'service' => $service,
            'relative_path' => $file['relative_path'],
            'content' => $file['content'],
            'size' => $file['size'],
            'updated_at' => $file['updated_at'],
        ];
    }

    /** @return array{service: string, relative_path: string, size: int, updated_at: string} */
    public function write(string $service, string $content): array
    {
        $name = $service . '.yml';
        $saved = $this->writeFile($name, $content, false);

        return [
            'service' => $service,
            'relative_path' => $saved['relative_path'],
            'size' => $saved['size'],
            'updated_at' => $saved['updated_at'],
        ];
    }

    private function isSafeName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\.(yml|yaml)$/', $name);
    }

    private function resolvePath(string $name, bool $forCreate): string
    {
        $name = basename(str_replace(['\\', "\0"], '', $name));
        if (!$this->isSafeName($name)) {
            throw new HttpException('services.compose_invalid_name', 400);
        }

        $base = $this->composeDirAbsolute();
        $candidate = $base . DIRECTORY_SEPARATOR . $name;

        if (is_file($candidate)) {
            $real = realpath($candidate);
            if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                throw new HttpException('services.compose_invalid_name', 400);
            }

            return $real;
        }

        if (!$forCreate && !is_file($candidate)) {
            return $candidate;
        }

        if (!str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
            throw new HttpException('services.compose_invalid_name', 400);
        }

        return $candidate;
    }
}
