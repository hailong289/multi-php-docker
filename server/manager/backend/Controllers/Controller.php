<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;
use Manager\Models\HostsSync;
use Manager\Models\InfraCompose;
use Manager\Models\InfraRuntime;
use Manager\Models\NginxManagement;
use Manager\Models\NginxReload;
use Manager\Models\PhpControllerDaemon;
use Manager\Models\PhpRuntime;
use Manager\Models\PhpVersionCatalog;
use Manager\Models\SslCertificates;
use Manager\Models\SupervisorRuntime;
use Manager\Support\Config;
use Manager\Support\Csrf;

abstract class Controller
{
    /** @return array<string, mixed> */
    protected function serversPayload(): array
    {
        $env = new EnvConfig();
        $servers = $env->allOrEmpty();
        $ssl = new SslCertificates(Config::projectPath());
        $serversOut = [];
        foreach ($servers as $key => $server) {
            $serversOut[$key] = $ssl->enrich(is_array($server) ? $server : []);
        }

        return [
            'servers' => $serversOut,
            'php_versions' => PhpVersionCatalog::forApi(),
            'profiles' => $env->requiredProfiles($servers),
            'apply_command' => $env->applyCommand($servers),
        ];
    }

    /** @return array<string, mixed> */
    protected function nginxPayload(): array
    {
        // Same shape as GET /api/nginx/management (state + test/reload + log tails).
        return [
            'nginx_status' => (new NginxReload())->status(),
            'nginx_management' => (new NginxManagement())->details(),
        ];
    }

    /** @return array<string, mixed> */
    protected function hostsPayload(): array
    {
        $hosts = new HostsSync();

        return [
            'hosts_status' => $hosts->status(),
            'hosts_extras' => $hosts->extras(),
            'hosts_write_enabled' => HostsSync::writeEnabled(),
            'pending_sync' => $hosts->pendingSync(),
        ];
    }

    /** @return array<string, mixed> */
    protected function phpPayload(): array
    {
        $php = new PhpRuntime();

        return [
            'php_controllers' => [
                'targets' => PhpRuntime::targets(),
                'statuses' => $php->statuses(),
            ],
            'php_controller_daemon' => (new PhpControllerDaemon())->status(),
        ];
    }

    /** @return array<string, mixed> */
    protected function infraPayload(): array
    {
        $infra = new InfraRuntime();
        $composeFiles = array_values(array_filter(
            (new InfraCompose())->list(),
            static fn (array $file): bool => ($file['runtime'] ?? '') === 'compose',
        ));

        return [
            'infra_services' => [
                'targets' => InfraRuntime::targets(),
                'statuses' => $infra->statuses(),
                'compose_files' => $composeFiles,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function supervisorPayload(): array
    {
        $supervisor = new SupervisorRuntime();

        return [
            'supervisor_services' => [
                'targets' => SupervisorRuntime::targets(),
                'statuses' => $supervisor->statuses(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function bootstrapPayload(): array
    {
        return array_merge(
            $this->serversPayload(),
            $this->nginxPayload(),
            $this->hostsPayload(),
            $this->phpPayload(),
            $this->infraPayload(),
            $this->supervisorPayload(),
            ['csrf_token' => Csrf::token()],
        );
    }
}
