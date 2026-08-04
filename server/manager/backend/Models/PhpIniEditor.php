<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

final class PhpIniEditor
{
    private const MAX_BYTES = 262144; // 256 KiB

    private static array $paths = [
        'php-8.2' => 'configs/php8/php.ini',
        'php-8.1' => 'configs/php8.1/php.ini',
        'php-8.0' => 'configs/php8.0/php.ini',
        'php-7.4' => 'configs/php7.4/php.ini',
    ];

    public function __construct(private readonly string $projectPath = '')
    {
    }

    private function root(): string
    {
        return rtrim($this->projectPath !== '' ? $this->projectPath : Config::projectPath(), '/');
    }

    public static function relativePath(string $service): string
    {
        if (!isset(self::$paths[$service])) {
            throw new HttpException('php_controller.invalid_service', 404);
        }

        return self::$paths[$service];
    }

    public function absolutePath(string $service): string
    {
        $rel = self::relativePath($service);
        $full = $this->root() . '/' . $rel;
        $realRoot = realpath($this->root());
        if ($realRoot === false) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }
        $dir = dirname($full);
        if (!is_dir($dir)) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }
        $realDir = realpath($dir);
        if ($realDir === false || !str_starts_with($realDir, $realRoot)) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }

        return $realDir . '/' . basename($full);
    }

    public function read(string $service): string
    {
        $path = $this->absolutePath($service);
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }

        return $content;
    }

    public function write(string $service, string $content): void
    {
        if (str_contains($content, "\0")) {
            throw new HttpException('php_controller.ini_invalid', 400);
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new HttpException('php_controller.ini_too_large', 400);
        }
        $path = $this->absolutePath($service);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new HttpException('php_controller.ini_write_failed', 500);
        }
    }

    public function extensionLineStatus(string $iniContent, string $name): string
    {
        $name = preg_quote($name, '/');
        if (preg_match('/^\s*extension\s*=\s*' . $name . '(?:\.so)?\s*;?\s*$/mi', $iniContent)) {
            return 'active';
        }
        if (preg_match('/^\s*;\s*extension\s*=\s*' . $name . '(?:\.so)?\s*;?\s*$/mi', $iniContent)) {
            return 'commented';
        }

        return 'absent';
    }

    /** Pure transform used by enable/disable and unit checks. */
    public function toggleExtensionContent(string $iniContent, string $name, bool $enable): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new HttpException('php_controller.invalid_extension', 400);
        }
        $lines = preg_split("/\r\n|\n|\r/", $iniContent) ?: [];
        $patternActive = '/^\s*extension\s*=\s*' . preg_quote($name, '/') . '(?:\.so)?\s*;?\s*$/i';
        $patternCommented = '/^\s*;\s*extension\s*=\s*' . preg_quote($name, '/') . '(?:\.so)?\s*;?\s*$/i';
        $found = false;
        foreach ($lines as $i => $line) {
            if (preg_match($patternActive, $line) || preg_match($patternCommented, $line)) {
                $found = true;
                $lines[$i] = $enable
                    ? ('extension=' . $name . '.so')
                    : (';extension=' . $name . '.so');
            }
        }
        if ($enable && !$found) {
            $lines[] = 'extension=' . $name . '.so';
        }
        $out = implode("\n", $lines);
        if (str_ends_with($iniContent, "\n") && !str_ends_with($out, "\n")) {
            $out .= "\n";
        }

        return $out;
    }

    public function enableExtension(string $service, string $name): void
    {
        $this->write($service, $this->toggleExtensionContent($this->read($service), $name, true));
    }

    public function disableExtension(string $service, string $name): void
    {
        $this->write($service, $this->toggleExtensionContent($this->read($service), $name, false));
    }
}
