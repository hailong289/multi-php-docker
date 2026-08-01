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

        return $next($request);
    }
}
