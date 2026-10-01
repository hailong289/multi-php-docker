<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;
use Manager\Support\FileLogFollow;

/**
 * Application logs under each source project. Paths are relative to the project
 * directory (document-root suffix stripped), with a per-framework preset and an
 * optional LOG_PATH override stored on the server record.
 */
final class SourceLogs
{
    public const SOURCE_PREFIX = '/var/www/source';

    /** Largest log body that can be loaded or saved in the editor. */
    public const MAX_EDIT_BYTES = 1048576;

    /** @var list<string> */
    public const FRAMEWORKS = [
        'laravel',
        'symfony',
        'wordpress',
        'codeigniter',
        'yii',
        'cakephp',
        'slim',
        'drupal',
        'plain',
        'custom',
    ];

    /**
     * Relative log location. null means the framework has no standard path.
     *
     * @var array<string, string|null>
     */
    public const PRESETS = [
        'laravel' => 'storage/logs',
        'symfony' => 'var/log',
        'codeigniter' => 'writable/logs',
        'yii' => 'runtime/logs',
        'cakephp' => 'logs',
        'wordpress' => 'wp-content/debug.log',
        'slim' => null,
        'drupal' => null,
        'plain' => null,
        'custom' => null,
    ];

    public function __construct(
        private readonly ?string $projectPath = null,
        private readonly ?EnvConfig $env = null,
    ) {
    }

    public static function isFramework(string $id): bool
    {
        return in_array($id, self::FRAMEWORKS, true);
    }

    public static function presetFor(string $framework): ?string
    {
        if (!self::isFramework($framework)) {
            return null;
        }

        return self::PRESETS[$framework];
    }

    /**
     * Empty string is a cleared override. null means the value is unsafe.
     */
    public static function normalizeRelative(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        if (str_contains($path, "\0") || str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }
        if (!preg_match('#^[a-zA-Z0-9._/-]+$#', $path)) {
            return null;
        }

        return $path;
    }

    public static function isLogFileName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.log$/', $name);
    }

    public static function detectFramework(string $serverPath): string
    {
        $path = rtrim(str_replace('\\', '/', $serverPath), '/');
        if ($path === '') {
            return 'laravel';
        }
        $rel = $path;
        $prefix = self::SOURCE_PREFIX;
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            $rel = substr($path, strlen($prefix));
        }
        if (str_ends_with($rel, '/webroot')) {
            return 'cakephp';
        }
        if (str_ends_with($rel, '/web')) {
            return 'yii';
        }
        if (str_ends_with($rel, '/public')) {
            return 'laravel';
        }
        $parts = array_values(array_filter(explode('/', ltrim($rel, '/')), static fn (string $part): bool => $part !== ''));
        if (count($parts) === 1) {
            return 'plain';
        }

        return 'custom';
    }

    /** @param array<string, mixed> $server */
    public static function frameworkOf(array $server): string
    {
        $stored = (string) ($server['FRAMEWORK'] ?? '');
        if (self::isFramework($stored)) {
            return $stored;
        }

        return self::detectFramework((string) ($server['SERVER_PATH'] ?? ''));
    }

    /** @param array<string, mixed> $server */
    public static function effectiveRelative(array $server): string
    {
        $override = self::normalizeRelative((string) ($server['LOG_PATH'] ?? ''));
        if ($override === null) {
            $override = '';
        }
        if ($override !== '') {
            return $override;
        }
        $preset = self::presetFor(self::frameworkOf($server));

        return $preset ?? '';
    }

    public function hostRoot(): string
    {
        return rtrim($this->projectPath ?? Config::projectPath(), '/') . '/server/source';
    }

    public function hostProjectDir(string $serverPath): string
    {
        $container = TerminalSession::projectDirFromServerPath($serverPath);
        if ($container === '' || $container === self::SOURCE_PREFIX) {
            return $container === self::SOURCE_PREFIX ? $this->hostRoot() : '';
        }
        if (!str_starts_with($container, self::SOURCE_PREFIX . '/')) {
            return '';
        }

        return $this->hostRoot() . substr($container, strlen(self::SOURCE_PREFIX));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $items = [];
        foreach ($this->env()->allOrEmpty() as $key => $server) {
            if (!is_array($server)) {
                continue;
            }
            $items[] = $this->describe((string) $key, $server);
        }
        usort($items, static fn (array $a, array $b): int => strcasecmp((string) $a['app_name'], (string) $b['app_name']));

        return $items;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function describe(string $key, array $server): array
    {
        $serverPath = (string) ($server['SERVER_PATH'] ?? '');
        $containerProject = TerminalSession::projectDirFromServerPath($serverPath);
        $framework = self::frameworkOf($server);
        $stored = self::isFramework((string) ($server['FRAMEWORK'] ?? ''));
        $override = self::normalizeRelative((string) ($server['LOG_PATH'] ?? ''));
        if ($override === null) {
            $override = '';
        }
        $preset = self::presetFor($framework);
        $relative = $override !== '' ? $override : ($preset ?? '');
        $hostProject = $this->hostProjectDir($serverPath);
        $located = $relative !== '' && $hostProject !== '' ? $this->locate($hostProject, $relative) : null;
        $kind = 'unset';
        $files = [];
        if ($relative === '') {
            $kind = 'unset';
        } elseif ($located === null) {
            $kind = 'missing';
        } elseif ($located['type'] === 'dir') {
            $kind = 'directory';
            $files = $this->listLogFiles($located['real'], $hostProject);
        } elseif ($located['type'] === 'file') {
            $kind = 'file';
            $files = [$this->fileMeta($located['real'])];
        } else {
            $kind = 'missing';
        }

        return [
            'key' => $key,
            'app_name' => (string) ($server['APP_NAME'] ?? ''),
            'domain_name' => (string) ($server['DOMAIN_NAME'] ?? ''),
            'server_path' => $serverPath,
            'project_dir' => $containerProject,
            'framework' => $framework,
            'framework_stored' => $stored,
            'preset' => $preset,
            'log_path' => $override,
            'relative' => $relative,
            'resolved' => $relative !== '' && $containerProject !== '' ? $containerProject . '/' . $relative : '',
            'kind' => $kind,
            'files' => $files,
        ];
    }

    /**
     * @return array{source: array<string, mixed>, file: string, log: array{available: bool, content: string, updated_at: string, size: int}}
     */
    public function read(string $key, string $file = '', int $lines = 200, bool $full = false): array
    {
        [$server, $source] = $this->requireSource($key);
        $path = $this->resolveFile($server, $source, $file);
        $meta = $full ? $this->readWholeFile($path) : $this->tailFile($path, $lines);
        $meta['size'] = is_file($path) ? (int) filesize($path) : 0;

        return [
            'source' => $this->describe($key, $server),
            'file' => basename($path),
            'log' => $meta,
        ];
    }

    /**
     * @return array{source: array<string, mixed>, file: string, log: array{available: bool, content: string, updated_at: string, size: int}}
     */
    public function write(string $key, string $file, string $content): array
    {
        if (strlen($content) > self::MAX_EDIT_BYTES) {
            throw new HttpException('source_logs.too_large', 413);
        }
        [$server] = $this->requireSource($key);
        $source = $this->describe($key, $server);
        $path = $this->resolveFile($server, $source, $file);
        if (!is_file($path) || !is_writable($path)) {
            throw new HttpException('source_logs.write_failed', 500);
        }
        if (file_put_contents($path, $content) === false) {
            throw new HttpException('source_logs.write_failed', 500);
        }

        return $this->read($key, basename($path), 200, true);
    }

    /**
     * @return array{source: array<string, mixed>, file: string}
     */
    public function delete(string $key, string $file): array
    {
        [$server] = $this->requireSource($key);
        $source = $this->describe($key, $server);
        $path = $this->resolveFile($server, $source, $file);
        if (!is_file($path) || !is_writable($path) || !unlink($path)) {
            throw new HttpException('source_logs.delete_failed', 500);
        }

        return [
            'source' => $this->describe($key, $server),
            'file' => basename($path),
        ];
    }

    /**
     * @return array{source: array<string, mixed>, file: string, log: array{available: bool, content: string, updated_at: string, size: int}}
     */
    public function clear(string $key, string $file): array
    {
        [$server] = $this->requireSource($key);
        $source = $this->describe($key, $server);
        $path = $this->resolveFile($server, $source, $file);
        if (!is_file($path) || !is_writable($path)) {
            throw new HttpException('source_logs.clear_failed', 500);
        }
        if (file_put_contents($path, '') === false) {
            throw new HttpException('source_logs.clear_failed', 500);
        }

        return $this->read($key, basename($path));
    }

    /**
     * @return array<string, mixed>
     */
    public function saveConfig(string $key, string $framework, string $logPath): array
    {
        $env = $this->env();
        $servers = $env->all();
        if (!isset($servers[$key]) || !is_array($servers[$key])) {
            throw new HttpException('error.server_missing', 404);
        }
        if (!self::isFramework($framework)) {
            throw new HttpException('validation.failed', 422, [
                'framework' => ['key' => 'validation.framework'],
            ]);
        }
        $normalized = self::normalizeRelative($logPath);
        if ($normalized === null) {
            throw new HttpException('validation.failed', 422, [
                'log_path' => ['key' => 'validation.log_path'],
            ]);
        }
        if ($normalized === '' && self::presetFor($framework) === null) {
            throw new HttpException('validation.failed', 422, [
                'log_path' => ['key' => 'validation.log_path_required'],
            ]);
        }

        $server = $servers[$key];
        $server['FRAMEWORK'] = $framework;
        if ($normalized === '') {
            unset($server['LOG_PATH']);
        } else {
            $server['LOG_PATH'] = $normalized;
        }
        $servers[$key] = $server;
        $env->save($servers);

        return $this->describe($key, $server);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function requireSource(string $key): array
    {
        if (!preg_match('/^SERVER_NAME\d+$/', $key)) {
            throw new HttpException('error.server_missing', 404);
        }
        $servers = $this->env()->allOrEmpty();
        if (!isset($servers[$key]) || !is_array($servers[$key])) {
            throw new HttpException('error.server_missing', 404);
        }
        $server = $servers[$key];

        return [$server, $this->describe($key, $server)];
    }

    /** @param array<string, mixed> $server
     *  @param array<string, mixed> $source
     */
    private function resolveFile(array $server, array $source, string $file): string
    {
        $relative = (string) ($source['relative'] ?? '');
        if ($relative === '') {
            throw new HttpException('source_logs.path_unset', 400);
        }
        $hostProject = $this->hostProjectDir((string) ($server['SERVER_PATH'] ?? ''));
        $located = $hostProject !== '' ? $this->locate($hostProject, $relative) : null;
        if ($located === null) {
            throw new HttpException('source_logs.missing', 404);
        }

        if ($located['type'] === 'file') {
            if ($file !== '' && $file !== basename($located['real'])) {
                throw new HttpException('source_logs.file_missing', 404);
            }

            return $located['real'];
        }

        if ($located['type'] !== 'dir' || !self::isLogFileName($file)) {
            throw new HttpException('source_logs.file_missing', 404);
        }
        $path = $this->locate($hostProject, $relative . '/' . $file);
        if ($path === null || $path['type'] !== 'file') {
            throw new HttpException('source_logs.file_missing', 404);
        }

        return $path['real'];
    }

    /**
     * @return array{real: string, type: 'dir'|'file'|'other'}|null
     */
    private function locate(string $hostProject, string $relative): ?array
    {
        $normalized = self::normalizeRelative($relative);
        if ($normalized === null || $normalized === '') {
            return null;
        }
        $projectReal = realpath($hostProject);
        if ($projectReal === false || !is_dir($projectReal)) {
            return null;
        }
        $candidate = $projectReal . '/' . $normalized;
        $real = realpath($candidate);
        if ($real === false || !$this->isInside($projectReal, $real)) {
            return null;
        }
        $type = 'other';
        if (is_dir($real)) {
            $type = 'dir';
        } elseif (is_file($real)) {
            $type = 'file';
        }

        return ['real' => $real, 'type' => $type];
    }

    /**
     * @return list<array{name: string, size: int, updated_at: string}>
     */
    private function listLogFiles(string $dirReal, string $hostProject): array
    {
        $projectReal = realpath($hostProject);
        if ($projectReal === false) {
            return [];
        }
        $files = [];
        foreach (scandir($dirReal) ?: [] as $name) {
            if (!self::isLogFileName($name)) {
                continue;
            }
            $path = $dirReal . '/' . $name;
            if (is_link($path)) {
                $real = realpath($path);
                if ($real === false || !$this->isInside($projectReal, $real) || !is_file($real)) {
                    continue;
                }
                $path = $real;
            } elseif (!is_file($path)) {
                continue;
            }
            $files[] = $this->fileMeta($path);
        }
        usort($files, static function (array $a, array $b): int {
            return strcmp((string) $b['updated_at'], (string) $a['updated_at']);
        });

        return $files;
    }

    /** @return array{name: string, size: int, updated_at: string} */
    private function fileMeta(string $path): array
    {
        return [
            'name' => basename($path),
            'size' => (int) filesize($path),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
        ];
    }

    private function isInside(string $projectReal, string $path): bool
    {
        return $path === $projectReal || str_starts_with($path, $projectReal . '/');
    }

    /** @return array{available: bool, content: string, updated_at: string, full: bool} */
    private function readWholeFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['available' => false, 'content' => '', 'updated_at' => '', 'full' => true];
        }
        $size = (int) filesize($path);
        if ($size > self::MAX_EDIT_BYTES) {
            throw new HttpException('source_logs.too_large', 413);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return ['available' => false, 'content' => '', 'updated_at' => '', 'full' => true];
        }
        if (!preg_match('//u', $content)) {
            $content = (string) iconv('UTF-8', 'UTF-8//IGNORE', $content);
        }

        return [
            'available' => true,
            'content' => $content,
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
            'full' => true,
        ];
    }

    /** @return array{available: bool, content: string, updated_at: string} */
    private function tailFile(string $path, int $lines = 200): array
    {
        $lines = max(1, min(500, $lines));
        if (!is_file($path) || !is_readable($path)) {
            return ['available' => false, 'content' => '', 'updated_at' => ''];
        }
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['available' => false, 'content' => '', 'updated_at' => ''];
        }
        $offset = max(0, $size - 1048576);
        fseek($handle, $offset);
        if ($offset > 0) {
            fgets($handle);
        }
        $content = (string) stream_get_contents($handle);
        fclose($handle);
        $rows = preg_split('/\r\n|\n|\r/', $content) ?: [];
        if ($rows !== [] && end($rows) === '') {
            array_pop($rows);
        }
        $content = implode(PHP_EOL, array_slice($rows, -$lines));
        if (!preg_match('//u', $content)) {
            $content = (string) iconv('UTF-8', 'UTF-8//IGNORE', $content);
        }

        return [
            'available' => true,
            'content' => $content,
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
        ];
    }

    public function resolvedPath(string $key, string $file): string
    {
        [$server, $source] = $this->requireSource($key);

        return $this->resolveFile($server, $source, $file);
    }

    /**
     * One follow tick. A shrink or replaced file is a reset (new tail). Growth is an append.
     *
     * @return array{event: 'append'|'reset'|'gone', content: string, offset: int, inode: int, size: int, updated_at: string}
     */
    public function readFollowChunk(string $path, int $offset, int $inode): array
    {
        return FileLogFollow::readChunk($path, $offset, $inode);
    }

    public function follow(string $path): void
    {
        FileLogFollow::follow($path);
    }

    private function env(): EnvConfig
    {
        return $this->env ?? new EnvConfig();
    }
}
