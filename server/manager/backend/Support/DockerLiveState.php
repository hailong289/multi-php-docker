<?php

declare(strict_types=1);

namespace Manager\Support;

/**
 * Live container state via the Docker Engine API (unix socket).
 * Used so the UI stays accurate when containers are stopped outside the manager.
 */
final class DockerLiveState
{
    private static ?array $cache = null;

    private static int $cacheAtMs = 0;

    private static bool $lastFetchOk = false;

    public static function socketPath(): string
    {
        $configured = getenv('MANAGER_DOCKER_SOCK');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return '/var/run/docker.sock';
    }

    public static function available(): bool
    {
        $sock = self::socketPath();

        return file_exists($sock);
    }

    /**
     * @return array<string, 'running'|'stopped'|'not_created'>
     */
    public static function statesByName(int $ttlMs = 400): array
    {
        $now = (int) floor(microtime(true) * 1000);
        if (self::$cache !== null && ($now - self::$cacheAtMs) < $ttlMs) {
            return self::$cache;
        }

        $states = self::fetchStates();
        if (self::$lastFetchOk) {
            self::$cache = $states;
            self::$cacheAtMs = $now;
        }

        return self::$lastFetchOk ? $states : (self::$cache ?? []);
    }

    /**
     * @return 'running'|'stopped'|'not_created'|null null = live probe unavailable
     */
    public static function stateFor(string $containerName): ?string
    {
        if ($containerName === '' || !self::available()) {
            return null;
        }

        $states = self::statesByName();
        if (!self::$lastFetchOk && self::$cache === null) {
            return null;
        }

        return $states[$containerName] ?? 'not_created';
    }

    /**
     * Prefer live Docker state unless UI action shows busy.
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    public static function apply(array $status, string $containerName, string $refreshedMessageKey): array
    {
        if (($status['state'] ?? '') === 'busy') {
            return $status;
        }

        $live = self::stateFor($containerName);
        if ($live === null) {
            return $status;
        }

        // Keep failed create/install as error when no container exists so Create can retry.
        if (($status['state'] ?? '') === 'error' && $live === 'not_created') {
            return $status;
        }

        // The controller just wrote this file. A cached container list from
        // before that write would keep the UI on "stopped" for the TTL.
        if (self::controllerStatusIsFresher($status)) {
            return $status;
        }

        if (($status['state'] ?? null) !== $live) {
            $status['state'] = $live;
            $status['message_key'] = $refreshedMessageKey;
            $status['updated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
        }

        return $status;
    }

    /**
     * Status timestamps are whole seconds. Treat that second as newer than a
     * cache snapshot taken during it, so a just-finished start is visible.
     *
     * @param array<string, mixed> $status
     */
    private static function controllerStatusIsFresher(array $status): bool
    {
        if (self::$cache === null) {
            return false;
        }
        $updated = $status['updated_at'] ?? '';
        if (!is_string($updated) || $updated === '') {
            return false;
        }
        $ts = strtotime($updated);
        if ($ts === false) {
            return false;
        }

        return (($ts + 1) * 1000) > self::$cacheAtMs;
    }

    /** @internal testing */
    public static function resetCache(): void
    {
        self::$cache = null;
        self::$cacheAtMs = 0;
        self::$lastFetchOk = false;
    }

    /**
     * @return array<string, 'running'|'stopped'|'not_created'>
     */
    private static function fetchStates(): array
    {
        self::$lastFetchOk = false;
        if (!self::available()) {
            return [];
        }

        $raw = self::engineGet('/containers/json?all=true');
        if ($raw === null) {
            return [];
        }

        try {
            $list = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($list)) {
            return [];
        }

        $states = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $dockerState = strtolower((string) ($item['State'] ?? ''));
            $normalized = $dockerState === 'running' ? 'running' : 'stopped';
            $names = $item['Names'] ?? [];
            if (!is_array($names)) {
                continue;
            }
            foreach ($names as $name) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $states[ltrim($name, '/')] = $normalized;
            }
        }

        self::$lastFetchOk = true;

        return $states;
    }

    public static function engineGet(string $path, int $timeoutSeconds = 2): ?string
    {
        $sock = self::socketPath();
        $timeoutSeconds = max(1, min(8, $timeoutSeconds));
        $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 1.5);
        if ($fp === false) {
            return null;
        }

        stream_set_timeout($fp, $timeoutSeconds);
        $request = "GET {$path} HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n";
        if (fwrite($fp, $request) === false) {
            fclose($fp);

            return null;
        }

        $response = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($response) || $response === '') {
            return null;
        }

        $parts = explode("\r\n\r\n", $response, 2);
        if (count($parts) < 2) {
            return null;
        }

        $headers = $parts[0];
        if (!preg_match('/^HTTP\/\d\.\d\s+200\b/', $headers)) {
            return null;
        }

        $body = $parts[1];
        if (preg_match('/^Transfer-Encoding:\s*chunked\b/mi', $headers)) {
            $body = self::decodeChunked($body);
        }

        return $body;
    }

    private static function decodeChunked(string $body): string
    {
        $out = '';
        $offset = 0;
        $len = strlen($body);
        while ($offset < $len) {
            $nl = strpos($body, "\r\n", $offset);
            if ($nl === false) {
                break;
            }
            $sizeLine = substr($body, $offset, $nl - $offset);
            if (str_contains($sizeLine, ';')) {
                $sizeLine = explode(';', $sizeLine, 2)[0];
            }
            $size = hexdec(trim($sizeLine));
            $offset = $nl + 2;
            if ($size === 0) {
                break;
            }
            if ($offset + $size > $len) {
                break;
            }
            $out .= substr($body, $offset, $size);
            $offset += $size;
            if (substr($body, $offset, 2) === "\r\n") {
                $offset += 2;
            }
        }

        return $out;
    }
}
