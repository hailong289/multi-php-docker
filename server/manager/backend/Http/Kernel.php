<?php

declare(strict_types=1);

namespace Manager\Http;

use Manager\Support\Csrf;
use Manager\Support\RemoteAuth;

final class Kernel
{
    private const PUBLIC_PATHS = [
        '/session',
        '/login',
    ];

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            Csrf::validate($request->header('x-csrf-token'));
        }

        if (!in_array($request->path(), self::PUBLIC_PATHS, true)) {
            RemoteAuth::requireAuthenticated();
        }

        return $next($request);
    }
}
