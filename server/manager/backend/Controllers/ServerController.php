<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;
use Manager\Models\InfraRuntime;
use Manager\Models\PhpVersionId;
use Manager\Models\SslCertificates;
use Manager\Models\StackPreset;
use Manager\Models\SupervisorConfigs;
use Manager\Support\Config;

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

        $ssl = new SslCertificates(Config::projectPath());
        $ssl->persist(
            [],
            $validation['server'],
            (string) ($validation['ssl_certificate'] ?? ''),
            (string) ($validation['ssl_private_key'] ?? '')
        );

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

    public function storePreset(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $presetId = (string) ($body['preset'] ?? '');
        unset($body['preset']);
        $env = new EnvConfig();
        $servers = $env->all();
        $validation = $env->validate($body, $servers, null);
        if ($validation['errors'] !== []) {
            throw new HttpException('validation.failed', 422, $validation['errors']);
        }
        $server = $validation['server'];
        $projectDir = (string) ($server['SERVER_PATH'] ?? '');
        if (str_ends_with($projectDir, '/public')) {
            $projectDir = dirname($projectDir);
        }
        $program = (new StackPreset())->program($presetId, $projectDir);
        $appFile = strtolower((string) ($server['APP_NAME'] ?? ''));
        $appFile = preg_replace('/[^a-z0-9._-]/', '', $appFile) ?? '';
        if ($program !== '' && $appFile === '') {
            throw new HttpException('preset.invalid_directory', 400);
        }

        $ssl = new SslCertificates(Config::projectPath());
        $ssl->persist([], $server, (string) ($validation['ssl_certificate'] ?? ''), (string) ($validation['ssl_private_key'] ?? ''));
        $key = $env->nextKey($servers);
        $servers[$key] = $server;
        $env->save($servers);

        $started = [];
        $infra = new InfraRuntime();
        $statuses = $infra->statuses();
        foreach (['mysql', 'mailpit'] as $service) {
            if (($statuses[$service]['state'] ?? '') !== 'not_created') {
                continue;
            }
            try {
                $infra->request($service, 'create');
                $started[] = $service;
            } catch (HttpException) {
                // The site is already saved. A stopped Docker daemon should not roll that back.
            }
        }

        $supervisorFile = null;
        if ($program !== '') {
            try {
                $supervisorFile = $appFile . '-worker.conf';
                (new SupervisorConfigs())->write(
                    PhpVersionId::supervisorService((string) $validation['php_version']),
                    $supervisorFile,
                    $program,
                    true,
                );
            } catch (HttpException) {
                $supervisorFile = null;
            }
        }

        return Response::json([
            'key' => $key,
            'server' => $server,
            'preset' => $presetId,
            'services' => $started,
            'supervisor_file' => $supervisorFile,
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

        $ssl = new SslCertificates(Config::projectPath());
        $ssl->persist(
            $servers[$key],
            $validation['server'],
            (string) ($validation['ssl_certificate'] ?? ''),
            (string) ($validation['ssl_private_key'] ?? '')
        );

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

        $appName = (string) ($servers[$key]['APP_NAME'] ?? '');
        unset($servers[$key]);
        $env->save($servers);
        if ($appName !== '') {
            (new SslCertificates(Config::projectPath()))->deleteApp($appName);
        }

        return Response::json([
            'message_key' => 'flash.deleted',
            'bootstrap' => $this->bootstrapPayload(),
        ]);
    }
}
