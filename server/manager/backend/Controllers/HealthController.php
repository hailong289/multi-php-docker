<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;
use Manager\Models\SiteHealth;
use Manager\Support\DockerEndpoint;

final class HealthController extends Controller
{
    public function sites(Request $request, array $params = []): Response
    {
        $health = new SiteHealth();
        $sites = [];
        foreach ((new EnvConfig())->allOrEmpty() as $key => $server) {
            if (!is_array($server) || !EnvConfig::isEnabled($server)) {
                continue;
            }
            if (count($sites) >= 50) {
                break;
            }
            $domain = (string) ($server['DOMAIN_NAME'] ?? '');
            $probe = $health->probe($domain);
            $sites[] = [
                'key' => (string) $key,
                'domain' => $domain,
                'status' => $probe['status'],
                'ms' => $probe['ms'],
                'state' => $probe['state'],
            ];
        }

        return Response::json(['sites' => $sites]);
    }

    public function ports(Request $request, array $params = []): Response
    {
        $service = (string) $request->queryParam('service', '');
        $health = new SiteHealth();
        $raw = DockerEndpoint::request('GET', '/containers/json?all=true');
        $list = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $list = is_array($decoded) ? $decoded : [];
        }
        $conflicts = $health->conflicts(
            $health->containersFromEngine($list),
            $health->serviceContainer($service),
            $health->servicePorts($service),
        );

        return Response::json(['conflicts' => $conflicts]);
    }
}
