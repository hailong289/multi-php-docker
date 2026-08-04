<?php

declare(strict_types=1);

namespace Manager\Models;

final class PhpExtensionCatalog
{
    public const NAMES = [
        'redis', 'imagick', 'mongodb', 'xdebug',
        'bcmath', 'intl', 'opcache', 'soap', 'exif', 'gmp',
    ];

    /** Common always-on modules — hide from the manage table as "custom". */
    private const SKIP_DISPLAY = [
        'core', 'standard', 'spl', 'reflection', 'date', 'pcre', 'hash', 'json',
        'openssl', 'libxml', 'zlib', 'tokenizer', 'filter', 'session', 'posix',
        'phar', 'ctype', 'iconv', 'pdo', 'xml', 'xmlreader', 'xmlwriter',
        'simplexml', 'dom', 'fileinfo', 'mbstring', 'mysqlnd', 'sodium',
        'sqlite3', 'pdo_sqlite', 'curl', 'readline', 'random', 'ffi',
    ];

    public static function isCurated(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $name);
    }

    public static function isSkippedBuiltin(string $name): bool
    {
        return in_array(strtolower($name), self::SKIP_DISPLAY, true);
    }

    public static function unsupportedOn(string $service, string $name): bool
    {
        return false;
    }

    /**
     * Extra names (custom pecl / non-curated) to show alongside curated.
     *
     * @param list<string> $extraNames
     * @param list<string> $modules names from php -m
     * @return list<array{name:string,status:string}>
     */
    public static function entries(string $service, array $modules, string $iniContent, array $extraNames = []): array
    {
        $loaded = [];
        foreach ($modules as $m) {
            $loaded[strtolower($m)] = true;
        }
        $names = self::NAMES;
        foreach ($extraNames as $extra) {
            $extra = strtolower(trim($extra));
            if ($extra === '' || !self::isValidName($extra) || self::isCurated($extra)) {
                continue;
            }
            if (!in_array($extra, $names, true)) {
                $names[] = $extra;
            }
        }
        foreach (array_keys($loaded) as $mod) {
            if (!self::isValidName($mod) || self::isCurated($mod) || self::isSkippedBuiltin($mod)) {
                continue;
            }
            if (!in_array($mod, $names, true)) {
                $names[] = $mod;
            }
        }

        $editor = new PhpIniEditor();
        $out = [];
        foreach ($names as $name) {
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
            } elseif (self::isCurated($name)) {
                $out[] = ['name' => $name, 'status' => 'available_to_install'];
            }
            // Non-curated absent names are omitted (only shown once loaded / in ini).
        }

        return $out;
    }
}
