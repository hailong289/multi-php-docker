<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Support\Config;

/**
 * Discovers installed PHP services from compose/php-*.yml.
 */
final class PhpVersionCatalog
{
    public static function versions(?string $projectPath = null): array
    {
        $root = rtrim($projectPath ?? Config::projectPath(), '/');
        $versions = [];
        foreach (glob($root . '/compose/php-*.yml') ?: [] as $file) {
            $base = basename($file, '.yml');
            if (!PhpVersionId::isValidService($base)) {
                continue;
            }
            $versions[$base] = [
                'label' => PhpVersionId::label($base),
                'default' => PhpVersionId::isDefault($base),
                'container' => PhpVersionId::container($base),
                'source_prefix' => PhpVersionId::sourcePrefix($base),
                'profile' => PhpVersionId::profile($base),
            ];
        }
        uksort($versions, static function (string $a, string $b): int {
            return version_compare(PhpVersionId::minorFromService($b), PhpVersionId::minorFromService($a));
        });

        return $versions;
    }

    public static function versionFromContainer(string $container): string
    {
        foreach (self::versions() as $version => $config) {
            if ($config['container'] === $container) {
                return $version;
            }
        }

        return PhpVersionId::defaultService();
    }

    public static function forApi(?string $projectPath = null): array
    {
        $versions = [];
        foreach (self::versions($projectPath) as $id => $config) {
            $versions[$id] = [
                'id' => $id,
                'label' => $config['label'],
                'default' => (bool) $config['default'],
                'container' => $config['container'],
                'source_prefix' => $config['source_prefix'],
                'profile' => $config['profile'],
            ];
        }

        return $versions;
    }
}
