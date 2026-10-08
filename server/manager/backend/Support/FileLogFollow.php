<?php

declare(strict_types=1);

namespace Manager\Support;

/**
 * Tail a log file over Server-Sent Events: snapshot, append, reset, gone.
 */
final class FileLogFollow
{
    /**
     * @return array{event: 'append'|'reset'|'gone', content: string, offset: int, inode: int, size: int, updated_at: string}
     */
    public static function readChunk(string $path, int $offset, int $inode): array
    {
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) {
            return [
                'event' => 'gone',
                'content' => '',
                'offset' => 0,
                'inode' => 0,
                'size' => 0,
                'updated_at' => '',
            ];
        }

        $stat = stat($path);
        $size = (int) ($stat['size'] ?? 0);
        $currentInode = (int) ($stat['ino'] ?? 0);
        $updatedAt = date(DATE_ATOM, (int) ($stat['mtime'] ?? time()));
        if ($currentInode !== $inode || $size < $offset) {
            $tail = self::tail($path, 200);

            return [
                'event' => 'reset',
                'content' => (string) ($tail['content'] ?? ''),
                'offset' => $size,
                'inode' => $currentInode,
                'size' => $size,
                'updated_at' => $updatedAt,
            ];
        }

        if ($size === $offset) {
            return [
                'event' => 'append',
                'content' => '',
                'offset' => $offset,
                'inode' => $currentInode,
                'size' => $size,
                'updated_at' => $updatedAt,
            ];
        }

        $length = min(65536, $size - $offset);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [
                'event' => 'gone',
                'content' => '',
                'offset' => 0,
                'inode' => 0,
                'size' => 0,
                'updated_at' => '',
            ];
        }
        fseek($handle, $offset);
        $raw = (string) fread($handle, $length);
        fclose($handle);
        $advance = strlen($raw);
        if (!preg_match('//u', $raw)) {
            $raw = (string) iconv('UTF-8', 'UTF-8//IGNORE', $raw);
        }

        return [
            'event' => 'append',
            'content' => $raw,
            'offset' => $offset + $advance,
            'inode' => $currentInode,
            'size' => $size,
            'updated_at' => $updatedAt,
        ];
    }

    public static function follow(string $path): void
    {
        ignore_user_abort(true);
        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        echo ':' . str_repeat(' ', 2048) . "\n\n";
        flush();

        clearstatcache(true, $path);
        $stat = is_file($path) ? stat($path) : false;
        if ($stat === false) {
            echo "event: gone\ndata: {}\n\n";
            flush();

            return;
        }

        $inode = (int) ($stat['ino'] ?? 0);
        $offset = (int) ($stat['size'] ?? 0);
        $tail = self::tail($path, 200);
        self::emit('snapshot', [
            'content' => (string) ($tail['content'] ?? ''),
            'size' => $offset,
            'updated_at' => (string) ($tail['updated_at'] ?? ''),
        ]);

        $started = time();
        $lastPing = time();
        while (!connection_aborted()) {
            if ((time() - $started) > 1200) {
                echo "event: reconnect\ndata: {}\n\n";
                flush();

                return;
            }

            $chunk = self::readChunk($path, $offset, $inode);
            if ($chunk['event'] === 'gone') {
                echo "event: gone\ndata: {}\n\n";
                flush();

                return;
            }

            $offset = $chunk['offset'];
            $inode = $chunk['inode'];
            if ($chunk['event'] === 'reset' || $chunk['content'] !== '') {
                self::emit($chunk['event'], [
                    'content' => $chunk['content'],
                    'size' => $chunk['size'],
                    'updated_at' => $chunk['updated_at'],
                ]);
            } elseif ((time() - $lastPing) >= 15) {
                echo ": ping\n\n";
                flush();
                $lastPing = time();
            }

            if ($chunk['size'] <= $chunk['offset']) {
                usleep(300000);
            }
        }
    }

    /** @return array{available: bool, content: string, updated_at: string} */
    private static function tail(string $path, int $lines = 200): array
    {
        $lines = max(1, min(500, $lines));
        if (!is_file($path) || !is_readable($path)) {
            return ['available' => false, 'content' => '', 'updated_at' => ''];
        }
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['available' => false, 'content' => '', 'updated_at' => ''];
        }
        $offset = max(0, $size - 1048576);
        fseek($handle, $offset);
        if ($offset > 0) {
            fgets($handle);
        }
        $content = (string) stream_get_contents($handle);
        fclose($handle);
        $rows = preg_split('/\r\n|\n|\r/', $content) ?: [];
        if ($rows !== [] && end($rows) === '') {
            array_pop($rows);
        }
        $content = implode(PHP_EOL, array_slice($rows, -$lines));
        if (!preg_match('//u', $content)) {
            $content = (string) iconv('UTF-8', 'UTF-8//IGNORE', $content);
        }

        return [
            'available' => true,
            'content' => $content,
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function emit(string $event, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        echo 'event: ' . $event . "\n";
        echo 'data: ' . $json . "\n\n";
        flush();
    }
}
