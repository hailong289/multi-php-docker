<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;

final class ServerController extends Controller
{
    public function store(Request $request, array $params = []): Response
    {
        $env = new EnvConfig();
        $servers = $env->all();
        $validation = $env->validate($request->json(), $servers, null);
        if ($validation['errors'] !== []) {
            throw new HttpException('validation.failed', 422, $validation['errors']);
        }

        $key = $env->nextKey($servers);
        $servers[$key] = $validation['server'];
        $env->save($servers);

        return Response::json([
            'key' => $key,
            'server' => $validation['server'],
            'message_key' => 'flash.added',
            'bootstrap' => $this->bootstrapPayload(),
        ], 201);
    }

    public function update(Request $request, array $params = []): Response
    {
        $key = (string) ($params['key'] ?? '');
        $env = new EnvConfig();
        $servers = $env->all();
        if (!isset($servers[$key])) {
            throw new HttpException('error.server_missing', 404);
        }

        $validation = $env->validate($request->json(), $servers, $key);
        if ($validation['errors'] !== []) {
            throw new HttpException('validation.failed', 422, $validation['errors']);
        }

        $servers[$key] = $validation['server'];
        $env->save($servers);

        return Response::json([
            'key' => $key,
            'server' => $validation['server'],
            'message_key' => 'flash.updated',
            'bootstrap' => $this->bootstrapPayload(),
        ]);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $key = (string) ($params['key'] ?? '');
        $env = new EnvConfig();
        $servers = $env->all();
        if (!isset($servers[$key])) {
            throw new HttpException('error.server_missing', 404);
        }

        unset($servers[$key]);
        $env->save($servers);

        return Response::json([
            'message_key' => 'flash.deleted',
            'bootstrap' => $this->bootstrapPayload(),
        ]);
    }
}
