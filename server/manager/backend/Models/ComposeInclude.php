<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

/** Ensure compose/*.yml fragments are listed in docker-compose.yml include. */
final class ComposeInclude
{
    private readonly string $projectPath;

    public function __construct(?string $projectPath = null)
    {
        $this->projectPath = rtrim($projectPath ?? Config::projectPath(), '/');
    }

    public function isIncluded(string $filename): bool
    {
        $name = $this->safeFilename($filename);
        $path = $this->projectPath . '/docker-compose.yml';
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $content = $this->normalizeNewlines((string) file_get_contents($path));

        return (bool) preg_match(
            '/^  - path: compose\/' . preg_quote($name, '/') . '\s*$/m',
            $content,
        );
    }

    public function ensureIncluded(string $filename): void
    {
        $name = $this->safeFilename($filename);
        if ($this->isIncluded($name)) {
            return;
        }

        $path = $this->projectPath . '/docker-compose.yml';
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $content = $this->normalizeNewlines((string) file_get_contents($path));
        $entry = "  - path: compose/{$name}\n    project_directory: .\n";

        if (preg_match('/^  - path: compose\/redis\.yml\s*$/m', $content)) {
            $content = preg_replace(
                '/^  - path: compose\/redis\.yml\s*$/m',
                rtrim($entry) . "\n  - path: compose/redis.yml",
                $content,
                1,
            );
        } elseif (preg_match('/^  - path: compose\/rabbitmq\.yml\s*$/m', $content)) {
            $content = preg_replace(
                '/^  - path: compose\/rabbitmq\.yml\s*$/m',
                rtrim($entry) . "\n  - path: compose/rabbitmq.yml",
                $content,
                1,
            );
        } elseif (preg_match('/^  - path: compose\/mysql\.yml\s*$/m', $content)) {
            $content = preg_replace(
                '/^  - path: compose\/mysql\.yml\s*$/m',
                rtrim($entry) . "\n  - path: compose/mysql.yml",
                $content,
                1,
            );
        } else {
            throw new HttpException('services.compose_include_failed', 500);
        }

        if (!is_string($content) || $content === '') {
            throw new HttpException('services.compose_include_failed', 500);
        }
        $this->writeFile($path, $content);
    }

    public function removeIncluded(string $filename): void
    {
        $name = $this->safeFilename($filename);
        $path = $this->projectPath . '/docker-compose.yml';
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $content = $this->normalizeNewlines((string) file_get_contents($path));
        $pattern = '/^  - path: compose\/' . preg_quote($name, '/') . '\s*$\n    project_directory: \.\s*$\n?/m';
        $updated = preg_replace($pattern, '', $content, 1);
        if (!is_string($updated) || $updated === $content) {
            return;
        }
        $this->writeFile($path, $updated);
    }

    private function safeFilename(string $filename): string
    {
        $name = basename(str_replace(['\\', "\0"], '', $filename));
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\.(yml|yaml)$/', $name)) {
            throw new HttpException('services.compose_invalid_name', 400);
        }

        return $name;
    }

    private function normalizeNewlines(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_writable($dir)) {
            throw new HttpException('services.compose_include_failed', 500);
        }
        $temp = $dir . '/.docker-compose.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            throw new HttpException('services.compose_include_failed', 500);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new HttpException('services.compose_include_failed', 500);
        }
    }
}
