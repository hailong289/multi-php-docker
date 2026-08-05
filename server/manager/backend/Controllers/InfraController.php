<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\InfraRuntime;

final class InfraController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $runtime = new InfraRuntime();

        return Response::json([
            'targets' => InfraRuntime::targets(),
            'statuses' => $runtime->statuses(),
        ]);
    }

    public function action(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $action = (string) ($params['action'] ?? '');
        $runtime = new InfraRuntime();
        $requestId = $runtime->request($service, $action);
        $target = InfraRuntime::targets()[$service];

        return Response::json([
            'request_id' => $requestId,
            'message_key' => 'services.requested',
            'message_parameters' => [
                'action' => 'services.' . $action,
                'service' => $target['label'],
            ],
            'infra_services' => [
                'targets' => InfraRuntime::targets(),
                'statuses' => $runtime->statuses(),
            ],
        ]);
    }
}
