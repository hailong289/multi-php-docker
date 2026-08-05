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
    public static function statesByName(int $ttlMs = 1200): array
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

        if (($status['state'] ?? null) !== $live) {
            $status['state'] = $live;
            $status['message_key'] = $refreshedMessageKey;
            $status['updated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
        }

        return $status;
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

        $raw = self::httpGet('/containers/json?all=true');
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

    private static function httpGet(string $path): ?string
    {
        $sock = self::socketPath();
        $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 1.5);
        if ($fp === false) {
            return null;
        }

        stream_set_timeout($fp, 2);
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

        return $parts[1];
    }
}
