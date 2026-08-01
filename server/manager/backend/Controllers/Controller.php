<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\EnvConfig;
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

        return [
            'servers' => $servers,
            'php_versions' => PhpVersionCatalog::forApi(),
            'profiles' => $env->requiredProfiles($servers),
            'apply_command' => $env->applyCommand($servers),
            'nginx_status' => $nginx->status(),
            'php_controllers' => [
                'targets' => PhpRuntime::targets(),
                'statuses' => $php->statuses(),
            ],
            'csrf_token' => Csrf::token(),
        ];
    }
}
