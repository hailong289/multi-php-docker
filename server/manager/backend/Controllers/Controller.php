<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;
use Manager\Models\HostsSync;
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
    protected function bootstrapPayload(): array
    {
        $env = new EnvConfig();
        $servers = $env->allOrEmpty();
        $nginx = new NginxReload();
        $php = new PhpRuntime();
        $infra = new InfraRuntime();
        $supervisor = new SupervisorRuntime();
        $hosts = new HostsSync();

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
            'nginx_status' => $nginx->status(),
            'nginx_management' => (new NginxManagement())->status(),
            'hosts_status' => $hosts->status(),
            'hosts_extras' => $hosts->extras(),
            'hosts_write_enabled' => HostsSync::writeEnabled(),
            'pending_sync' => $hosts->pendingSync(),
            'php_controllers' => [
                'targets' => PhpRuntime::targets(),
                'statuses' => $php->statuses(),
            ],
            'php_controller_daemon' => (new PhpControllerDaemon())->status(),
            'infra_services' => [
                'targets' => InfraRuntime::targets(),
                'statuses' => $infra->statuses(),
            ],
            'supervisor_services' => [
                'targets' => SupervisorRuntime::targets(),
                'statuses' => $supervisor->statuses(),
            ],
            'csrf_token' => Csrf::token(),
        ];
    }
}
