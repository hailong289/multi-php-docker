<?php

declare(strict_types=1);

namespace Manager\Support;

/**
 * Reads and updates KEY=value lines in the project .env that docker compose interpolates.
 */
final class ComposeEnv
{
    public static function path(): string
    {
        return Config::projectPath() . '/.env';
    }

    public static function get(string $key, ?string $path = null): ?string
    {
        $content = (string) @file_get_contents($path ?? self::path());
        if (!preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $content, $m)) {
            return null;
        }

        return trim($m[1], " \t\r\"'");
    }

    /**
     * Replaces existing keys in place and appends missing ones; other lines are kept.
     *
     * @param array<string, string> $values
     */
    public static function set(array $values, ?string $path = null): bool
    {
        $path ??= self::path();
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        foreach ($values as $key => $value) {
            $line = $key . '=' . $value;
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace_callback($pattern, static fn (): string => $line, $content, 1);
                continue;
            }
            if ($content !== '' && !str_ends_with($content, "\n")) {
                $content .= "\n";
            }
            $content .= $line . "\n";
        }

        return AtomicFile::write($path, $content);
    }
}
