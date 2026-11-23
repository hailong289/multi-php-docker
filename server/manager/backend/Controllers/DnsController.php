<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\DnsRecords;
use Manager\Models\SiteHealth;
use Manager\Support\Config;
use Manager\Support\ContainerControl;
use Manager\Support\DockerExec;
use Manager\Support\DockerLiveState;

/** Container-side wildcard DNS (dns_container, configs/coredns/Corefile). */
final class DnsController extends Controller
{
    private const CONTAINER = 'dns_container';

    public function show(Request $request, array $params = []): Response
    {
        $logs = DockerExec::containerLogs(self::CONTAINER, 40);

        return Response::json([
            'state' => DockerLiveState::stateFor(self::CONTAINER) ?? 'unknown',
            'tlds' => self::tlds(),
            'records' => (new DnsRecords())->all(),
            'logs' => $logs === null ? [] : array_values(array_filter(explode("\n", trim($logs)), 'strlen')),
        ]);
    }

    public function restart(Request $request, array $params = []): Response
    {
        if (ContainerControl::apply(self::CONTAINER, 'restart') === null) {
            throw new HttpException('dns.restart_failed', 502);
        }

        return Response::json(['message_key' => 'dns.restarted']);
    }

    public function records(Request $request, array $params = []): Response
    {
        $records = (new DnsRecords())->save($request->json()['records'] ?? null, self::tlds());
        // The Corefile reload plugin does not watch imported files.
        if (ContainerControl::apply(self::CONTAINER, 'restart') === null) {
            throw new HttpException('dns.restart_failed', 502);
        }

        return Response::json(['records' => $records, 'message_key' => 'dns.record.saved']);
    }

    public function test(Request $request, array $params = []): Response
    {
        $domain = strtolower(trim((string) ($request->json()['domain'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9-]*(\.[a-z0-9-]+)+$/', $domain) !== 1) {
            throw new HttpException('validation.failed', 422, ['domain' => ['key' => 'validation.local_domain']]);
        }

        // Manager forwards DNS to dns_container like PHP containers, so this is what PHP would get.
        $resolved = gethostbyname($domain);
        $ip = $resolved !== $domain ? $resolved : null;
        $tld = substr($domain, strrpos($domain, '.') + 1);

        return Response::json([
            'domain' => $domain,
            'ip' => $ip,
            'supported_tld' => in_array($tld, self::tlds(), true),
            // 127.0.0.1 means the name fell through to the host hosts file: PHP would call itself.
            'http' => $ip === null || str_starts_with($ip, '127.') ? null : (new SiteHealth())->probe($domain),
        ]);
    }

    /**
     * TLDs answered with nginx's address: the zones of the Corefile's first server block.
     *
     * @return list<string>
     */
    public static function tlds(): array
    {
        $corefile = @file_get_contents(rtrim(Config::projectPath(), '/') . '/configs/coredns/Corefile');
        if (!is_string($corefile) || preg_match('/^([a-z0-9. ]+)\{/m', $corefile, $m) !== 1) {
            return [];
        }

        return array_values(array_filter(explode(' ', trim($m[1])), static fn (string $zone): bool => $zone !== '.' && $zone !== ''));
    }
}
