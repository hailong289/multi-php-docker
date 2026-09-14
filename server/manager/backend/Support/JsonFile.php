<?php

declare(strict_types=1);

namespace Manager\Support;

/**
 * Read JSON object files without emitting warnings on missing/racy paths.
 * Status files under runtime/.../status/ can vanish between is_file() and read.
 */
final class JsonFile
{
    public static function readObject(string $path): ?array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
