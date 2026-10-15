<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\PhpVersionId;
use Manager\Models\SiteCron;
use Manager\Models\SupervisorConfigs;

final class CronController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $service = $this->service($params);
        $configs = new SupervisorConfigs();
        $cron = new SiteCron();
        $jobs = [];
        foreach ($configs->list($service) as $file) {
            $name = (string) ($file['name'] ?? '');
            $read = $configs->read($service, $name);
            $content = (string) ($read['content'] ?? '');
            if (!str_contains($content, '; manager-cron ')) {
                continue;
            }
            $jobs[] = $cron->parse($content) + ['name' => $name];
        }

        return Response::json(['jobs' => $jobs]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $service = $this->service($params);
        $body = $request->json();
        $id = bin2hex(random_bytes(6));
        $cron = new SiteCron();
        $content = $cron->render(
            $id,
            (string) ($body['directory'] ?? ''),
            (string) ($body['command'] ?? ''),
            (int) ($body['minutes'] ?? 0),
        );
        $name = 'cron-' . $id . '.conf';
        (new SupervisorConfigs())->write($service, $name, $content, true);

        return Response::json([
            'job' => $cron->parse($content) + ['name' => $name],
            'message_key' => 'cron.saved',
        ], 201);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $service = $this->service($params);
        $name = (string) ($params['name'] ?? '');
        (new SupervisorConfigs())->delete($service, $name);

        return Response::json(['message_key' => 'cron.deleted']);
    }

    /** @param array<string, mixed> $params */
    private function service(array $params): string
    {
        $service = (string) ($params['service'] ?? '');
        if (!PhpVersionId::isValidSupervisorService($service)) {
            throw new HttpException('supervisor.invalid_service', 400);
        }

        return $service;
    }
}
