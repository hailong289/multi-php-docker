<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

/**
 * CRUD for Supervisor program files (*.conf) under the version conf.d directory.
 */
final class SupervisorConfigs
{
    private const MAX_BYTES = 262144;

    private readonly string $projectPath;

    public function __construct(?string $projectPath = null)
    {
        $this->projectPath = rtrim($projectPath ?? Config::projectPath(), '/');
    }

    /** Absolute conf.d directory for a supervisor compose service. */
    public function confDirAbsolute(string $supervisorService): string
    {
        $targets = SupervisorRuntime::targets($this->projectPath);
        if (!isset($targets[$supervisorService])) {
            throw new HttpException('supervisor.invalid_service', 400);
        }
        $relative = (string) ($targets[$supervisorService]['conf_dir'] ?? '');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new HttpException('supervisor.conf_dir_invalid', 500);
        }

        $path = $this->projectPath . '/' . $relative;
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new HttpException('supervisor.conf_dir_create_failed', 500);
        }

        $real = realpath($path);
        if ($real === false) {
            throw new HttpException('supervisor.conf_dir_invalid', 500);
        }

        return $real;
    }

    /** @return list<array{name: string, size: int, updated_at: string}> */
    public function list(string $supervisorService): array
    {
        $dir = $this->confDirAbsolute($supervisorService);
        $files = [];
        foreach (glob($dir . '/*.conf') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            if (!$this->isSafeName($name) || str_ends_with(strtolower($name), '.example.conf')) {
                continue;
            }
            // Skip example-style names that are not pure .conf worker files.
            if (str_contains(strtolower($name), '.example')) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'size' => (int) filesize($path),
                'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
            ];
        }
        usort($files, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $files;
    }

    /** @return array{name: string, content: string, size: int, updated_at: string, conf_dir: string} */
    public function read(string $supervisorService, string $name): array
    {
        $path = $this->resolvePath($supervisorService, $name, false);
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException('supervisor.conf_missing', 404);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new HttpException('supervisor.conf_read_failed', 500);
        }
        if (!preg_match('//u', $content)) {
            $content = (string) iconv('UTF-8', 'UTF-8//IGNORE', $content);
        }
        $targets = SupervisorRuntime::targets($this->projectPath);

        return [
            'name' => basename($path),
            'content' => $content,
            'size' => strlen($content),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
            'conf_dir' => (string) ($targets[$supervisorService]['conf_dir'] ?? ''),
        ];
    }

    /**
     * @return array{name: string, size: int, updated_at: string, created: bool}
     */
    public function write(string $supervisorService, string $name, string $content, bool $create): array
    {
        $path = $this->resolvePath($supervisorService, $name, $create);
        if ($create && is_file($path)) {
            throw new HttpException('supervisor.conf_exists', 409);
        }
        if (!$create && !is_file($path)) {
            throw new HttpException('supervisor.conf_missing', 404);
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new HttpException('supervisor.conf_too_large', 422);
        }
        if (!preg_match('//u', $content)) {
            throw new HttpException('supervisor.conf_invalid_utf8', 422);
        }

        $dir = dirname($path);
        if (!is_writable($dir) || (is_file($path) && !is_writable($path))) {
            throw new HttpException('supervisor.conf_not_writable', 500);
        }

        $temp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            throw new HttpException('supervisor.conf_write_failed', 500);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new HttpException('supervisor.conf_write_failed', 500);
        }
        clearstatcache(true, $path);

        return [
            'name' => basename($path),
            'size' => (int) filesize($path),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
            'created' => $create,
        ];
    }

    public function delete(string $supervisorService, string $name): void
    {
        $path = $this->resolvePath($supervisorService, $name, false);
        if (!is_file($path)) {
            throw new HttpException('supervisor.conf_missing', 404);
        }
        if (!is_writable($path) || !unlink($path)) {
            throw new HttpException('supervisor.conf_delete_failed', 500);
        }
    }

    public function defaultContent(string $supervisorService): string
    {
        $targets = SupervisorRuntime::targets($this->projectPath);
        $phpService = (string) ($targets[$supervisorService]['php_service'] ?? 'php-8.2');
        $source = 'source_php' . PhpVersionId::minorFromService($phpService) . PhpVersionId::pathSuffix($phpService);
        $example = $this->projectPath . '/configs/supervisor.d/worker.conf.example';
        if (is_file($example) && is_readable($example)) {
            $raw = (string) file_get_contents($example);
            return str_replace('source_php8.2', $source, $raw);
        }

        return <<<CONF
; Supervisord program config
[program:app_worker]
directory=/var/www/{$source}/my-project
command=php artisan queue:work --sleep=3 --tries=3 --timeout=90
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
startsecs=3
startretries=3
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/app-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
CONF;
    }

    private function isSafeName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\.conf$/', $name);
    }

    private function resolvePath(string $supervisorService, string $name, bool $forCreate): string
    {
        $name = basename(str_replace(['\\', "\0"], '', $name));
        if (!$this->isSafeName($name) || str_contains(strtolower($name), 'example')) {
            throw new HttpException('supervisor.conf_invalid_name', 400);
        }

        $base = $this->confDirAbsolute($supervisorService);
        $candidate = $base . DIRECTORY_SEPARATOR . $name;

        if (is_file($candidate)) {
            $real = realpath($candidate);
            if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                throw new HttpException('supervisor.conf_invalid_name', 400);
            }

            return $real;
        }

        if (!$forCreate) {
            return $candidate;
        }

        if (!str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
            throw new HttpException('supervisor.conf_invalid_name', 400);
        }

        return $candidate;
    }
}
