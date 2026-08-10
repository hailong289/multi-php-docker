<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;
use RuntimeException;

final class DockerHubPhpTags
{
    private const HUB_TAGS_URL = 'https://hub.docker.com/v2/repositories/library/php/tags';

    /** Max Hub API pages to scan when resolving a version stem search. */
    private const STEM_SCAN_MAX_PAGES = 25;

    /** Concurrent Hub requests while scanning a version stem. */
    private const STEM_SCAN_CONCURRENCY = 5;

    /** Cache TTL for stem search collections (seconds). */
    private const STEM_CACHE_TTL = 3600;

    /**
     * Proxy Docker Hub PHP tags (FPM-focused).
     * Empty/non-version queries keep Hub order (newest updated first).
     * Version stems (e.g. 5.6, 7.4) scan Hub with a strict prefix match so old
     * releases are reachable without matching false positives like 8.5.6 for "5.6".
     *
     * @return array{versions: list<array<string,mixed>>, page: int, per_page: int, total: int, total_pages: int, name: string}
     */
    public function page(int $page, int $perPage, string $q, string $variant): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $q = strtolower(trim($q));
        $variant = in_array($variant, ['all', 'default', 'alpine', 'trixie'], true) ? $variant : 'all';

        $stem = $this->normalizeSearchStem($q);
        $installed = $this->installedTags();

        if ($stem !== '' && self::isVersionStem($stem)) {
            $hub = $this->collectStemMatches($stem, $variant);
            $rows = $hub['results'];
            $total = count($rows);
            $offset = ($page - 1) * $perPage;
            $slice = array_slice($rows, $offset, $perPage);
            $versions = [];
            foreach ($slice as $row) {
                $mapped = $this->mapHubRow($row, $installed);
                if ($mapped !== null) {
                    $versions[] = $mapped;
                }
            }

            return [
                'versions' => $versions,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
                'name' => $stem,
            ];
        }

        $name = $this->hubNameFilter($stem, $variant);
        $hub = $this->fetchHubPage($page, $perPage, $name, 'last_updated');
        $versions = [];
        foreach ($hub['results'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $this->mapHubRow($row, $installed);
            if ($mapped !== null) {
                $versions[] = $mapped;
            }
        }

        $total = (int) $hub['count'];

        return [
            'versions' => $versions,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'name' => $name,
        ];
    }

    public static function isVersionStem(string $stem): bool
    {
        return (bool) preg_match('/^\d+\.\d+(?:\.\d+)?$/', $stem);
    }

    /** Tag belongs to version stem (avoids 8.5.6 matching search "5.6"). */
    public static function tagMatchesStem(string $tag, string $stem): bool
    {
        $tag = strtolower($tag);
        $stem = strtolower($stem);

        return str_starts_with($tag, $stem . '-') || str_starts_with($tag, $stem . '.');
    }

    public static function isFpmTag(string $tag): bool
    {
        return (bool) preg_match('/-fpm(?:-|$)/', strtolower($tag));
    }

    /**
     * @param array<string, true> $installed
     * @return array<string, mixed>|null
     */
    private function mapHubRow(array $row, array $installed): ?array
    {
        $tag = strtolower(trim((string) ($row['name'] ?? '')));
        if ($tag === '') {
            return null;
        }

        $rowVariant = $this->variantFromTag($tag);
        $version = explode('-', $tag, 2)[0];
        $installable = PhpVersionId::isValidMinor($version)
            && (bool) preg_match(
                '/^' . preg_quote($version, '/') . '-fpm(?:-alpine|-trixie)?$/',
                $tag,
            );
        $service = $installable
            ? PhpVersionId::serviceFrom($version, $rowVariant)
            : ('php-tag-' . preg_replace('/[^a-z0-9.]+/', '-', $tag));
        $label = $installable ? PhpVersionId::label($service) : ('php:' . $tag);

        return [
            'version' => $installable ? $version : '',
            'variant' => $rowVariant,
            'label' => $label,
            'service' => $service,
            'tag' => $tag,
            'installable' => $installable,
            'last_updated' => (string) ($row['last_updated'] ?? ''),
            'installed' => $installable
                && (isset($installed[$tag])
                    || isset($installed[PhpVersionId::dockerTag($version, $rowVariant)])),
        ];
    }

    private function variantFromTag(string $tag): string
    {
        if (str_contains($tag, 'alpine')) {
            return 'alpine';
        }
        if (str_contains($tag, 'trixie')) {
            return 'trixie';
        }

        return 'default';
    }

    private function normalizeSearchStem(string $q): string
    {
        $q = strtolower(trim($q));
        $q = preg_replace('/\s+/', '', $q) ?? '';
        $stem = preg_replace('/-?(fpm)(-alpine|-trixie)?$/i', '', $q) ?? '';

        return rtrim($stem, '-./');
    }

    private function hubNameFilter(string $stem, string $variant): string
    {
        if ($variant === 'alpine') {
            return $stem === '' ? 'fpm-alpine' : $stem . '-fpm-alpine';
        }
        if ($variant === 'trixie') {
            return $stem === '' ? 'fpm-trixie' : $stem . '-fpm-trixie';
        }
        if ($variant === 'default') {
            return $stem === '' ? 'fpm' : $stem . '-fpm';
        }

        return $stem === '' ? 'fpm' : $stem . '-fpm';
    }

    /**
     * @return array{count: int, results: list<array<string,mixed>>}
     */
    private function collectStemMatches(string $stem, string $variant): array
    {
        $cached = $this->readStemCache($stem, $variant);
        if ($cached !== null) {
            return $cached;
        }

        $pageSize = 100;
        $first = $this->fetchHubPage(1, $pageSize, $stem, 'last_updated');
        $totalHub = (int) $first['count'];
        $totalPages = max(1, (int) ceil($totalHub / $pageSize));
        $totalPages = min($totalPages, self::STEM_SCAN_MAX_PAGES);

        $pageResults = [1 => $first['results']];
        $pending = [];
        for ($p = 2; $p <= $totalPages; $p++) {
            $pending[] = $p;
        }
        foreach (array_chunk($pending, self::STEM_SCAN_CONCURRENCY) as $chunk) {
            foreach ($this->fetchHubPages($chunk, $pageSize, $stem, 'last_updated') as $pageNum => $rows) {
                $pageResults[$pageNum] = $rows;
            }
        }

        ksort($pageResults);
        $matches = [];
        $seen = [];
        foreach ($pageResults as $batch) {
            foreach ($batch as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $tag = strtolower(trim((string) ($row['name'] ?? '')));
                if ($tag === '' || isset($seen[$tag])) {
                    continue;
                }
                if (!self::tagMatchesStem($tag, $stem) || !self::isFpmTag($tag)) {
                    continue;
                }
                $rowVariant = $this->variantFromTag($tag);
                if ($variant !== 'all' && $rowVariant !== $variant) {
                    continue;
                }
                $seen[$tag] = true;
                $matches[] = $row;
            }
        }

        $out = [
            'count' => count($matches),
            'results' => $matches,
        ];
        $this->writeStemCache($stem, $variant, $out);

        return $out;
    }

    /**
     * @param list<int> $pages
     * @return array<int, list<mixed>>
     */
    private function fetchHubPages(array $pages, int $pageSize, string $name, string $ordering): array
    {
        if ($pages === []) {
            return [];
        }

        $mh = curl_multi_init();
        if ($mh === false) {
            $out = [];
            foreach ($pages as $page) {
                $hub = $this->fetchHubPage($page, $pageSize, $name, $ordering);
                $out[$page] = $hub['results'];
            }

            return $out;
        }

        $handles = [];
        foreach ($pages as $page) {
            $query = [
                'page' => $page,
                'page_size' => $pageSize,
                'ordering' => $ordering,
            ];
            if ($name !== '') {
                $query['name'] = $name;
            }
            $url = self::HUB_TAGS_URL . '?' . http_build_query($query);
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: MultiPhpManager/1.0',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$page] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $page => $ch) {
            $body = curl_multi_getcontent($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            unset($ch);
            if (!is_string($body) || $httpStatus < 200 || $httpStatus >= 300) {
                $out[$page] = [];
                continue;
            }
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $out[$page] = [];
                continue;
            }
            $results = is_array($decoded) && isset($decoded['results']) && is_array($decoded['results'])
                ? array_values($decoded['results'])
                : [];
            $out[$page] = $results;
        }
        curl_multi_close($mh);

        return $out;
    }

    private function stemCachePath(string $stem, string $variant): string
    {
        $dir = rtrim(Config::runtimePath(), '/') . '/dockerhub-php-tags';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/stem-' . preg_replace('/[^a-z0-9.]+/', '-', $stem . '-' . $variant) . '.json';
    }

    /**
     * @return array{count: int, results: list<array<string,mixed>>}|null
     */
    private function readStemCache(string $stem, string $variant): ?array
    {
        $path = $this->stemCachePath($stem, $variant);
        if (!is_file($path)) {
            return null;
        }
        $mtime = filemtime($path);
        if ($mtime === false || (time() - $mtime) > self::STEM_CACHE_TTL) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return null;
        }

        return [
            'count' => (int) ($data['count'] ?? count($data['results'])),
            'results' => array_values($data['results']),
        ];
    }

    /**
     * @param array{count: int, results: list<array<string,mixed>>} $payload
     */
    private function writeStemCache(string $stem, string $variant, array $payload): void
    {
        $path = $this->stemCachePath($stem, $variant);
        @file_put_contents(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array{count: int, results: list<mixed>}
     */
    private function fetchHubPage(int $page, int $pageSize, string $name, string $ordering): array
    {
        $query = [
            'page' => $page,
            'page_size' => $pageSize,
            'ordering' => $ordering,
        ];
        if ($name !== '') {
            $query['name'] = $name;
        }

        $url = self::HUB_TAGS_URL . '?' . http_build_query($query);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to query Docker Hub tags.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: MultiPhpManager/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException('Docker Hub tags request failed' . ($error !== '' ? (': ' . $error) : ''));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
            throw new RuntimeException('Docker Hub returned an unexpected payload.');
        }

        return [
            'count' => (int) ($decoded['count'] ?? count($decoded['results'])),
            'results' => array_values($decoded['results']),
        ];
    }

    /**
     * @return array<string, true>
     */
    private function installedTags(): array
    {
        $keys = [];
        foreach (PhpVersionCatalog::versions() as $svc => $_meta) {
            try {
                $version = PhpVersionId::minorFromService($svc);
                $variant = PhpVersionId::variantFromService($svc);
            } catch (HttpException) {
                continue;
            }
            $keys[PhpVersionId::dockerTag($version, $variant)] = true;
        }

        return $keys;
    }
}
