<?php

declare(strict_types=1);

namespace Manager\Models;

final class PhpVersionCatalog
{
    public static function versions(): array
    {
        return [
            'php-8.2' => [
                'label' => 'PHP 8.2',
                'default' => true,
                'container' => 'php8.2_container',
                'source_prefix' => '/var/www/source_php8.2',
                'profile' => null,
            ],
            'php-8.1' => [
                'label' => 'PHP 8.1',
                'default' => false,
                'container' => 'php8.1_container',
                'source_prefix' => '/var/www/source_php8.1',
                'profile' => 'php-8.1',
            ],
            'php-8.0' => [
                'label' => 'PHP 8.0',
                'default' => false,
                'container' => 'php8.0_container',
                'source_prefix' => '/var/www/source_php8.0',
                'profile' => 'php-8.0',
            ],
            'php-7.4' => [
                'label' => 'PHP 7.4',
                'default' => false,
                'container' => 'php7.4_container',
                'source_prefix' => '/var/www/source_php7.4',
                'profile' => 'php-7.4',
            ],
        ];
    }

    public static function versionFromContainer(string $container): string
    {
        foreach (self::versions() as $version => $config) {
            if ($config['container'] === $container) {
                return $version;
            }
        }

        return 'php-8.2';
    }

    public static function forApi(): array
    {
        $versions = [];
        foreach (self::versions() as $id => $config) {
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
