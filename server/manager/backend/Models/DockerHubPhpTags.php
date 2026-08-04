<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

/** Fetches installable PHP minor versions from Docker Hub library/php (*-fpm tags). */
final class DockerHubPhpTags
{
    private const FALLBACK = ['8.4', '8.3', '8.2', '8.1', '8.0', '7.4'];

    public function __construct(private readonly string $projectPath = '')
    {
    }

    private function root(): string
    {
        return rtrim($this->projectPath !== '' ? $this->projectPath : Config::projectPath(), '/');
    }

    /**
     * @return list<array{version:string,tag:string,service:string,label:string,installed:bool}>
     */
    public function available(): array
    {
        $installed = [];
        foreach (array_keys(PhpVersionCatalog::versions($this->root())) as $service) {
            $installed[PhpVersionId::minorFromService($service)] = true;
        }

        $minors = array_values(array_unique(array_merge($this->fetchMinors(), self::FALLBACK)));
        sort($minors, SORT_NATURAL);

        $out = [];
        foreach ($minors as $version) {
            if (!PhpVersionId::isValidMinor($version)) {
                continue;
            }
            // Skip very old unsupported for this stack.
            if (version_compare($version, '7.4', '<')) {
                continue;
            }
            $service = PhpVersionId::serviceFromMinor($version);
            $out[] = [
                'version' => $version,
                'tag' => $version . '-fpm',
                'service' => $service,
                'label' => PhpVersionId::label($service),
                'installed' => isset($installed[$version]),
            ];
        }

        usort($out, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $out;
    }

    /** @return list<string> */
    private function fetchMinors(): array
    {
        $found = [];
        // Query by major prefix so we do not only see stale 5.x tags under name=fpm.
        foreach (['8.', '7.4-fpm'] as $query) {
            $url = 'https://hub.docker.com/v2/repositories/library/php/tags?page_size=100&ordering=last_updated&name='
                . rawurlencode($query);
            $pages = 0;
            while ($url !== null && $pages < 4) {
                $pages++;
                $payload = $this->httpGetJson($url);
                if ($payload === null) {
                    break;
                }
                foreach ($payload['results'] ?? [] as $row) {
                    $name = (string) ($row['name'] ?? '');
                    if (preg_match('/^(\d+\.\d+)-fpm$/', $name, $m)) {
                        $found[$m[1]] = true;
                    }
                }
                $next = $payload['next'] ?? null;
                $url = is_string($next) && $next !== '' ? $next : null;
            }
        }

        return array_keys($found);
    }

    private function httpGetJson(string $url): ?array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: multi-php-manager/1.0'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $code < 200 || $code >= 300) {
                return null;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 12,
                    'header' => "Accept: application/json\r\nUser-Agent: multi-php-manager/1.0\r\n",
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if (!is_string($body)) {
                return null;
            }
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
