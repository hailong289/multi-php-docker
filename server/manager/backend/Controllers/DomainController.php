<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;
use Manager\Models\HostsSync;
use Manager\Models\PhpVersionCatalog;

final class DomainController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $env = new EnvConfig();
        $hosts = new HostsSync();
        $servers = $env->all();
        $hostsStatus = $hosts->status();

        return Response::json([
            'domains' => $hosts->listedDomains($servers, $hostsStatus),
            'hosts_status' => $hostsStatus,
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $hosts = new HostsSync();
        $body = $request->json();
        $domain = $hosts->normalizeDomain((string) ($body['domain_name'] ?? ''));
        $error = $hosts->validateDomain($domain);
        if ($error !== null) {
            throw new HttpException('validation.failed', 422, ['domain_name' => $error]);
        }

        $env = new EnvConfig();
        foreach ($env->all() as $server) {
            if (strcasecmp((string) ($server['DOMAIN_NAME'] ?? ''), $domain) === 0) {
                throw new HttpException('validation.failed', 422, [
                    'domain_name' => ['key' => 'validation.duplicate_domain'],
                ]);
            }
        }

        $extras = $hosts->extras();
        if (in_array($domain, $extras, true)) {
            throw new HttpException('validation.failed', 422, [
                'domain_name' => ['key' => 'validation.duplicate_domain'],
            ]);
        }

        $extras[] = $domain;
        $hosts->saveExtras($extras);
        // Force admin write so watch mode elevates immediately (Manager cannot write hosts itself).
        $hosts->request(true, $domain);
        $servers = $env->all();

        return Response::json([
            'domain_name' => $domain,
            'message_key' => 'hosts.domain_added',
            'domains' => $hosts->listedDomains($servers, $hosts->status()),
            'hosts_status' => $hosts->status(),
            'pending_sync' => $hosts->pendingSync(),
            'manual' => $hosts->manualHint($hosts->desiredDomains($servers)),
            'bootstrap' => $this->bootstrapPayload(),
        ], 201);
    }

    public function update(Request $request, array $params = []): Response
    {
        $key = (string) ($params['key'] ?? '');
        $body = $request->json();
        $hosts = new HostsSync();
        $env = new EnvConfig();
        $servers = $env->all();

        if (!isset($servers[$key])) {
            throw new HttpException('error.server_missing', 404);
        }

        $server = $servers[$key];
        $validation = $env->validate([
            'app_name' => (string) ($server['APP_NAME'] ?? ''),
            'domain_name' => (string) ($body['domain_name'] ?? ''),
            'server_path' => (string) ($server['SERVER_PATH'] ?? ''),
            'php_version' => PhpVersionCatalog::versionFromContainer(
                (string) ($server['CONTAINER_PHP_VERSION'] ?? '')
            ),
            'enabled' => EnvConfig::isEnabled($server),
        ], $servers, $key);

        if ($validation['errors'] !== []) {
            throw new HttpException('validation.failed', 422, $validation['errors']);
        }

        $servers[$key] = $validation['server'];
        $env->save($servers);
        $hosts->request(true, (string) ($validation['server']['DOMAIN_NAME'] ?? ''));

        return Response::json([
            'key' => $key,
            'server' => $validation['server'],
            'message_key' => 'flash.domain_updated',
            'pending_sync' => $hosts->pendingSync(),
            'bootstrap' => $this->bootstrapPayload(),
        ]);
    }

    public function updateExtra(Request $request, array $params = []): Response
    {
        $hosts = new HostsSync();
        $current = $hosts->normalizeDomain((string) ($params['domain'] ?? ''));
        $next = $hosts->normalizeDomain((string) ($request->json()['domain_name'] ?? ''));
        $error = $hosts->validateDomain($next);
        if ($error !== null) {
            throw new HttpException('validation.failed', 422, ['domain_name' => $error]);
        }

        $extras = $hosts->extras();
        if (!in_array($current, $extras, true)) {
            throw new HttpException('error.domain_missing', 404);
        }

        $env = new EnvConfig();
        $servers = $env->all();
        foreach ($servers as $server) {
            if (strcasecmp((string) ($server['DOMAIN_NAME'] ?? ''), $next) === 0) {
                throw new HttpException('validation.failed', 422, [
                    'domain_name' => ['key' => 'validation.duplicate_domain'],
                ]);
            }
        }
        foreach ($extras as $extra) {
            if ($extra !== $current && strcasecmp($extra, $next) === 0) {
                throw new HttpException('validation.failed', 422, [
                    'domain_name' => ['key' => 'validation.duplicate_domain'],
                ]);
            }
        }

        $extras = array_values(array_map(
            static fn (string $item): string => $item === $current ? $next : $item,
            $extras,
        ));
        $hosts->saveExtras($extras);
        $hosts->request(true, $next);

        return Response::json([
            'key' => 'hosts:' . $next,
            'domain_name' => $next,
            'message_key' => 'hosts.domain_updated',
            'domains' => $hosts->listedDomains($servers, $hosts->status()),
            'hosts_status' => $hosts->status(),
            'pending_sync' => $hosts->pendingSync(),
            'manual' => $hosts->manualHint($hosts->desiredDomains($servers)),
            'bootstrap' => $this->bootstrapPayload(),
        ]);
    }

    public function destroyExtra(Request $request, array $params = []): Response
    {
        $hosts = new HostsSync();
        $domain = $hosts->normalizeDomain((string) ($params['domain'] ?? ''));
        $extras = $hosts->extras();
        if (!in_array($domain, $extras, true)) {
            throw new HttpException('error.domain_missing', 404);
        }

        $extras = array_values(array_filter(
            $extras,
            static fn (string $item): bool => $item !== $domain,
        ));
        $hosts->saveExtras($extras);
        $hosts->request(true);

        $env = new EnvConfig();
        $servers = $env->all();

        return Response::json([
            'message_key' => 'hosts.domain_removed',
            'domains' => $hosts->listedDomains($servers, $hosts->status()),
            'hosts_status' => $hosts->status(),
            'pending_sync' => $hosts->pendingSync(),
            'manual' => $hosts->manualHint($hosts->desiredDomains($servers)),
            'bootstrap' => $this->bootstrapPayload(),
        ]);
    }
}
