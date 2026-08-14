#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Background PTY bridge: Docker Exec attach ↔ session in.bin / out.bin
 *
 * Usage: php terminal-worker.php <session_id>
 */

$sessionId = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{16,64}$/', $sessionId)) {
    fwrite(STDERR, "invalid session\n");
    exit(1);
}

$runtime = getenv('MANAGER_RUNTIME_PATH') ?: '/runtime';
$dir = rtrim($runtime, '/') . '/terminals/' . $sessionId;
$metaPath = $dir . '/meta.json';
if (!is_file($metaPath)) {
    fwrite(STDERR, "missing meta\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Manager\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Manager\Support\DockerExec;

function worker_read_meta(string $path): array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }

    return is_array($data) ? $data : [];
}

function worker_write_meta(string $path, array $meta): void
{
    @file_put_contents($path, json_encode($meta, JSON_UNESCAPED_SLASHES));
}

function worker_mark_closed(string $dir, string $metaPath, ?int $exitCode): void
{
    $meta = worker_read_meta($metaPath);
    $meta['closed'] = true;
    if ($exitCode !== null) {
        $meta['exit_code'] = $exitCode;
    }
    worker_write_meta($metaPath, $meta);
}

$meta = worker_read_meta($metaPath);
$execId = (string) ($meta['exec_id'] ?? '');
if ($execId === '') {
    worker_mark_closed($dir, $metaPath, null);
    exit(1);
}

try {
    [$fp, $preface] = DockerExec::openAttach($execId);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    worker_mark_closed($dir, $metaPath, null);
    exit(1);
}

$outFile = $dir . '/out.bin';
$inFile = $dir . '/in.bin';
$offsetFile = $dir . '/in.offset';

if ($preface !== '') {
    file_put_contents($outFile, $preface, FILE_APPEND);
}

$inOffset = is_file($offsetFile) ? (int) trim((string) file_get_contents($offsetFile)) : 0;

/**
 * @param resource $fp
 * @return bool|null true if bytes were written, false if idle, null if attach is dead
 */
function worker_drain_input(string $inFile, string $offsetFile, $fp, int &$inOffset): ?bool
{
    clearstatcache(true, $inFile);
    $inSize = is_file($inFile) ? (int) filesize($inFile) : 0;
    if ($inSize <= $inOffset) {
        return false;
    }
    $h = fopen($inFile, 'rb');
    if ($h === false) {
        return false;
    }
    fseek($h, $inOffset);
    $chunk = stream_get_contents($h);
    fclose($h);
    if (!is_string($chunk) || $chunk === '') {
        return false;
    }
    $written = @fwrite($fp, $chunk);
    if ($written === false) {
        return null;
    }
    if ($written === 0) {
        return false;
    }
    $inOffset += $written;
    file_put_contents($offsetFile, (string) $inOffset);

    return true;
}

while (true) {
    $meta = worker_read_meta($metaPath);
    if (!empty($meta['closed'])) {
        break;
    }

    $drained = worker_drain_input($inFile, $offsetFile, $fp, $inOffset);
    if ($drained === null) {
        break;
    }

    $read = [$fp];
    $write = null;
    $except = null;
    $n = @stream_select($read, $write, $except, 0, 8000);
    if ($n > 0) {
        $data = fread($fp, 65536);
        if ($data === false || $data === '') {
            $metaInfo = stream_get_meta_data($fp);
            if (!empty($metaInfo['eof'])) {
                break;
            }
        } else {
            file_put_contents($outFile, $data, FILE_APPEND);
        }
        if (worker_drain_input($inFile, $offsetFile, $fp, $inOffset) === null) {
            break;
        }
    }
}

$exitCode = null;
$info = DockerExec::inspectExec($execId);
if (is_array($info) && array_key_exists('ExitCode', $info)) {
    $exitCode = (int) $info['ExitCode'];
}
@fclose($fp);
worker_mark_closed($dir, $metaPath, $exitCode);
exit(0);
