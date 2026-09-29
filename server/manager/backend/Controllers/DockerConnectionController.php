<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\DockerConnection;
use Manager\Models\PhpControllerDaemon;

final class DockerConnectionController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        return Response::json([
            'docker_connection' => (new DockerConnection())->status(true),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $result = (new DockerConnection())->save(is_array($body) ? $body : []);
        $status = (new DockerConnection())->status(true);

        // Env is read at php-controller start; restart when daemon exists so DOCKER_* applies.
        $daemon = new PhpControllerDaemon();
        $daemonState = $daemon->status()['state'];
        if ($daemonState === 'running' || $daemonState === 'stopped') {
            try {
                $daemon->restart();
                $status = (new DockerConnection())->status(true);
            } catch (\Throwable) {
                // Keep saved config even if restart fails; UI can restart manually.
            }
        }

        return Response::json([
            'message_key' => $result['message_key'],
            'docker_connection' => $status,
            'php_controller_daemon' => $daemon->status(),
        ]);
    }

    public function test(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $override = is_array($body) && $body !== [] ? $body : null;
        $result = (new DockerConnection())->test($override);

        return Response::json($result);
    }
}
