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
        return DockerEndpoint::localSocketPath();
    }

    public static function available(): bool
    {
        return DockerEndpoint::available();
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

        // Keep failed create/install as error when no container exists so Create can retry.
        if (($status['state'] ?? '') === 'error' && $live === 'not_created') {
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
        return DockerEndpoint::request('GET', $path, null, null, [200], null, 1.5);
    }
}
