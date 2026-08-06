<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use RuntimeException;

final class DockerHubPhpTags
{
    private const HUB_TAGS_URL = 'https://hub.docker.com/v2/repositories/library/php/tags';

    /**
     * Proxy one Docker Hub tags page (no post-filtering of results).
     * name=fpm by default; when the user searches, the query is joined as "{q}-fpm".
     *
     * @return array{versions: list<array<string,mixed>>, page: int, per_page: int, total: int, total_pages: int, name: string}
     */
    public function page(int $page, int $perPage, string $q, string $variant): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $q = strtolower(trim($q));
        $variant = in_array($variant, ['all', 'default', 'alpine', 'trixie'], true) ? $variant : 'all';

        $name = $this->hubNameFilter($q, $variant);
        $hub = $this->fetchHubPage($page, $perPage, $name);
        $installed = $this->installedTags();
        $versions = [];
        foreach ($hub['results'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tag = strtolower(trim((string) ($row['name'] ?? '')));
            if ($tag === '') {
                continue;
            }

            $rowVariant = $this->variantFromTag($tag);
            $version = explode('-', $tag, 2)[0];
            $installable = PhpVersionId::isValidMinor($version)
                && str_starts_with($tag, $version . '-fpm');
            $service = $installable
                ? PhpVersionId::serviceFrom($version, $rowVariant)
                : ('php-tag-' . preg_replace('/[^a-z0-9.]+/', '-', $tag));
            $label = $installable ? PhpVersionId::label($service) : ('php:' . $tag);

            $versions[] = [
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

    private function hubNameFilter(string $q, string $variant): string
    {
        $q = strtolower(trim($q));
        $q = preg_replace('/\s+/', '', $q) ?? '';
        // Keep only the version/search stem; fpm(+suffix) is appended below.
        $stem = preg_replace('/-?(fpm)(-alpine|-trixie)?$/i', '', $q) ?? '';
        $stem = rtrim($stem, '-./');

        if ($variant === 'alpine') {
            return $stem === '' ? 'fpm-alpine' : $stem . '-fpm-alpine';
        }
        if ($variant === 'trixie') {
            return $stem === '' ? 'fpm-trixie' : $stem . '-fpm-trixie';
        }
        if ($variant === 'default') {
            // Hub has no negative filter; keep name=fpm and let the UI show mixed debian tags.
            return $stem === '' ? 'fpm' : $stem . '-fpm';
        }

        // all: name=fpm; user search is joined in front.
        return $stem === '' ? 'fpm' : $stem . '-fpm';
    }

    /**
     * @return array{count: int, results: list<mixed>}
     */
    private function fetchHubPage(int $page, int $pageSize, string $name): array
    {
        $url = self::HUB_TAGS_URL . '?' . http_build_query([
            'page' => $page,
            'page_size' => $pageSize,
            'ordering' => 'last_updated',
            'name' => $name,
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to query Docker Hub tags.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: MultiPhpManager/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

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
