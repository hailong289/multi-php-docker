<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\NginxReload;
use Manager\Models\NginxManagement;

final class NginxController extends Controller
{
    public function management(Request $request, array $params = []): Response
    {
        return Response::json(['nginx_management' => (new NginxManagement())->details()]);
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
