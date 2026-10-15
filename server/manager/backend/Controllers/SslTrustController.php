<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;
use Manager\Models\SslCertificates;
use Manager\Models\SslTrust;
use Manager\Support\Config;

final class SslTrustController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        return Response::json([
            'last_result' => (new SslTrust())->lastResult(Config::projectPath()),
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $key = (string) ($request->json()['server_key'] ?? '');
        if (!preg_match('/^SERVER_NAME\d+$/', $key)) {
            throw new HttpException('error.server_missing', 404);
        }
        $servers = (new EnvConfig())->all();
        $server = $servers[$key] ?? null;
        if (!is_array($server)) {
            throw new HttpException('error.server_missing', 404);
        }
        if (!SslCertificates::isEnabled($server) || SslCertificates::mode($server) !== SslCertificates::MODE_GENERATED) {
            throw new HttpException('ssl.trust_requires_generated', 409);
        }
        $app = (string) ($server['APP_NAME'] ?? '');
        $domain = (string) ($server['DOMAIN_NAME'] ?? '');
        $out = (new SslTrust())->request(Config::projectPath(), $app, $domain);

        return Response::json($out + [
            'app_name' => $app,
            'domain_name' => $domain,
        ]);
    }
}
