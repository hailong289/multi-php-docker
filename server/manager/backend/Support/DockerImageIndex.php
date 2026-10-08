<?php

declare(strict_types=1);

namespace Manager\Support;

/**
 * Local image names from one GET /images/json, cached briefly.
 * Avoids per-image GET /images/{name}/json on status and start responses.
 */
final class DockerImageIndex
{
    /** @var array<string, true>|null */
    private static ?array $refs = null;

    private static int $cacheAtMs = 0;

    private static bool $lastFetchOk = false;

    public static function contains(string $ref, int $ttlMs = 1500): bool
    {
        $ref = trim($ref);
        if ($ref === '' || !DockerLiveState::available()) {
            return false;
        }

        $refs = self::refs($ttlMs);

        return isset($refs[$ref]) || isset($refs[self::normalize($ref)]);
    }

    /** @internal testing */
    public static function resetCache(): void
    {
        self::$refs = null;
        self::$cacheAtMs = 0;
        self::$lastFetchOk = false;
    }

    /**
     * @param list<string> $repoTags
     */
    public static function listed(string $ref, array $repoTags): bool
    {
        $ref = trim($ref);
        if ($ref === '') {
            return false;
        }
        $set = [];
        foreach ($repoTags as $tag) {
            if (!is_string($tag) || $tag === '' || $tag === '<none>:<none>') {
                continue;
            }
            $set[$tag] = true;
            $set[self::normalize($tag)] = true;
        }

        return isset($set[$ref]) || isset($set[self::normalize($ref)]);
    }

    public static function normalize(string $ref): string
    {
        $ref = trim($ref);
        if (str_starts_with($ref, 'docker.io/')) {
            $ref = substr($ref, strlen('docker.io/'));
        }
        if (str_starts_with($ref, 'library/')) {
            $ref = substr($ref, strlen('library/'));
        }
        if ($ref !== '' && !str_contains($ref, '@') && !str_contains($ref, ':')) {
            $ref .= ':latest';
        }

        return $ref;
    }

    /**
     * @return array<string, true>
     */
    private static function refs(int $ttlMs): array
    {
        $now = (int) floor(microtime(true) * 1000);
        if (self::$refs !== null && ($now - self::$cacheAtMs) < $ttlMs) {
            return self::$refs;
        }

        $fetched = self::fetchRefs();
        if (self::$lastFetchOk) {
            self::$refs = $fetched;
            self::$cacheAtMs = $now;
        }

        return self::$lastFetchOk ? $fetched : (self::$refs ?? []);
    }

    /**
     * @return array<string, true>
     */
    private static function fetchRefs(): array
    {
        self::$lastFetchOk = false;
        if (!DockerLiveState::available()) {
            return [];
        }

        $raw = DockerLiveState::engineGet('/images/json', 4);
        if ($raw === null) {
            return [];
        }

        try {
            $list = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($list)) {
            return [];
        }

        $set = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $tags = $item['RepoTags'] ?? [];
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    if (!is_string($tag) || $tag === '' || $tag === '<none>:<none>') {
                        continue;
                    }
                    $set[$tag] = true;
                    $set[self::normalize($tag)] = true;
                }
            }
            $digests = $item['RepoDigests'] ?? [];
            if (is_array($digests)) {
                foreach ($digests as $digest) {
                    if (is_string($digest) && $digest !== '' && $digest !== '<none>@<none>') {
                        $set[$digest] = true;
                    }
                }
            }
        }

        self::$lastFetchOk = true;

        return $set;
    }
}
