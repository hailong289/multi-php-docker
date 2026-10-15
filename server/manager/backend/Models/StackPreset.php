<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;

final class StackPreset
{
    public function program(string $preset, string $projectDir): string
    {
        if (!in_array($preset, ['laravel', 'symfony', 'wordpress'], true)) {
            throw new HttpException('preset.unknown', 400);
        }
        if (!preg_match('#^/var/www/source/[A-Za-z0-9._-]+$#', $projectDir)) {
            throw new HttpException('preset.invalid_directory', 400);
        }
        if ($preset === 'wordpress') {
            return '';
        }
        $folder = basename($projectDir);
        $program = str_replace(['-', '.'], '_', $folder) . '_worker';
        $command = $preset === 'laravel'
            ? 'php artisan queue:work --sleep=3 --tries=3 --timeout=90'
            : 'php bin/console messenger:consume async --time-limit=3600';

        return <<<CONF
[program:{$program}]
directory={$projectDir}
command={$command}
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
startsecs=3
startretries=3
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/{$folder}-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5

CONF;
    }
}
