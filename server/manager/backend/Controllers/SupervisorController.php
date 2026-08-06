<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\SupervisorConfigs;
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

    public function configs(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $configs = new SupervisorConfigs();
        $targets = SupervisorRuntime::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('supervisor.invalid_service', 400);
        }

        return Response::json([
            'conf_dir' => $targets[$service]['conf_dir'],
            'configs' => $configs->list($service),
            'default_content' => $configs->defaultContent($service),
        ]);
    }

    public function configShow(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $name = (string) ($params['name'] ?? '');

        return Response::json([
            'config' => (new SupervisorConfigs())->read($service, $name),
        ]);
    }

    public function configCreate(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $body = $request->json();
        $name = (string) ($body['name'] ?? '');
        $content = (string) ($body['content'] ?? '');
        $configs = new SupervisorConfigs();
        $saved = $configs->write($service, $name, $content, true);
        $reload = $this->maybeRestart($service);

        return Response::json([
            'config' => $saved,
            'message_key' => $reload['request_id'] !== null
                ? 'supervisor.conf_created_restarting'
                : 'supervisor.conf_created',
            'request_id' => $reload['request_id'],
            'supervisor_services' => $reload['supervisor_services'],
        ], 201);
    }

    public function configSave(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $name = (string) ($params['name'] ?? '');
        $body = $request->json();
        $content = (string) ($body['content'] ?? '');
        $configs = new SupervisorConfigs();
        $saved = $configs->write($service, $name, $content, false);
        $reload = $this->maybeRestart($service);

        return Response::json([
            'config' => $saved,
            'message_key' => $reload['request_id'] !== null
                ? 'supervisor.conf_saved_restarting'
                : 'supervisor.conf_saved',
            'request_id' => $reload['request_id'],
            'supervisor_services' => $reload['supervisor_services'],
        ]);
    }

    public function configDelete(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $name = (string) ($params['name'] ?? '');
        (new SupervisorConfigs())->delete($service, $name);
        $reload = $this->maybeRestart($service);

        return Response::json([
            'message_key' => $reload['request_id'] !== null
                ? 'supervisor.conf_deleted_restarting'
                : 'supervisor.conf_deleted',
            'request_id' => $reload['request_id'],
            'supervisor_services' => $reload['supervisor_services'],
        ]);
    }

    /**
     * @return array{request_id: ?string, supervisor_services: array{targets: array, statuses: array}}
     */
    private function maybeRestart(string $service): array
    {
        $runtime = new SupervisorRuntime();
        $statuses = $runtime->statuses();
        $state = $statuses[$service]['state'] ?? 'not_created';
        $requestId = null;
        if (in_array($state, ['running', 'stopped'], true)) {
            $requestId = $runtime->request($service, 'restart');
            $statuses = $runtime->statuses();
        }

        return [
            'request_id' => $requestId,
            'supervisor_services' => [
                'targets' => SupervisorRuntime::targets(),
                'statuses' => $statuses,
            ],
        ];
    }
}
