<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\ComposeFileRuntime;
use Manager\Models\InfraCompose;
use Manager\Models\InfraRuntime;

final class InfraController extends Controller
{
    /** @return array{targets: array<string, array<string, mixed>>, statuses: array<string, array<string, mixed>>, compose_files: list<array<string, mixed>>} */
    private function infraServicesPayload(InfraRuntime $runtime): array
    {
        return [
            'targets' => InfraRuntime::targets(),
            'statuses' => $runtime->statuses(),
            'compose_files' => array_values(array_filter(
                (new InfraCompose())->list(),
                static fn (array $file): bool => ($file['runtime'] ?? '') === 'compose',
            )),
        ];
    }

    public function index(Request $request, array $params = []): Response
    {
        $runtime = new InfraRuntime();

        return Response::json($this->infraServicesPayload($runtime));
    }

    public function action(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $action = (string) ($params['action'] ?? '');
        $runtime = new InfraRuntime();
        $target = InfraRuntime::targets()[$service] ?? null;
        if ($target === null) {
            throw new \Manager\Http\HttpException('services.invalid_service', 400);
        }

        if ($action === 'delete') {
            $runtime->deleteContainer($service);

            return Response::json([
                'message_key' => 'services.deleted',
                'message_parameters' => [
                    'service' => $target['label'],
                ],
                'infra_services' => $this->infraServicesPayload($runtime),
            ]);
        }

        if ($action === 'delete-image') {
            $runtime->deleteImage($service);

            return Response::json([
                'message_key' => 'services.image_deleted',
                'message_parameters' => [
                    'service' => $target['label'],
                ],
                'infra_services' => $this->infraServicesPayload($runtime),
            ]);
        }

        $requestId = $runtime->request($service, $action);

        return Response::json([
            'request_id' => $requestId,
            'message_key' => 'services.requested',
            'message_parameters' => [
                'action' => 'services.' . $action,
                'service' => $target['label'],
            ],
            'infra_services' => $this->infraServicesPayload($runtime),
        ]);
    }

    public function composeFiles(Request $request, array $params = []): Response
    {
        $compose = new InfraCompose();

        return Response::json([
            'compose_dir' => 'compose',
            'files' => $compose->list(),
            'default_content' => $compose->defaultContent(),
        ]);
    }

    public function composeFileShow(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');

        return Response::json([
            'compose' => (new InfraCompose())->readFile($name),
        ]);
    }

    public function composeFileCreate(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $name = (string) ($body['name'] ?? '');
        $compose = new InfraCompose();
        $content = (string) ($body['content'] ?? '');
        if ($content === '') {
            $content = $compose->defaultContentFor($name);
        }
        $saved = $compose->writeFile($name, $content, true);

        return Response::json([
            'compose' => $saved,
            'message_key' => 'services.compose_created',
        ], 201);
    }

    public function composeFileSave(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');
        $body = $request->json();
        $content = (string) ($body['content'] ?? '');
        $saved = (new InfraCompose())->writeFile($name, $content, false);

        return Response::json([
            'compose' => $saved,
            'message_key' => 'services.compose_saved',
        ]);
    }

    public function composeFileDelete(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');
        (new InfraCompose())->deleteFile($name);

        return Response::json([
            'message_key' => 'services.compose_deleted',
        ]);
    }

    public function composeFileAction(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');
        $action = (string) ($params['action'] ?? '');
        $runtime = new ComposeFileRuntime();
        $compose = (new InfraCompose())->readFile($name);

        if ($action === 'delete') {
            $runtime->deleteContainer($name);

            return Response::json([
                'message_key' => 'services.deleted',
                'message_parameters' => [
                    'service' => $compose['name'],
                ],
                'compose' => $compose,
                'infra_services' => $this->infraServicesPayload(new InfraRuntime()),
            ]);
        }

        if ($action === 'delete-image') {
            $runtime->deleteImage($name);

            return Response::json([
                'message_key' => 'services.image_deleted',
                'message_parameters' => [
                    'service' => $compose['name'],
                ],
                'compose' => $compose,
                'infra_services' => $this->infraServicesPayload(new InfraRuntime()),
            ]);
        }

        if ($action === 'stop') {
            $runtime->stopContainer($name);

            return Response::json([
                'message_key' => 'services.requested',
                'message_parameters' => [
                    'action' => 'services.stop',
                    'service' => $compose['name'],
                ],
                'compose' => $compose,
                'infra_services' => $this->infraServicesPayload(new InfraRuntime()),
            ]);
        }

        if ($action === 'restart') {
            $runtime->restartContainer($name);

            return Response::json([
                'message_key' => 'services.requested',
                'message_parameters' => [
                    'action' => 'services.restart',
                    'service' => $compose['name'],
                ],
                'compose' => $compose,
                'infra_services' => $this->infraServicesPayload(new InfraRuntime()),
            ]);
        }

        $requestId = $runtime->request($name, $action);

        return Response::json([
            'request_id' => $requestId,
            'message_key' => 'services.compose_action_requested',
            'message_parameters' => [
                'action' => 'services.compose_' . $action,
                'name' => $compose['name'],
            ],
            'compose' => $compose,
        ]);
    }

    public function composeFileLogs(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');
        $tail = (int) ($request->queryParam('tail') ?? 300);

        return Response::json([
            'logs' => (new ComposeFileRuntime())->logs($name, $tail),
        ]);
    }

    public function composeFileActionLogs(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');
        $compose = new InfraCompose();
        $meta = $compose->readFile($name);
        $ctx = $compose->actionContextForFile($name, (string) ($meta['content'] ?? ''));
        if ($ctx === null) {
            throw new HttpException('services.compose_action_logs_unavailable', 404);
        }

        $runtime = (string) ($ctx['runtime'] ?? '');
        $service = (string) ($ctx['service'] ?? '');
        if ($runtime === 'compose') {
            $logs = (new ComposeFileRuntime())->actionLogs($name);
        } elseif ($runtime === 'infra' && $service !== '') {
            $logs = (new InfraRuntime())->actionLogs($service);
        } else {
            throw new HttpException('services.compose_action_logs_unavailable', 404);
        }

        return Response::json(['logs' => $logs]);
    }

    public function composeShow(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');

        return Response::json([
            'compose' => (new InfraCompose())->read($service),
        ]);
    }

    public function composeSave(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $body = $request->json();
        $content = (string) ($body['content'] ?? '');
        $saved = (new InfraCompose())->write($service, $content);

        return Response::json([
            'compose' => $saved,
            'message_key' => 'services.compose_saved',
        ]);
    }

    public function logs(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $tail = (int) ($request->queryParam('tail') ?? 300);

        return Response::json([
            'logs' => (new InfraRuntime())->logs($service, $tail),
        ]);
    }

    public function actionLogs(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');

        return Response::json([
            'logs' => (new InfraRuntime())->actionLogs($service),
        ]);
    }
}
