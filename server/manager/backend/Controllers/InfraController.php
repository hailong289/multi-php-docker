<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\InfraCompose;
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
        $content = (string) ($body['content'] ?? '');
        if ($content === '') {
            $content = (new InfraCompose())->defaultContent();
        }
        $saved = (new InfraCompose())->writeFile($name, $content, true);

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
}
