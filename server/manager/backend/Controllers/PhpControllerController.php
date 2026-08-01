<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\PhpRuntime;

final class PhpControllerController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $runtime = new PhpRuntime();

        return Response::json([
            'targets' => PhpRuntime::targets(),
            'statuses' => $runtime->statuses(),
        ]);
    }

    public function action(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $action = (string) ($params['action'] ?? '');
        $runtime = new PhpRuntime();
        $requestId = $runtime->request($service, $action);
        $target = PhpRuntime::targets()[$service];

        return Response::json([
            'request_id' => $requestId,
            'message_key' => 'php_controller.requested',
            'message_parameters' => [
                'action' => 'php_controller.' . $action,
                'version' => $target['label'],
            ],
            'php_controllers' => [
                'targets' => PhpRuntime::targets(),
                'statuses' => $runtime->statuses(),
            ],
        ]);
    }
}
