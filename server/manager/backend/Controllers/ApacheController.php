<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\ApacheConfig;

final class ApacheController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        return Response::json(['apache' => (new ApacheConfig())->details()]);
    }

    public function saveConfig(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $config = new ApacheConfig();
        $config->saveConfig((string) ($body['content'] ?? ''));
        $reload = ($body['reload'] ?? false) === true;
        if ($reload) {
            $config->requestReload();
        }

        return Response::json([
            'apache' => $config->details(),
            'message_key' => $reload ? 'apache.config_saved_reloading' : 'apache.config_saved',
        ]);
    }

    public function ports(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $config = new ApacheConfig();
        $requestId = $config->updatePorts($body['http_port'] ?? null, $body['https_port'] ?? null);

        return Response::json([
            'request_id' => $requestId,
            'message_key' => $requestId === '' ? 'apache.ports_saved' : 'apache.ports_applying',
            'apache' => $config->details(),
        ]);
    }

    public function reload(Request $request, array $params = []): Response
    {
        (new ApacheConfig())->requestReload();

        return Response::json(['message_key' => 'apache.reload_requested']);
    }
}
