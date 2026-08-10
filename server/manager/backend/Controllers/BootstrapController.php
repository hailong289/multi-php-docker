<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;

final class BootstrapController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        return Response::json($this->bootstrapPayload());
    }
}
