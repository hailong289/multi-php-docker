<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Support\Csrf;
use Manager\Support\RemoteAuth;

final class AuthController extends Controller
{
    public function login(Request $request, array $params = []): Response
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if (!RemoteAuth::attemptLogin($username, $password, $ip)) {
            throw new HttpException('error.invalid_credentials', 401);
        }

        return Response::json([
            'ok' => true,
            'authenticated' => true,
            'csrf_token' => Csrf::token(),
        ]);
    }

    public function logout(Request $request, array $params = []): Response
    {
        RemoteAuth::logout();

        return Response::json([
            'ok' => true,
            'authenticated' => false,
            'csrf_token' => Csrf::token(),
        ]);
    }
}
