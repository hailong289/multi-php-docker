<?php

declare(strict_types=1);

namespace Manager\Support;

/**
 * Write files through a temp path, with a copy fallback when rename() fails.
 * Docker Desktop bind mounts (especially on macOS) frequently refuse rename.
 */
final class AtomicFile
{
    public static function write(string $path, string $content): bool
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
            return false;
        }

        if (@rename($tempPath, $path)) {
            return true;
        }

        $ok = file_put_contents($path, $content, LOCK_EX) !== false;
        if (is_file($tempPath)) {
            @unlink($tempPath);
        }

        return $ok;
    }
}
