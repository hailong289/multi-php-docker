<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\NginxReload;

final class NginxController extends Controller
{
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
