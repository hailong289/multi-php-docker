<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;

final class SiteCron
{
    public function assertCommand(string $command): string
    {
        $command = trim($command);
        if ($command === '' || strlen($command) > 200 || preg_match('/[;|&`$<>\n\r]/', $command)) {
            throw new HttpException('cron.invalid_command', 400);
        }

        return $command;
    }

    public function render(string $id, string $directory, string $command, int $minutes): string
    {
        if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
            throw new HttpException('cron.invalid_command', 400);
        }
        if (!preg_match('#^/var/www/source/[A-Za-z0-9._/-]+$#', $directory) || str_contains($directory, '..')) {
            throw new HttpException('cron.invalid_directory', 400);
        }
        if ($minutes < 1 || $minutes > 60) {
            throw new HttpException('cron.invalid_interval', 400);
        }
        $command = $this->assertCommand($command);
        $seconds = $minutes * 60;
        $loop = escapeshellarg("while true; do {$command}; sleep {$seconds}; done");

        return <<<CONF
; manager-cron minutes={$minutes}
[program:cron_{$id}]
directory={$directory}
command=/bin/sh -c {$loop}
autostart=true
autorestart=true
startsecs=3
startretries=3
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/cron-{$id}.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5

CONF;
    }

    /** @return array{id: string, directory: string, command: string, minutes: int} */
    public function parse(string $conf): array
    {
        if (!preg_match('/; manager-cron minutes=(\d+)/', $conf, $minutes)) {
            throw new HttpException('cron.invalid_command', 400);
        }
        if (!preg_match('/^directory=(.+)$/m', $conf, $directory)) {
            throw new HttpException('cron.invalid_directory', 400);
        }
        if (!preg_match('/\[program:cron_([a-f0-9]{12})\]/', $conf, $id)) {
            throw new HttpException('cron.invalid_command', 400);
        }
        if (!preg_match('/do (.+); sleep \d+/', $conf, $command)) {
            throw new HttpException('cron.invalid_command', 400);
        }

        return [
            'id' => $id[1],
            'directory' => trim($directory[1]),
            'command' => $command[1],
            'minutes' => (int) $minutes[1],
        ];
    }
}
