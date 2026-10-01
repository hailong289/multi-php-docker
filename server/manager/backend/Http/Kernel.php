<?php

declare(strict_types=1);

namespace Manager\Http;

use Manager\Support\Csrf;

final class Kernel
{
    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            Csrf::validate($request->header('x-csrf-token'));
        }

        // Terminal I/O is chatty; holding the session file lock serializes
        // input POSTs behind output polls on php -S / file sessions.
        $path = $request->path();
        if (
            session_status() === PHP_SESSION_ACTIVE
            && (str_starts_with($path, '/terminal/') || str_starts_with($path, '/status/'))
        ) {
            session_write_close();
        }

        return $next($request);
    }
}
