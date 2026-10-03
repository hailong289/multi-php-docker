<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\SourceLogs;

final class SourceLogController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        return Response::json([
            'sources' => (new SourceLogs())->list(),
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $key = (string) ($params['key'] ?? '');
        $file = (string) $request->queryParam('file', '');
        $lines = (int) $request->queryParam('lines', 200);
        $full = in_array(strtolower((string) $request->queryParam('full', '')), ['1', 'true', 'yes'], true);

        return Response::json((new SourceLogs())->read($key, $file, $lines, $full));
    }

    public function stream(Request $request, array $params = []): Response
    {
        $key = (string) ($params['key'] ?? '');
        $file = (string) $request->queryParam('file', '');
        $logs = new SourceLogs();
        $path = $logs->resolvedPath($key, $file);

        return Response::stream(static function () use ($logs, $path): void {
            $logs->follow($path);
        });
    }

    public function update(Request $request, array $params = []): Response
    {
        $key = (string) ($params['key'] ?? '');
        $body = $request->json();
        $result = (new SourceLogs())->write(
            $key,
            (string) ($body['file'] ?? ''),
            (string) ($body['content'] ?? ''),
        );

        return Response::json([
            'source' => $result['source'],
            'file' => $result['file'],
            'log' => $result['log'],
            'message_key' => 'source_logs.saved',
        ]);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $key = (string) ($params['key'] ?? '');
        $body = $request->json();
        $file = (string) ($body['file'] ?? $request->queryParam('file', ''));
        $result = (new SourceLogs())->delete($key, $file);

        return Response::json([
            'source' => $result['source'],
            'file' => $result['file'],
            'message_key' => 'source_logs.deleted',
        ]);
    }

    public function updateConfig(Request $request, array $params = []): Response
    {
        $key = (string) ($params['key'] ?? '');
        $body = $request->json();
        $framework = (string) ($body['framework'] ?? '');
        $logPath = (string) ($body['log_path'] ?? '');
        $source = (new SourceLogs())->saveConfig($key, $framework, $logPath);

        return Response::json([
            'source' => $source,
            'message_key' => 'source_logs.config_saved',
        ]);
    }

    public function clear(Request $request, array $params = []): Response
    {
        $key = (string) ($params['key'] ?? '');
        $file = (string) ($request->json()['file'] ?? '');
        $logs = new SourceLogs();
        $result = $logs->clear($key, $file);

        return Response::json([
            'source' => $result['source'],
            'file' => $result['file'],
            'log' => $result['log'],
            'message_key' => 'source_logs.cleared',
        ]);
    }
}
