<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;

/** Shared naming for php-X.Y services, containers, and host paths. */
final class PhpVersionId
{
    public static function isValidService(string $service): bool
    {
        return (bool) preg_match('/^php-\d+\.\d+$/', $service);
    }

    public static function isValidMinor(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+$/', $version);
    }

    public static function serviceFromMinor(string $version): string
    {
        if (!self::isValidMinor($version)) {
            throw new HttpException('php_controller.invalid_version', 400);
        }

        return 'php-' . $version;
    }

    public static function minorFromService(string $service): string
    {
        if (!preg_match('/^php-(\d+\.\d+)$/', $service, $m)) {
            throw new HttpException('php_controller.invalid_service', 400);
        }

        return $m[1];
    }

    public static function container(string $service): string
    {
        return 'php' . self::minorFromService($service) . '_container';
    }

    public static function supervisorContainer(string $service): string
    {
        $minor = self::minorFromService($service);

        return 'supervisor' . str_replace('.', '', $minor) . '_container';
    }

    public static function sourceDirName(string $service): string
    {
        return 'source_php' . self::minorFromService($service);
    }

    public static function sourcePrefix(string $service): string
    {
        return '/var/www/' . self::sourceDirName($service);
    }

    /** php-8.2 keeps historical configs/php8 path. */
    public static function iniRelativePath(string $service): string
    {
        if ($service === 'php-8.2') {
            return 'configs/php8/php.ini';
        }

        return 'configs/php' . self::minorFromService($service) . '/php.ini';
    }

    public static function iniDirRelative(string $service): string
    {
        return dirname(self::iniRelativePath($service));
    }

    public static function supervisorConfDir(string $service): string
    {
        return 'configs/supervisor.d/php' . self::minorFromService($service);
    }

    public static function defaultService(): string
    {
        return 'php-8.2';
    }

    public static function isDefault(string $service): bool
    {
        return $service === self::defaultService();
    }

    public static function profile(string $service): ?string
    {
        return self::isDefault($service) ? null : $service;
    }

    public static function label(string $service): string
    {
        return 'PHP ' . self::minorFromService($service);
    }
}
