<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\SupervisorRuntime;

final class SupervisorController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $runtime = new SupervisorRuntime();

        return Response::json([
            'targets' => SupervisorRuntime::targets(),
            'statuses' => $runtime->statuses(),
        ]);
    }

    public function details(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $log = $request->queryParam('log');
        $logFile = is_string($log) ? $log : null;

        return Response::json([
            'supervisor' => (new SupervisorRuntime())->details($service, $logFile),
        ]);
    }

    public function action(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $action = (string) ($params['action'] ?? '');
        $runtime = new SupervisorRuntime();
        $requestId = $runtime->request($service, $action);
        $target = SupervisorRuntime::targets()[$service];

        return Response::json([
            'request_id' => $requestId,
            'message_key' => 'supervisor.requested',
            'message_parameters' => [
                'action' => 'supervisor.' . $action,
                'service' => $target['label'],
            ],
            'supervisor_services' => [
                'targets' => SupervisorRuntime::targets(),
                'statuses' => $runtime->statuses(),
            ],
        ]);
    }

    public function clearLog(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $log = $request->json()['log'] ?? null;
        if (!is_string($log) || $log === '') {
            throw new HttpException('supervisor.invalid_log', 400);
        }
        $logFile = basename(str_replace('\\', '/', $log));
        $runtime = new SupervisorRuntime();
        $runtime->clearLog($service, $logFile);

        return Response::json([
            'message_key' => 'supervisor.cleared',
            'message_parameters' => ['log' => $logFile],
            'supervisor' => $runtime->details($service, $logFile),
        ]);
    }
}
