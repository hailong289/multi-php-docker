<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;
use Manager\Models\HostsSync;

final class HostsController extends Controller
{
    public function status(Request $request, array $params = []): Response
    {
        $hosts = new HostsSync();

        return Response::json([
            'hosts_status' => $hosts->status(),
            'pending_sync' => $hosts->pendingSync(),
        ]);
    }

    public function sync(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $forceAdmin = (bool) ($body['force_admin'] ?? false);
        $focusDomain = (string) ($body['domain_name'] ?? '');

        $hosts = new HostsSync();
        $env = new EnvConfig();
        $servers = $env->all();

        // Default Sync button: read mounted hosts file and refresh status only.
        if (!$forceAdmin) {
            $status = $hosts->refreshStatusFromHostsFile($servers);

            return Response::json([
                'message_key' => 'hosts.status_refreshed',
                'hosts_status' => $status,
                'pending_sync' => false,
                'domains' => $hosts->listedDomains($servers, $status),
                'force_admin' => false,
            ]);
        }

        $hosts->request(true, $focusDomain);
        $desired = $hosts->desiredDomains($servers);
        $manualDomains = $focusDomain !== ''
            ? [$hosts->normalizeDomain($focusDomain)]
            : $desired;

        return Response::json([
            'message_key' => 'hosts.admin_write_requested',
            'hosts_status' => $hosts->status(),
            'pending_sync' => $hosts->pendingSync(),
            'manual' => $hosts->manualHint($manualDomains),
            'force_admin' => true,
            'domain_name' => $hosts->normalizeDomain($focusDomain),
        ]);
    }
}
