<?php

declare(strict_types=1);

namespace Manager\Support;

/**
 * Exclusive status locks for compose helpers and in-container extension execs.
 * fopen mode "x" is the O_EXCL create; two php -S workers cannot both win.
 */
final class JobLock
{
    public const COMPOSE = 'exec.json';

    public const ID_GRACE_SECONDS = 15;

    public const MAX_SECONDS = 600;

    public static function path(string $basePath, string $name): string
    {
        return rtrim($basePath, '/') . '/status/' . $name;
    }

    public static function extensionName(string $service): string
    {
        return 'exec-ext-' . $service . '.json';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function exclusiveCreate(string $path, array $payload): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            return false;
        }
        $ok = fwrite($fh, $json) !== false;
        fclose($fh);
        if (!$ok) {
            @unlink($path);
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function merge(string $path, array $fields): bool
    {
        $current = self::read($path);
        if ($current === null) {
            return false;
        }

        return AtomicFile::write(
            $path,
            json_encode(array_merge($current, $fields), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = JsonFile::readObject($path);

        return is_array($decoded) ? $decoded : null;
    }

    public static function ageSeconds(array $lock): int
    {
        $started = $lock['started_at'] ?? '';
        if (!is_string($started) || $started === '') {
            return self::MAX_SECONDS + 1;
        }
        $ts = strtotime($started);
        if ($ts === false) {
            return self::MAX_SECONDS + 1;
        }

        return max(0, time() - $ts);
    }

    public static function release(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $lock
     */
    public static function markError(string $basePath, array $lock): void
    {
        $service = $lock['service'] ?? '';
        $queueKey = $lock['queue_key'] ?? '';
        $requestId = is_string($lock['request_id'] ?? null) ? $lock['request_id'] : '';
        $updated = gmdate('Y-m-d\TH:i:s\Z');
        if (is_string($queueKey) && $queueKey !== '') {
            $payload = [
                'compose_file' => (string) ($lock['compose_file'] ?? ''),
                'queue_key' => $queueKey,
                'state' => 'error',
                'message_key' => 'php_controller.action_failed',
                'request_id' => $requestId,
                'updated_at' => $updated,
            ];
            $name = $queueKey . '.json';
        } elseif (is_string($service) && $service !== '') {
            $payload = [
                'service' => $service,
                'state' => 'error',
                'message_key' => 'php_controller.action_failed',
                'request_id' => $requestId,
                'updated_at' => $updated,
            ];
            $name = $service . '.json';
        } else {
            return;
        }
        $statusPath = self::path($basePath, $name);
        $existing = self::read($statusPath);
        if (is_array($existing) && ($existing['state'] ?? '') === 'busy') {
            AtomicFile::write($statusPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        } elseif (!is_file($statusPath)) {
            AtomicFile::write($statusPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }
    }
}
