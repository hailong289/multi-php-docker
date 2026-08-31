<?php

declare(strict_types=1);

namespace Manager\Models;

/**
 * Lightweight parser for compose/*.yml service blocks (no YAML extension required).
 */
final class ComposeFileParser
{
    /**
     * @return list<array{name: string, profile: ?string, container: ?string, image: ?string, has_build: bool}>
     */
    public static function services(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (!preg_match('/^services:\s*$\n(.*?)(?=^[a-zA-Z0-9][a-zA-Z0-9._-]*:\s*$|\z)/ms', $content, $section)) {
            return [];
        }

        $services = [];
        if (!preg_match_all(
            '/^  ([a-zA-Z0-9][a-zA-Z0-9._-]*):\s*$\n((?:    .*\n?)*)/m',
            $section[1],
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        foreach ($matches as $match) {
            $name = $match[1];
            $block = $match[2];
            if ($name === 'networks' || $name === 'volumes') {
                continue;
            }
            $services[] = [
                'name' => $name,
                'profile' => self::profileFromBlock($block),
                'container' => self::containerFromBlock($block),
                'image' => self::imageFromBlock($block),
                'has_build' => self::needsBuild($block),
            ];
        }

        return $services;
    }

    private static function profileFromBlock(string $block): ?string
    {
        if (preg_match('/^\s{4}profiles:\s*\[\s*"([^"]+)"\s*\]/m', $block, $m)) {
            return $m[1];
        }
        if (preg_match('/^\s{4}profiles:\s*$\n\s{6}-\s*"?([^"\n]+)"?\s*$/m', $block, $m)) {
            return trim($m[1], " \t\"'");
        }

        return null;
    }

    private static function containerFromBlock(string $block): ?string
    {
        if (preg_match('/^\s{4}container_name:\s*([^\s#]+)/m', $block, $m)) {
            return trim($m[1], " \t\"'");
        }

        return null;
    }

    private static function imageFromBlock(string $block): ?string
    {
        if (preg_match('/^\s{4}image:\s*([^\s#]+)/m', $block, $m)) {
            return trim($m[1], " \t\"'");
        }

        return null;
    }

    /** True when the service must be built locally (build: without a pre-set image:). */
    private static function needsBuild(string $block): bool
    {
        $hasBuild = (bool) preg_match('/^\s{4}build:/m', $block);
        $hasImage = (bool) preg_match('/^\s{4}image:/m', $block);

        return $hasBuild && !$hasImage;
    }
}
