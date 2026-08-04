<?php

declare(strict_types=1);

namespace Manager\Models;

final class PhpExtensionCatalog
{
    public const NAMES = [
        'redis', 'imagick', 'mongodb', 'xdebug',
        'bcmath', 'intl', 'opcache', 'soap', 'exif', 'gmp',
    ];

    public static function isCurated(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    public static function unsupportedOn(string $service, string $name): bool
    {
        return false;
    }

    /**
     * @param list<string> $modules names from php -m
     * @return list<array{name:string,status:string}>
     */
    public static function entries(string $service, array $modules, string $iniContent): array
    {
        $loaded = [];
        foreach ($modules as $m) {
            $loaded[strtolower($m)] = true;
        }
        $editor = new PhpIniEditor();
        $out = [];
        foreach (self::NAMES as $name) {
            if (self::unsupportedOn($service, $name)) {
                $out[] = ['name' => $name, 'status' => 'unsupported_on_version'];
                continue;
            }
            if (isset($loaded[strtolower($name)])) {
                $out[] = ['name' => $name, 'status' => 'loaded'];
                continue;
            }
            $line = $editor->extensionLineStatus($iniContent, $name);
            if ($line === 'commented') {
                $out[] = ['name' => $name, 'status' => 'disabled_in_ini'];
            } elseif ($line === 'active') {
                // Enabled in php.ini but not loaded yet (needs install and/or FPM restart).
                $out[] = ['name' => $name, 'status' => 'enabled_in_ini'];
            } else {
                $out[] = ['name' => $name, 'status' => 'available_to_install'];
            }
        }

        return $out;
    }
}
