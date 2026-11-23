<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\DnsConfig;
use Manager\Models\DnsRecords;
use Manager\Models\SiteHealth;
use Manager\Support\ComposeEnv;
use Manager\Support\ContainerControl;
use Manager\Support\DockerExec;
use Manager\Support\DockerLiveState;

/** Container-side wildcard DNS (dns_container, configs/coredns/Corefile). */
final class DnsController extends Controller
{
    private const CONTAINER = 'dns_container';

    public function show(Request $request, array $params = []): Response
    {
        // Self-heal sites.conf when env.json was edited outside Manager.
        $customSites = DnsConfig::sync();
        $queryLog = (new DnsConfig())->queryLog();
        // Query lines are short and many; show more of them while the log is on.
        $logs = DockerExec::containerLogs(self::CONTAINER, $queryLog ? 200 : 40);

        return Response::json([
            'state' => DockerLiveState::stateFor(self::CONTAINER) ?? 'unknown',
            'tlds' => DnsConfig::siteTlds(),
            'custom_sites' => $customSites,
            'records' => (new DnsRecords())->all(),
            'query_log' => $queryLog,
            'upstream' => (string) (ComposeEnv::get('DNS_UPSTREAM') ?? ''),
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
        // CoreDNS's reload plugin picks the rendered file up within a few seconds; no restart needed.
        $records = (new DnsRecords())->save($request->json()['records'] ?? null, DnsConfig::siteTlds());

        return Response::json(['records' => $records, 'message_key' => 'dns.record.saved']);
    }

    public function settings(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $upstream = trim((string) ($body['upstream'] ?? ''));
        if ($upstream !== '' && filter_var($upstream, FILTER_VALIDATE_IP) === false) {
            throw new HttpException('dns.upstream_invalid', 422, [], ['upstream' => $upstream]);
        }
        (new DnsConfig())->setQueryLog((bool) ($body['query_log'] ?? false));

        // Upstream is the dns container's own `dns:` option (docker-compose.yml): Docker DNS keeps
        // answering container names and forwards only the rest. Changing it recreates the container.
        if ($upstream !== (string) (ComposeEnv::get('DNS_UPSTREAM') ?? '')) {
            if (!ComposeEnv::set(['DNS_UPSTREAM' => $upstream])) {
                throw new HttpException('dns.settings_failed', 500);
            }
            if (!DockerExec::composeUpService('dns')) {
                throw new HttpException('dns.recreate_failed', 502);
            }
        }

        return Response::json(['message_key' => 'dns.settings_saved']);
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
            'supported_tld' => in_array($tld, DnsConfig::siteTlds(), true)
                || in_array($domain, (new DnsConfig())->customSiteDomains(), true),
            // 127.0.0.1 means the name fell through to the host hosts file: PHP would call itself.
            'http' => $ip === null || str_starts_with($ip, '127.') ? null : (new SiteHealth())->probe($domain),
        ]);
    }
}
