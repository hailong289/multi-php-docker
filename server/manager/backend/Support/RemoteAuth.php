<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Http\HttpException;

final class RemoteAuth
{
    private const SESSION_AUTH = 'manager_authenticated';
    private const RATE_FILE_PREFIX = 'manager_login_rate_';

    public static function isRemote(): bool
    {
        return Config::managerRemote();
    }

    public static function credentialsConfigured(): bool
    {
        return Config::managerUsername() !== '' && Config::managerPassword() !== '';
    }

    public static function isLocked(): bool
    {
        return self::isRemote() && !self::credentialsConfigured();
    }

    public static function isAuthenticated(): bool
    {
        if (!self::isRemote()) {
            return true;
        }

        return !empty($_SESSION[self::SESSION_AUTH]);
    }

    public static function requireAuthenticated(): void
    {
        if (!self::isRemote()) {
            return;
        }
        if (self::isLocked()) {
            throw new HttpException('error.manager_remote_locked', 503);
        }
        if (!self::isAuthenticated()) {
            throw new HttpException('error.unauthorized', 401);
        }
    }

    public static function attemptLogin(string $username, string $password, string $clientIp): bool
    {
        if (!self::isRemote()) {
            return false;
        }
        if (self::isLocked()) {
            throw new HttpException('error.manager_remote_locked', 503);
        }
        if (self::isRateLimited($clientIp)) {
            throw new HttpException('error.login_rate_limited', 429);
        }

        $ok = hash_equals(Config::managerUsername(), $username)
            && hash_equals(Config::managerPassword(), $password);

        if (!$ok) {
            self::recordFailure($clientIp);

            return false;
        }

        self::clearFailures($clientIp);
        $_SESSION[self::SESSION_AUTH] = true;
        unset($_SESSION['csrf_token']);
        Csrf::token();

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_AUTH]);
        unset($_SESSION['csrf_token']);
    }

    private static function ratePath(string $clientIp): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $clientIp) ?: 'unknown';

        return rtrim(Config::runtimePath(), '/') . '/' . self::RATE_FILE_PREFIX . $safe . '.json';
    }

    private static function isRateLimited(string $clientIp): bool
    {
        $path = self::ratePath($clientIp);
        if (!is_file($path)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return false;
        }
        $failures = (int) ($data['failures'] ?? 0);
        $windowStart = (int) ($data['window_start'] ?? 0);
        if (time() - $windowStart > 300) {
            return false;
        }

        return $failures >= 10;
    }

    private static function recordFailure(string $clientIp): void
    {
        $path = self::ratePath($clientIp);
        $data = ['failures' => 0, 'window_start' => time()];
        if (is_file($path)) {
            $parsed = json_decode((string) file_get_contents($path), true);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }
        if (time() - (int) ($data['window_start'] ?? 0) > 300) {
            $data = ['failures' => 0, 'window_start' => time()];
        }
        $data['failures'] = (int) ($data['failures'] ?? 0) + 1;
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }

    private static function clearFailures(string $clientIp): void
    {
        $path = self::ratePath($clientIp);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
