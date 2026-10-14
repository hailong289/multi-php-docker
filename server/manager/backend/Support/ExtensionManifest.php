<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Models\PhpExtensionCatalog;

/**
 * Host-side list of PHP extensions to reinstall after a container is replaced.
 * Stored under php-controller-runtime/extensions, which container delete does not touch.
 */
final class ExtensionManifest
{
    /**
     * @return list<string>
     */
    public static function extensions(string $basePath, string $service): array
    {
        $data = self::read($basePath, $service);

        return $data['extensions'];
    }

    /**
     * @return list<string>
     */
    public static function failed(string $basePath, string $service): array
    {
        $data = self::read($basePath, $service);

        return $data['failed'];
    }

    public static function add(string $basePath, string $service, string $extension): void
    {
        $extension = self::validName($extension);
        if ($extension === null || !self::validService($service)) {
            return;
        }
        $data = self::read($basePath, $service);
        if (!in_array($extension, $data['extensions'], true)) {
            $data['extensions'][] = $extension;
        }
        $data['failed'] = array_values(array_filter(
            $data['failed'],
            static fn (string $name): bool => $name !== $extension,
        ));
        self::write($basePath, $service, $data);
    }

    public static function remove(string $basePath, string $service, string $extension): void
    {
        $extension = self::validName($extension);
        if ($extension === null || !self::validService($service)) {
            return;
        }
        $data = self::read($basePath, $service);
        $data['extensions'] = array_values(array_filter(
            $data['extensions'],
            static fn (string $name): bool => $name !== $extension,
        ));
        $data['failed'] = array_values(array_filter(
            $data['failed'],
            static fn (string $name): bool => $name !== $extension,
        ));
        self::write($basePath, $service, $data);
    }

    public static function fail(string $basePath, string $service, string $extension): void
    {
        $extension = self::validName($extension);
        if ($extension === null || !self::validService($service)) {
            return;
        }
        $data = self::read($basePath, $service);
        if (!in_array($extension, $data['failed'], true)) {
            $data['failed'][] = $extension;
        }
        self::write($basePath, $service, $data);
    }

    /**
     * @param list<string> $names
     */
    public static function dropNames(string $basePath, string $service, array $names): void
    {
        if (!self::validService($service)) {
            return;
        }
        $drop = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }
            $valid = self::validName($name);
            if ($valid !== null) {
                $drop[$valid] = true;
            }
        }
        if ($drop === []) {
            return;
        }
        $data = self::read($basePath, $service);
        $extensions = array_values(array_filter(
            $data['extensions'],
            static fn (string $name): bool => !isset($drop[$name]),
        ));
        $failed = array_values(array_filter(
            $data['failed'],
            static fn (string $name): bool => !isset($drop[$name]),
        ));
        if ($extensions === $data['extensions'] && $failed === $data['failed']) {
            return;
        }
        self::write($basePath, $service, ['extensions' => $extensions, 'failed' => $failed]);
    }

    public static function ensure(string $basePath, string $service, string $extension): void
    {
        $extension = self::validName($extension);
        if ($extension === null || !self::validService($service)) {
            return;
        }
        $data = self::read($basePath, $service);
        if (in_array($extension, $data['extensions'], true)) {
            return;
        }
        self::add($basePath, $service, $extension);
    }

    /**
     * Record loaded extensions that the image does not already ship.
     *
     * @param list<string> $modules
     * @param list<string> $baseline
     */
    public static function seedFromModules(string $basePath, string $service, array $modules, array $baseline): void
    {
        $skip = [];
        foreach ($baseline as $name) {
            if (!is_string($name)) {
                continue;
            }
            $valid = self::validName($name);
            if ($valid !== null) {
                $skip[$valid] = true;
            }
        }
        self::dropNames($basePath, $service, array_keys($skip));
        foreach ($modules as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $name = self::normalize($name);
            if (isset($skip[$name]) || PhpExtensionCatalog::isSkippedBuiltin($name)) {
                continue;
            }
            self::ensure($basePath, $service, $name);
        }
    }

    /**
     * @param list<string> $modules
     * @return list<array{name:string,state:string}>
     */
    public static function savedRows(string $basePath, string $service, array $modules, bool $running): array
    {
        $data = self::read($basePath, $service);
        $loaded = [];
        foreach ($modules as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $loaded[self::normalize($name)] = true;
        }
        $rows = [];
        foreach ($data['extensions'] as $name) {
            if (in_array($name, $data['failed'], true)) {
                $state = 'failed';
            } elseif (!$running) {
                $state = 'saved';
            } elseif (isset($loaded[self::normalize($name)])) {
                $state = 'loaded';
            } else {
                $state = 'pending';
            }
            $rows[] = ['name' => $name, 'state' => $state];
        }

        return $rows;
    }

    public static function forgetFailure(string $basePath, string $service, string $extension): void
    {
        $extension = self::validName($extension);
        if ($extension === null || !self::validService($service)) {
            return;
        }
        $data = self::read($basePath, $service);
        $next = array_values(array_filter(
            $data['failed'],
            static fn (string $name): bool => $name !== $extension,
        ));
        if ($next === $data['failed']) {
            return;
        }
        $data['failed'] = $next;
        self::write($basePath, $service, $data);
    }

    /**
     * First saved extension that is not loaded and not marked failed.
     *
     * @param list<string> $loaded module names from php -m
     */
    public static function firstMissing(string $basePath, string $service, array $loaded): ?string
    {
        $data = self::read($basePath, $service);
        $have = [];
        foreach ($loaded as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $have[self::normalize($name)] = true;
        }
        foreach ($data['extensions'] as $name) {
            if (in_array($name, $data['failed'], true)) {
                continue;
            }
            if (isset($have[self::normalize($name)])) {
                continue;
            }

            return $name;
        }

        return null;
    }

    public static function path(string $basePath, string $service): string
    {
        return rtrim($basePath, '/') . '/extensions/' . $service . '.json';
    }

    /**
     * @return array{extensions: list<string>, failed: list<string>}
     */
    private static function read(string $basePath, string $service): array
    {
        $decoded = JsonFile::readObject(self::path($basePath, $service));
        $extensions = [];
        $failed = [];
        if (is_array($decoded)) {
            foreach ($decoded['extensions'] ?? [] as $name) {
                $valid = is_string($name) ? self::validName($name) : null;
                if ($valid !== null && !in_array($valid, $extensions, true)) {
                    $extensions[] = $valid;
                }
            }
            foreach ($decoded['failed'] ?? [] as $name) {
                $valid = is_string($name) ? self::validName($name) : null;
                if ($valid !== null && !in_array($valid, $failed, true)) {
                    $failed[] = $valid;
                }
            }
        }

        return ['extensions' => $extensions, 'failed' => $failed];
    }

    /**
     * @param array{extensions: list<string>, failed: list<string>} $data
     */
    private static function write(string $basePath, string $service, array $data): void
    {
        $json = json_encode([
            'extensions' => $data['extensions'],
            'failed' => $data['failed'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        AtomicFile::write(self::path($basePath, $service), $json);
    }

    private static function validService(string $service): bool
    {
        return (bool) preg_match('/^php-\d+\.\d+(?:\.\d+)?(?:-alpine|-trixie)?$/', $service);
    }

    private static function validName(string $extension): ?string
    {
        $extension = strtolower(trim($extension));
        if (preg_match('/^[a-z][a-z0-9_]*$/', $extension) !== 1) {
            return null;
        }

        return $extension;
    }

    private static function normalize(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === 'zend opcache' || $name === 'zend_opcache') {
            return 'opcache';
        }

        return $name;
    }
}
