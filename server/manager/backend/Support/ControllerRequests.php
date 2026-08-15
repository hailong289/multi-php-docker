<?php

declare(strict_types=1);

namespace Manager\Support;

/**
 * php-controller request queue helpers.
 * Stale files leave Manager UI stuck on "processing" when the controller is down.
 */
final class ControllerRequests
{
    /** Drop queued actions older than this (seconds). */
    public const STALE_SECONDS = 600;

    public static function purgeStale(string $requestDir, int $ttlSeconds = self::STALE_SECONDS): void
    {
        if ($requestDir === '' || !is_dir($requestDir)) {
            return;
        }
        $ttlSeconds = max(60, $ttlSeconds);
        $now = time();
        foreach (glob(rtrim($requestDir, '/') . '/*.json') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime === false) {
                continue;
            }
            if (($now - $mtime) > $ttlSeconds) {
                @unlink($file);
            }
        }
    }

    /**
     * @param list<string> $actions action names that block the UI (start, stop, …)
     */
    public static function hasBlocking(string $requestDir, string $service, array $actions): bool
    {
        self::purgeStale($requestDir);
        if ($service === '' || $actions === []) {
            return false;
        }
        $actionRe = implode('|', array_map(
            static fn (string $a): string => preg_quote($a, '/'),
            $actions,
        ));
        foreach (glob(rtrim($requestDir, '/') . '/*__' . $service . '__*.json') ?: [] as $file) {
            if (preg_match('/__(?:' . $actionRe . ')(?:\.json)?$/', basename($file))) {
                return true;
            }
        }

        return false;
    }
}
