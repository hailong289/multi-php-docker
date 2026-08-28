<?php

declare(strict_types=1);

namespace Manager\Support;

/** Read php-controller action status + last-create/start log files. */
final class ActionLogReader
{
    private const MAX_BYTES = 262144;

    /**
     * @param callable(array<string, mixed>): bool|null $statusValidator
     * @return array{
     *     state: string,
     *     message_key: string,
     *     request_id: string,
     *     available: bool,
     *     content: string,
     *     create_log: string,
     *     start_log: string,
     *     updated_at: string
     * }
     */
    public static function bundle(string $statusDir, string $key, ?callable $statusValidator = null): array
    {
        $status = [
            'state' => 'not_created',
            'message_key' => '',
            'request_id' => '',
            'updated_at' => '',
        ];
        $statusFile = rtrim($statusDir, '/') . '/' . $key . '.json';
        if (is_file($statusFile) && is_readable($statusFile)) {
            $decoded = json_decode((string) file_get_contents($statusFile), true);
            if (is_array($decoded) && ($statusValidator === null || $statusValidator($decoded))) {
                $status = array_merge($status, array_intersect_key($decoded, $status));
            }
        }

        $createLog = self::readTail(rtrim($statusDir, '/') . '/' . $key . '.last-create.log');
        $startLog = self::readTail(rtrim($statusDir, '/') . '/' . $key . '.last-start.log');
        $content = self::formatSections($createLog, $startLog);

        $updatedAt = (string) ($status['updated_at'] ?? '');
        foreach (['.last-create.log', '.last-start.log'] as $suffix) {
            $path = rtrim($statusDir, '/') . '/' . $key . $suffix;
            if (!is_file($path)) {
                continue;
            }
            $mtime = date(DATE_ATOM, (int) filemtime($path));
            if ($updatedAt === '' || strcmp($mtime, $updatedAt) > 0) {
                $updatedAt = $mtime;
            }
        }

        return [
            'state' => (string) ($status['state'] ?? 'not_created'),
            'message_key' => (string) ($status['message_key'] ?? ''),
            'request_id' => (string) ($status['request_id'] ?? ''),
            'available' => $content !== '' || ($status['message_key'] ?? '') !== '',
            'content' => $content,
            'create_log' => $createLog,
            'start_log' => $startLog,
            'updated_at' => $updatedAt,
        ];
    }

    private static function readTail(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            return '';
        }
        if ($size <= self::MAX_BYTES) {
            $content = file_get_contents($path);

            return is_string($content) ? $content : '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        fseek($handle, -self::MAX_BYTES, SEEK_END);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    private static function formatSections(string $createLog, string $startLog): string
    {
        $parts = [];
        if ($createLog !== '') {
            $parts[] = "=== create ===\n" . $createLog;
        }
        if ($startLog !== '') {
            $parts[] = "=== start ===\n" . $startLog;
        }

        return implode("\n\n", $parts);
    }
}
