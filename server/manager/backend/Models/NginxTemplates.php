<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

final class NginxTemplates
{
    private const MAX_BYTES = 524288;

    private readonly string $templatesPath;

    private readonly string $runtimePath;

    public function __construct(?string $templatesPath = null, ?string $runtimePath = null)
    {
        $this->templatesPath = rtrim(
            $templatesPath ?? (Config::projectPath() . '/nginx/templates'),
            '/'
        );
        $this->runtimePath = rtrim($runtimePath ?? Config::runtimePath(), '/');
    }

    /** @return list<array{name: string, size: int, updated_at: string}> */
    public function list(): array
    {
        if (!is_dir($this->templatesPath)) {
            return [];
        }

        $files = [];
        foreach (glob($this->templatesPath . '/*.template') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            if (!$this->isSafeName($name)) {
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

    /** @return array{name: string, content: string, size: int, updated_at: string} */
    public function read(string $name): array
    {
        $path = $this->resolvePath($name);
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException('nginx.template_missing', 404);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new HttpException('nginx.template_read_failed', 500);
        }
        if (!preg_match('//u', $content)) {
            $content = (string) iconv('UTF-8', 'UTF-8//IGNORE', $content);
        }

        return [
            'name' => basename($path),
            'content' => $content,
            'size' => strlen($content),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
        ];
    }

    /** @return array{name: string, size: int, updated_at: string, soft_reload: bool} */
    public function save(string $name, string $content, bool $softReload = true): array
    {
        $path = $this->resolvePath($name);
        if (!is_file($path)) {
            throw new HttpException('nginx.template_missing', 404);
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new HttpException('nginx.template_too_large', 422);
        }
        if (!preg_match('//u', $content)) {
            throw new HttpException('nginx.template_invalid_utf8', 422);
        }

        $dir = dirname($path);
        if (!is_writable($dir) || !is_writable($path)) {
            throw new HttpException('nginx.template_not_writable', 500);
        }

        $temp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            throw new HttpException('nginx.template_write_failed', 500);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new HttpException('nginx.template_write_failed', 500);
        }

        clearstatcache(true, $path);

        if ($softReload) {
            $this->requestSoftReload();
        }

        return [
            'name' => basename($path),
            'size' => (int) filesize($path),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
            'soft_reload' => $softReload,
        ];
    }

    public function requestSoftReload(): void
    {
        if (!is_dir($this->runtimePath) && !mkdir($this->runtimePath, 0775, true) && !is_dir($this->runtimePath)) {
            throw new HttpException('error.runtime_directory', 500);
        }
        if (file_put_contents($this->runtimePath . '/nginx.soft-reload', date(DATE_ATOM), LOCK_EX) === false) {
            throw new HttpException('nginx.soft_reload_request_failed', 500);
        }
    }

    private function isSafeName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\.template$/', $name);
    }

    private function resolvePath(string $name): string
    {
        $name = basename(str_replace(['\\', "\0"], '', $name));
        if (!$this->isSafeName($name)) {
            throw new HttpException('nginx.template_invalid_name', 400);
        }

        $base = realpath($this->templatesPath);
        if ($base === false || !is_dir($base)) {
            throw new HttpException('nginx.templates_missing', 500);
        }

        $candidate = $base . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) {
            $real = realpath($candidate);
            if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                throw new HttpException('nginx.template_invalid_name', 400);
            }

            return $real;
        }

        // Allow resolve for existence checks before create — we only edit existing files.
        if (!str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
            throw new HttpException('nginx.template_invalid_name', 400);
        }

        return $candidate;
    }
}
