<?php

declare(strict_types=1);

namespace Manager\Support;

final class Config
{
    public static function envPath(): string
    {
        return getenv('MANAGER_ENV_PATH') ?: '/var/host-project/env.json';
    }

    public static function runtimePath(): string
    {
        return getenv('MANAGER_RUNTIME_PATH') ?: '/runtime';
    }

    public static function phpControllerPath(): string
    {
        return getenv('MANAGER_PHP_CONTROLLER_PATH') ?: '/runtime/php-controller';
    }

    public static function nginxLogsPath(): string
    {
        return getenv('MANAGER_NGINX_LOGS_PATH') ?: '/nginx-logs';
    }

    public static function projectPath(): string
    {
        return rtrim(getenv('MANAGER_PROJECT_PATH') ?: '/var/host-project', '/');
    }

}
