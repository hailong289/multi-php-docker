<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\TerminalSession;

final class TerminalController extends Controller
{
    public function create(Request $request, array $params = []): Response
    {
        $serverKey = trim((string) $request->input('server_key', ''));
        $cols = (int) $request->input('cols', 120);
        $rows = (int) $request->input('rows', 32);
        $result = TerminalSession::create($serverKey, $cols, $rows);

        return Response::json($result);
    }

    public function output(Request $request, array $params = []): Response
    {
        $id = (string) ($params['id'] ?? '');
        $since = (int) $request->queryParam('since', 0);
        $result = TerminalSession::readOutput($id, $since);

        return Response::json([
            'offset' => $result['offset'],
            'data' => base64_encode($result['data']),
            'closed' => $result['closed'],
            'exit_code' => $result['exit_code'],
        ]);
    }

    public function input(Request $request, array $params = []): Response
    {
        $id = (string) ($params['id'] ?? '');
        $b64 = (string) $request->input('data', '');
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            $raw = '';
        }
        TerminalSession::writeInput($id, $raw);

        return Response::json(['ok' => true]);
    }

    public function resize(Request $request, array $params = []): Response
    {
        $id = (string) ($params['id'] ?? '');
        $cols = (int) $request->input('cols', 120);
        $rows = (int) $request->input('rows', 32);
        TerminalSession::resize($id, $cols, $rows);

        return Response::json(['ok' => true]);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (string) ($params['id'] ?? '');
        TerminalSession::close($id);

        return Response::json(['ok' => true]);
    }
}
