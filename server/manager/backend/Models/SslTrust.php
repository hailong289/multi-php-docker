<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\AtomicFile;

final class SslTrust
{
    /** @return array{url: string, token: string} */
    public function request(string $projectPath, string $appName, string $domain): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $appName)) {
            throw new HttpException('ssl.trust_requires_generated', 400);
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,253}$/', $domain) || str_contains($domain, '..')) {
            throw new HttpException('ssl.trust_requires_generated', 400);
        }
        $token = bin2hex(random_bytes(16));
        $dir = rtrim($projectPath, '/') . '/runtime';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException('ssl.trust_write_failed', 500);
        }
        $ok = AtomicFile::write($dir . '/ssl.trust', json_encode([
            'token' => $token,
            'app_name' => $appName,
            'domain_name' => $domain,
            'created_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES) . "\n");
        if (!$ok) {
            throw new HttpException('ssl.trust_write_failed', 500);
        }

        return [
            'url' => 'multi-php-ssl://issue?id=' . $token,
            'token' => $token,
        ];
    }

    public function lastResult(string $projectPath): string
    {
        $path = rtrim($projectPath, '/') . '/runtime/ssl.trust.result';
        if (!is_file($path)) {
            return '';
        }
        $lines = preg_split("/\r\n|\n|\r/", trim((string) file_get_contents($path))) ?: [];
        $last = $lines === [] ? '' : (string) $lines[count($lines) - 1];

        return $last;
    }
}
