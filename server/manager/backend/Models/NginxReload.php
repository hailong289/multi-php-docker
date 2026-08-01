<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

final class NginxReload
{
    private readonly string $runtimePath;

    public function __construct(?string $runtimePath = null)
    {
        $this->runtimePath = $runtimePath ?? Config::runtimePath();
    }

    public function status(): ?array
    {
        $statusFile = rtrim($this->runtimePath, '/') . '/nginx.status.json';
        if (!is_file($statusFile) || !is_readable($statusFile)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($statusFile), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function request(): void
    {
        if (!is_dir($this->runtimePath) && !mkdir($this->runtimePath, 0775, true) && !is_dir($this->runtimePath)) {
            throw new HttpException('error.runtime_directory', 500);
        }
        if (file_put_contents($this->runtimePath . '/nginx.reload', date(DATE_ATOM), LOCK_EX) === false) {
            throw new HttpException('error.reload_request', 500);
        }
    }
}
