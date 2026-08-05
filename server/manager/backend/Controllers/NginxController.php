<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\NginxReload;
use Manager\Models\NginxManagement;
use Manager\Models\NginxTemplates;

final class NginxController extends Controller
{
    public function management(Request $request, array $params = []): Response
    {
        return Response::json(['nginx_management' => (new NginxManagement())->details()]);
    }

    public function templates(Request $request, array $params = []): Response
    {
        return Response::json([
            'templates' => (new NginxTemplates())->list(),
        ]);
    }

    public function templateShow(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');
        return Response::json([
            'template' => (new NginxTemplates())->read($name),
        ]);
    }

    public function templateSave(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');
        $body = $request->json();
        $content = (string) ($body['content'] ?? '');
        $softReload = array_key_exists('soft_reload', $body)
            ? (bool) $body['soft_reload']
            : true;

        $saved = (new NginxTemplates())->save($name, $content, $softReload);

        return Response::json([
            'template' => $saved,
            'message_key' => $softReload
                ? 'nginx.template_saved_reloading'
                : 'nginx.template_saved',
        ]);
    }

    public function softReload(Request $request, array $params = []): Response
    {
        (new NginxTemplates())->requestSoftReload();

        return Response::json([
            'message_key' => 'nginx.soft_reload_requested',
        ]);
    }

    public function action(Request $request, array $params = []): Response
    {
        $action = (string) ($params['action'] ?? '');
        $runtime = new NginxManagement();
        return Response::json([
            'request_id' => $runtime->requestAction($action),
            'message_key' => 'nginx.action_requested',
        ]);
    }

    public function test(Request $request, array $params = []): Response
    {
        (new NginxManagement())->requestTest();
        return Response::json(['message_key' => 'nginx.test_requested']);
    }

    public function status(Request $request, array $params = []): Response
    {
        return Response::json([
            'nginx_status' => (new NginxReload())->status(),
        ]);
    }

    public function reload(Request $request, array $params = []): Response
    {
        (new NginxReload())->request();

        return Response::json([
            'message_key' => 'reload.requested',
        ]);
    }
}
