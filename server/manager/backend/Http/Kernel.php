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

        // Terminal I/O is chatty; holding the session file lock serializes
        // input POSTs behind output polls on php -S / file sessions.
        if (str_starts_with($request->path(), '/terminal/') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $next($request);
    }
}
