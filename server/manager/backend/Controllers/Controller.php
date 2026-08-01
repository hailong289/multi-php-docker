<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;
use Manager\Models\HostsSync;
use Manager\Models\NginxReload;
use Manager\Models\PhpRuntime;
use Manager\Models\PhpVersionCatalog;
use Manager\Support\Csrf;

abstract class Controller
{
    protected function bootstrapPayload(): array
    {
        $env = new EnvConfig();
        $servers = $env->all();
        $nginx = new NginxReload();
        $php = new PhpRuntime();
        $hosts = new HostsSync();

        return [
            'servers' => $servers,
            'php_versions' => PhpVersionCatalog::forApi(),
            'profiles' => $env->requiredProfiles($servers),
            'apply_command' => $env->applyCommand($servers),
            'nginx_status' => $nginx->status(),
            'hosts_status' => $hosts->status(),
            'hosts_extras' => $hosts->extras(),
            'pending_sync' => $hosts->pendingSync(),
            'php_controllers' => [
                'targets' => PhpRuntime::targets(),
                'statuses' => $php->statuses(),
            ],
            'csrf_token' => Csrf::token(),
        ];
    }
}
