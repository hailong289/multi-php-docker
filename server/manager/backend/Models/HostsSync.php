<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;
use Manager\Support\RemoteAuth;

final class HostsSync
{
    private readonly string $runtimePath;

    public function __construct(?string $runtimePath = null)
    {
        $this->runtimePath = rtrim($runtimePath ?? Config::runtimePath(), '/');
    }

    public static function writeEnabled(): bool
    {
        return !RemoteAuth::isRemote();
    }

    public function status(): ?array
    {
        $file = $this->runtimePath . '/hosts.status.json';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function pendingSync(): bool
    {
        return is_file($this->runtimePath . '/hosts.sync');
    }

    /**
     * Return the latest status written by the optional helper on the host.
     *
     * @param array<string, array<string, mixed>> $servers
     * @return array<string, mixed>
     */
    public function refreshStatus(array $servers): array
    {
        $status = $this->status();

        return [
            'message_key' => $status === null
                ? 'hosts.controller_unavailable'
                : 'hosts.status_refreshed',
            'hosts_status' => $status,
            'pending_sync' => $this->pendingSync(),
            'domains' => $this->listedDomains($servers, $status),
        ];
    }

    /**
     * @return array<string, true> lowercase domain => true
     */
    public function parseHostsDomains(string $content): array
    {
        $present = [];
        foreach (preg_split("/\r\n|\n|\r/", $content) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            // Strip inline comments.
            if (($hash = strpos($trimmed, '#')) !== false) {
                $trimmed = trim(substr($trimmed, 0, $hash));
                if ($trimmed === '') {
                    continue;
                }
            }
            $parts = preg_split('/\s+/', $trimmed) ?: [];
            if (count($parts) < 2) {
                continue;
            }
            $ip = strtolower($parts[0]);
            if ($ip !== '127.0.0.1' && $ip !== '::1') {
                continue;
            }
            for ($i = 1, $n = count($parts); $i < $n; $i++) {
                $host = $this->normalizeDomain($parts[$i]);
                if ($host !== '' && $host !== 'localhost') {
                    $present[$host] = true;
                }
            }
        }

        return $present;
    }

    public function request(bool $forceAdmin = false, string $focusDomain = ''): void
    {
        // Remote Manager runs on a server: OS hosts helpers / protocol writes do not apply.
        if (!self::writeEnabled()) {
            return;
        }

        if (!is_dir($this->runtimePath) && !mkdir($this->runtimePath, 0775, true) && !is_dir($this->runtimePath)) {
            throw new HttpException('error.runtime_directory', 500);
        }

        $payload = [
            'requested_at' => date(DATE_ATOM),
            'request_id' => bin2hex(random_bytes(8)),
            'force_admin' => $forceAdmin,
        ];
        $focus = $this->normalizeDomain($focusDomain);
        if ($focus !== '') {
            $payload['focus_domain'] = $focus;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        if (file_put_contents($this->runtimePath . '/hosts.sync', $json, LOCK_EX) === false) {
            throw new HttpException('error.hosts_sync_request', 500);
        }
    }

    /**
     * @param list<string> $domains
     * @return array{hosts_path_windows: string, hosts_path_unix: string, lines: list<string>}
     */
    public function manualHint(array $domains): array
    {
        $normalized = [];
        foreach ($domains as $domain) {
            $value = $this->normalizeDomain((string) $domain);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }
        $list = array_keys($normalized);
        sort($list);

        return [
            'hosts_path_windows' => 'C:\\Windows\\System32\\drivers\\etc\\hosts',
            'hosts_path_unix' => '/etc/hosts',
            'lines' => array_map(static fn (string $domain): string => '127.0.0.1 ' . $domain, $list),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $servers
     * @return list<string>
     */
    public function desiredDomains(array $servers): array
    {
        $set = [];
        foreach ($servers as $server) {
            $domain = $this->normalizeDomain((string) ($server['DOMAIN_NAME'] ?? ''));
            if ($domain !== '') {
                $set[$domain] = true;
            }
        }
        foreach ($this->extras() as $domain) {
            $set[$domain] = true;
        }
        $list = array_keys($set);
        sort($list);

        return $list;
    }

    public function extraPath(): string
    {
        return $this->runtimePath . '/hosts.extra.json';
    }

    /**
     * @return list<string>
     */
    public function extras(): array
    {
        $file = $this->extraPath();
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        try {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $domains = [];
        foreach ($decoded as $item) {
            $domain = strtolower(trim((string) $item));
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }
        $list = array_keys($domains);
        sort($list);

        return $list;
    }

    /**
     * @param list<string> $domains
     */
    public function saveExtras(array $domains): void
    {
        if (!is_dir($this->runtimePath) && !mkdir($this->runtimePath, 0775, true) && !is_dir($this->runtimePath)) {
            throw new HttpException('error.runtime_directory', 500);
        }

        $normalized = [];
        foreach ($domains as $item) {
            $domain = strtolower(trim((string) $item));
            if ($domain !== '') {
                $normalized[$domain] = true;
            }
        }
        $list = array_keys($normalized);
        sort($list);

        $json = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($this->extraPath(), $json, LOCK_EX) === false) {
            throw new HttpException('error.hosts_extra_write', 500);
        }
    }

    public function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    public function validateDomain(string $domain): ?array
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return ['key' => 'validation.domain'];
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $servers
     * @return list<array{key: string, source: string, app_name: string, domain_name: string, hosts_state: string}>
     */
    public function listedDomains(array $servers, ?array $hostsStatus = null): array
    {
        $hostsStatus ??= $this->status();
        $states = is_array($hostsStatus['domains'] ?? null) ? $hostsStatus['domains'] : [];
        $hasStatus = $hostsStatus !== null;
        $domains = [];
        $seen = [];

        foreach ($servers as $key => $server) {
            $domainName = $this->normalizeDomain((string) ($server['DOMAIN_NAME'] ?? ''));
            if ($domainName === '') {
                continue;
            }
            $seen[$domainName] = true;
            $domains[] = [
                'key' => (string) $key,
                'source' => 'server',
                'app_name' => (string) ($server['APP_NAME'] ?? ''),
                'domain_name' => $domainName,
                'hosts_state' => self::resolveHostsState($domainName, $states, $hasStatus),
            ];
        }

        foreach ($this->extras() as $domainName) {
            if (isset($seen[$domainName])) {
                continue;
            }
            $domains[] = [
                'key' => 'hosts:' . $domainName,
                'source' => 'hosts',
                'app_name' => '',
                'domain_name' => $domainName,
                'hosts_state' => self::resolveHostsState($domainName, $states, $hasStatus),
            ];
        }

        return $domains;
    }

    /**
     * @param array<string, mixed> $states
     */
    private static function resolveHostsState(string $domainName, array $states, bool $hasStatus): string
    {
        if (!$hasStatus) {
            return 'unknown';
        }

        return (($states[$domainName] ?? null) === 'synced') ? 'synced' : 'missing';
    }

    /**
     * @deprecated use listedDomains()
     * @param array<string, array<string, mixed>> $servers
     * @return list<array{key: string, app_name: string, domain_name: string, hosts_state: string}>
     */
    public static function domainsFromServers(array $servers, ?array $hostsStatus): array
    {
        return (new self())->listedDomains($servers, $hostsStatus);
    }
}
