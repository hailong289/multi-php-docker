<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Support\Csrf;
use Manager\Models\HostsSync;

final class SessionController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        return Response::json([
            'csrf_token' => Csrf::token(),
            'hosts_write_enabled' => HostsSync::writeEnabled(),
        ]);
    }
}
