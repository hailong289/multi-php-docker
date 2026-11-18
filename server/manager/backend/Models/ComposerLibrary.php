<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\AtomicFile;
use Manager\Support\Config;
use Manager\Support\DockerExec;

/**
 * Shared Composer library: phars under versions/<x.y.z>/composer.phar and per-PHP assignments under
 * assigned/<service|default>. PHP containers mount it at /opt/composer and run it through
 * scripts/composer/bin/composer, so an assignment takes effect on the next composer call.
 */
final class ComposerLibrary
{
    private const RELEASES_URL = 'https://getcomposer.org/versions';

    private const DOWNLOAD_URL = 'https://getcomposer.org/download/%s/composer.phar';

    private const RELEASES_TTL_SECONDS = 21600;

    private const RELEASES_RETRY_SECONDS = 600;

    private const FEED_TIMEOUT_SECONDS = 4;

    private const DOWNLOAD_TIMEOUT_SECONDS = 30;

    private const VERSION_RE = '/^\d+\.\d+\.\d+$/D';

    private readonly string $root;

    /** @var callable(string, int): ?string */
    private mixed $http;

    /** @var callable(): array<string, string> */
    private mixed $targets;

    /** @var callable(string): ?bool */
    private mixed $mounted;

    /**
     * @param (callable(string, int): ?string)|null $http url, timeout seconds => body, null on any failure
     * @param (callable(): array<string, string>)|null $targets installed PHP service => container name
     * @param (callable(string): ?bool)|null $mounted container name => has the /opt/composer mount; null when the container does not exist
     */
    public function __construct(?string $root = null, ?callable $http = null, ?callable $targets = null, ?callable $mounted = null)
    {
        $this->root = rtrim($root ?? Config::runtimePath() . '/composer', '/');
        $this->http = $http ?? static function (string $url, int $timeout): ?string {
            $context = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 1]]);
            $body = @file_get_contents($url, false, $context);

            return is_string($body) ? $body : null;
        };
        $this->targets = $targets ?? static fn (): array => array_map(
            static fn (array $target): string => (string) ($target['container'] ?? ''),
            PhpRuntime::targets(),
        );
        $this->mounted = $mounted ?? static fn (string $container): ?bool => DockerExec::inspectNamedContainer($container) === null
            ? null
            : DockerExec::mountSource($container, '/opt/composer') !== null;
    }

    /** @return list<array{key: 'latest'|'lts', version: string, min_php: int}> */
    public static function parseReleases(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['stable'] ?? null)) {
            return [];
        }
        $rows = [];
        foreach ($decoded['stable'] as $release) {
            if (!is_array($release) || !is_string($release['version'] ?? null)) {
                continue;
            }
            $rows[] = [
                'key' => ($release['lts'] ?? false) === true ? 'lts' : 'latest',
                'version' => $release['version'],
                'min_php' => (int) ($release['min-php'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Comparable with getcomposer.org "min-php": "php-8.2.5" → 80205. A minor-only service runs the
     * newest patch of that minor (Docker tag), so "php-7.2" → 70299.
     */
    public static function phpVersionId(string $service): int
    {
        $parts = array_map('intval', explode('.', PhpVersionId::minorFromService($service)));

        return $parts[0] * 10000 + ($parts[1] ?? 0) * 100 + ($parts[2] ?? 99);
    }

    /** Download and verify (sha256 from getcomposer.org) a version; "latest" / "lts" resolve from the feed. */
    public function download(string $choice): string
    {
        $release = null;
        if ($choice === 'latest' || $choice === 'lts') {
            foreach ($this->releases() as $row) {
                if ($row['key'] === $choice) {
                    $release = $row;
                }
            }
            if ($release === null) {
                throw new HttpException('composer.download_failed', 502, [], ['version' => $choice]);
            }
            $version = $release['version'];
        } elseif (preg_match(self::VERSION_RE, $choice) === 1) {
            $version = $choice;
            foreach ($this->releases() as $row) {
                if ($row['version'] === $version) {
                    $release = $row;
                }
            }
        } else {
            throw new HttpException('composer.invalid_version', 400);
        }

        $url = sprintf(self::DOWNLOAD_URL, $version);
        $phar = ($this->http)($url, self::DOWNLOAD_TIMEOUT_SECONDS);
        $sum = ($this->http)($url . '.sha256sum', self::FEED_TIMEOUT_SECONDS);
        if ($phar === null || $phar === '' || $sum === null) {
            throw new HttpException('composer.download_failed', 502, [], ['version' => $version]);
        }
        $expected = strtolower(strtok(trim($sum), " \t") ?: '');
        if (!hash_equals($expected, hash('sha256', $phar))) {
            throw new HttpException('composer.checksum_mismatch', 502, [], ['version' => $version]);
        }

        $dir = $this->root . '/versions/' . $version;
        // Mark incomplete first: the wrapper and the UI ignore a version until meta.json is back.
        @unlink($dir . '/meta.json');
        $written = AtomicFile::write($dir . '/composer.phar', $phar);
        if (!$written || !hash_equals($expected, (string) @hash_file('sha256', $dir . '/composer.phar'))) {
            @unlink($dir . '/composer.phar');
            throw new HttpException('composer.download_failed', 502, [], ['version' => $version]);
        }
        AtomicFile::write($dir . '/meta.json', (string) json_encode(['min_php' => $release['min_php'] ?? null]));

        return $version;
    }

    public function remove(string $version): void
    {
        $this->assertVersion($version);
        if (in_array($version, $this->assignments(), true)) {
            throw new HttpException('composer.version_in_use', 409);
        }
        $dir = $this->root . '/versions/' . $version;
        if (!is_dir($dir)) {
            throw new HttpException('composer.version_missing', 404);
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /** @param string $target a PHP service or "default"; $version null clears the assignment */
    public function assign(string $target, ?string $version): void
    {
        if ($target !== 'default' && !PhpVersionId::isValidService($target)) {
            throw new HttpException('composer.invalid_target', 400);
        }
        $file = $this->root . '/assigned/' . $target;
        if ($version === null) {
            @unlink($file);

            return;
        }
        $this->assertVersion($version);
        if (!$this->isComplete($version)) {
            throw new HttpException('composer.version_missing', 404);
        }
        $minPhp = $this->minPhp($version);
        $phpId = $target === 'default' ? $this->lowestPhpVersionId() : self::phpVersionId($target);
        if ($minPhp !== null && $phpId < $minPhp) {
            throw new HttpException('composer.unsupported_php', 400);
        }
        AtomicFile::write($file, $version . "\n");
    }

    /** @return list<array{version: string, min_php: ?int, assigned_to: list<string>}> */
    public function versions(): array
    {
        $assignments = $this->assignments();
        $rows = [];
        foreach (glob($this->root . '/versions/*/composer.phar') ?: [] as $phar) {
            $version = basename(dirname($phar));
            if (preg_match(self::VERSION_RE, $version) !== 1 || !$this->isComplete($version)) {
                continue;
            }
            $rows[] = [
                'version' => $version,
                'min_php' => $this->minPhp($version),
                'assigned_to' => array_keys($assignments, $version, true),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $rows;
    }

    /** @return array<string, string> target => version; files of uninstalled PHP versions are ignored */
    public function assignments(): array
    {
        $installed = $this->sortedTargets();
        $out = [];
        foreach (glob($this->root . '/assigned/*') ?: [] as $file) {
            $target = basename($file);
            $version = trim((string) file_get_contents($file));
            // A removed PHP version leaves its file behind; it must not hold a Composer version forever.
            if (($target === 'default' || isset($installed[$target])) && preg_match(self::VERSION_RE, $version) === 1) {
                $out[$target] = $version;
            }
        }

        return $out;
    }

    /**
     * @return array{
     *     releases: list<array{key: string, version: string, min_php: int}>,
     *     versions: list<array{version: string, min_php: ?int, assigned_to: list<string>}>,
     *     default: ?string,
     *     targets: list<array{service: string, label: string, container: string, version: ?string, effective: ?string, mounted: ?bool}>
     * }
     */
    public function show(): array
    {
        $assignments = $this->assignments();
        $default = $this->usable($assignments['default'] ?? null);
        $targets = [];
        foreach ($this->sortedTargets() as $service => $container) {
            $own = $assignments[$service] ?? null;
            $targets[] = [
                'service' => $service,
                'label' => PhpVersionId::label($service),
                'container' => $container,
                'version' => $own,
                // Same lookup as the wrapper: own assignment, then default, then the image's composer.
                'effective' => $this->usable($own) ?? $default,
                'mounted' => ($this->mounted)($container),
            ];
        }

        return [
            'releases' => $this->releases(),
            'versions' => $this->versions(),
            'default' => $assignments['default'] ?? null,
            'targets' => $targets,
        ];
    }

    private function usable(?string $version): ?string
    {
        return $version !== null && $this->isComplete($version) ? $version : null;
    }

    private function minPhp(string $version): ?int
    {
        $meta = json_decode((string) @file_get_contents($this->root . '/versions/' . $version . '/meta.json'), true);
        if (is_array($meta) && is_int($meta['min_php'] ?? null)) {
            return $meta['min_php'];
        }
        foreach ($this->releases() as $row) {
            if ($row['version'] === $version) {
                return $row['min_php'];
            }
        }

        return null;
    }

    /** meta.json is written last; without it the phar may be half-written (non-atomic fallback on macOS mounts). */
    private function isComplete(string $version): bool
    {
        return is_file($this->pharPath($version)) && is_file($this->root . '/versions/' . $version . '/meta.json');
    }

    private function pharPath(string $version): string
    {
        return $this->root . '/versions/' . $version . '/composer.phar';
    }

    private function assertVersion(string $version): void
    {
        if (preg_match(self::VERSION_RE, $version) !== 1) {
            throw new HttpException('composer.invalid_version', 400);
        }
    }

    /** @return array<string, string> service => container, oldest PHP first */
    private function sortedTargets(): array
    {
        $targets = array_filter(
            ($this->targets)(),
            static fn (string $container, string $service): bool => $container !== '' && PhpVersionId::isValidService($service),
            ARRAY_FILTER_USE_BOTH,
        );
        uksort($targets, static fn (string $a, string $b): int => [self::phpVersionId($a), $a] <=> [self::phpVersionId($b), $b]);

        return $targets;
    }

    private function lowestPhpVersionId(): int
    {
        $first = array_key_first($this->sortedTargets());

        return $first === null ? PHP_INT_MAX : self::phpVersionId($first);
    }

    /** @return list<array{key: string, version: string, min_php: int}> */
    private function releases(): array
    {
        $cache = $this->root . '/releases.json';
        $failedMarker = $cache . '.failed';
        $cached = is_file($cache) ? (string) file_get_contents($cache) : null;
        $fresh = $cached !== null && time() - (int) filemtime($cache) < self::RELEASES_TTL_SECONDS;
        $recentlyFailed = is_file($failedMarker) && time() - (int) filemtime($failedMarker) < self::RELEASES_RETRY_SECONDS;
        $raw = $cached;
        if (!$fresh && !$recentlyFailed) {
            $fetched = ($this->http)(self::RELEASES_URL, self::FEED_TIMEOUT_SECONDS);
            if ($fetched !== null && self::parseReleases($fetched) !== []) {
                AtomicFile::write($cache, $fetched);
                @unlink($failedMarker);
                $raw = $fetched;
            } else {
                // Offline: keep the stale list and do not wait on the network again for a while.
                AtomicFile::write($failedMarker, (string) time());
            }
        }

        return $raw === null ? [] : self::parseReleases($raw);
    }
}
