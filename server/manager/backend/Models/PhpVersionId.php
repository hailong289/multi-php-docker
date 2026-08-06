<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;

/** Shared naming for php-X.Y[.Z][-alpine|-trixie] services, containers, and host paths. */
final class PhpVersionId
{
    /** X.Y or X.Y.Z */
    private const VERSION_RE = '\d+\.\d+(?:\.\d+)?';

    /** Service suffix fragment: (-alpine|-trixie)? */
    private const VARIANT_SERVICE_RE = '(?:-alpine|-trixie)?';

    public static function isValidService(string $service): bool
    {
        return (bool) preg_match('/^php-' . self::VERSION_RE . self::VARIANT_SERVICE_RE . '$/', $service);
    }

    public static function isValidMinor(string $version): bool
    {
        return (bool) preg_match('/^' . self::VERSION_RE . '$/', $version);
    }

    public static function isValidVariant(string $variant): bool
    {
        return in_array($variant, ['default', 'alpine', 'trixie'], true);
    }

    public static function serviceFrom(string $version, string $variant = 'default'): string
    {
        if (!self::isValidMinor($version)) {
            throw new HttpException('php_controller.invalid_version', 400);
        }
        $variant = self::normalizeVariant($variant);
        $service = 'php-' . $version;

        return $variant === 'default' ? $service : ($service . '-' . $variant);
    }

    /** @deprecated use serviceFrom */
    public static function serviceFromMinor(string $version): string
    {
        return self::serviceFrom($version, 'default');
    }

    public static function normalizeVariant(string $variant): string
    {
        $variant = strtolower(trim($variant));
        if ($variant === '' || $variant === 'debian' || $variant === 'default') {
            return 'default';
        }
        if ($variant === 'alpine' || $variant === 'trixie') {
            return $variant;
        }
        throw new HttpException('php_controller.invalid_variant', 400);
    }

    public static function variantFromService(string $service): string
    {
        if (str_ends_with($service, '-alpine')) {
            return 'alpine';
        }
        if (str_ends_with($service, '-trixie')) {
            return 'trixie';
        }

        return 'default';
    }

    /** Path/name suffix like "-alpine", or "" for default. */
    public static function pathSuffix(string $service): string
    {
        $variant = self::variantFromService($service);

        return $variant === 'default' ? '' : ('-' . $variant);
    }

    /** Container name fragment like "alpine", or "" for default. */
    public static function containerSuffix(string $service): string
    {
        $variant = self::variantFromService($service);

        return $variant === 'default' ? '' : $variant;
    }

    public static function minorFromService(string $service): string
    {
        if (!preg_match('/^php-(' . self::VERSION_RE . ')' . self::VARIANT_SERVICE_RE . '$/', $service, $m)) {
            throw new HttpException('php_controller.invalid_service', 400);
        }

        return $m[1];
    }

    public static function container(string $service): string
    {
        return 'php' . self::minorFromService($service) . self::containerSuffix($service) . '_container';
    }

    public static function supervisorContainer(string $service): string
    {
        if (self::isDefault($service)) {
            return 'supervisor_container';
        }

        return 'supervisor' . str_replace('.', '', self::minorFromService($service))
            . self::containerSuffix($service) . '_container';
    }

    /** Compose service name for Supervisor paired with a PHP-FPM service. */
    public static function supervisorService(string $phpService): string
    {
        if (self::isDefault($phpService)) {
            return 'supervisor';
        }

        return 'supervisor-' . self::minorFromService($phpService) . self::pathSuffix($phpService);
    }

    public static function supervisorLogRelative(string $phpService): string
    {
        if (self::isDefault($phpService)) {
            return 'logs/supervisor';
        }

        return 'logs/' . self::supervisorService($phpService);
    }

    public static function isValidSupervisorService(string $service): bool
    {
        if ($service === 'supervisor') {
            return true;
        }

        return (bool) preg_match('/^supervisor-' . self::VERSION_RE . self::VARIANT_SERVICE_RE . '$/', $service);
    }

    /** Map supervisor service id back to its PHP-FPM service id. */
    public static function phpServiceFromSupervisor(string $supervisorService): string
    {
        if ($supervisorService === 'supervisor') {
            return self::defaultService();
        }
        if (!preg_match('/^supervisor-(' . self::VERSION_RE . self::VARIANT_SERVICE_RE . ')$/', $supervisorService, $m)) {
            throw new HttpException('supervisor.invalid_service', 400);
        }

        return 'php-' . $m[1];
    }

    public static function sourceDirName(string $service): string
    {
        return 'source_php' . self::minorFromService($service) . self::pathSuffix($service);
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

        return 'configs/php' . self::minorFromService($service) . self::pathSuffix($service) . '/php.ini';
    }

    public static function iniDirRelative(string $service): string
    {
        return dirname(self::iniRelativePath($service));
    }

    public static function supervisorConfDir(string $service): string
    {
        // Default php-8.2 compose mounts ./configs/supervisor.d (whole tree).
        // Other versions mount a version subdirectory.
        if (self::isDefault($service)) {
            return 'configs/supervisor.d';
        }

        return 'configs/supervisor.d/php' . self::minorFromService($service) . self::pathSuffix($service);
    }

    public static function dockerTag(string $version, string $variant = 'default'): string
    {
        $variant = self::normalizeVariant($variant);

        return match ($variant) {
            'alpine' => $version . '-fpm-alpine',
            'trixie' => $version . '-fpm-trixie',
            default => $version . '-fpm',
        };
    }

    public static function dockerfileName(string $version, string $variant = 'default'): string
    {
        $variant = self::normalizeVariant($variant);
        $base = 'php-' . $version;

        return ($variant === 'default' ? $base : ($base . '-' . $variant)) . '.Dockerfile';
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
        $label = 'PHP ' . self::minorFromService($service);
        $variant = self::variantFromService($service);
        if ($variant === 'alpine') {
            $label .= ' (Alpine)';
        } elseif ($variant === 'trixie') {
            $label .= ' (Trixie)';
        }

        return $label;
    }
}
