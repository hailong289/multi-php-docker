<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;
use Manager\Support\DockerExec;
use Manager\Support\DockerLiveState;

final class TerminalSession
{
    private const TTL_SECONDS = 1800;

    /** @return array<string, true> */
    public static function allowedPhpContainers(?string $projectPath = null): array
    {
        $map = [];
        foreach (PhpVersionCatalog::versions($projectPath) as $config) {
            $container = (string) ($config['container'] ?? '');
            if ($container !== '') {
                $map[$container] = true;
            }
        }

        return $map;
    }

    public static function baseDir(): string
    {
        return rtrim(Config::runtimePath(), '/') . '/terminals';
    }

    public static function sessionDir(string $id): string
    {
        return self::baseDir() . '/' . $id;
    }

    /**
     * Project directory for shells (SERVER_PATH with known document-root suffix stripped).
     */
    public static function projectDirFromServerPath(string $serverPath): string
    {
        $path = rtrim(str_replace('\\', '/', $serverPath), '/');
        if ($path === '' || !str_starts_with($path, '/var/www/source_')) {
            return '';
        }
        foreach (['webroot', 'public', 'web'] as $root) {
            $suffix = '/' . $root;
            if (str_ends_with($path, $suffix)) {
                return substr($path, 0, -strlen($suffix));
            }
        }

        return $path;
    }

    /**
     * @return array{session_id: string, container: string, app_name: string, domain: string, cwd: string}
     */
    public static function create(string $serverKey, int $cols, int $rows): array
    {
        self::cleanupExpired();

        if (!preg_match('/^SERVER_NAME\d+$/', $serverKey)) {
            throw new HttpException('error.validation', 422);
        }

        $cols = max(40, min(300, $cols));
        $rows = max(10, min(120, $rows));

        $servers = (new EnvConfig())->all();
        if (!isset($servers[$serverKey]) || !is_array($servers[$serverKey])) {
            throw new HttpException('error.not_found', 404);
        }
        $server = $servers[$serverKey];
        $container = (string) ($server['CONTAINER_PHP_VERSION'] ?? '');
        if ($container === '' || !isset(self::allowedPhpContainers()[$container])) {
            throw new HttpException('terminal.unavailable', 400);
        }

        if (!DockerLiveState::available()) {
            throw new HttpException('terminal.docker_unavailable', 503);
        }
        if (DockerLiveState::stateFor($container) !== 'running') {
            throw new HttpException('terminal.container_not_running', 409);
        }

        $containerId = DockerExec::containerIdByName($container);
        if ($containerId === null) {
            throw new HttpException('terminal.container_not_running', 409);
        }

        $cwd = self::projectDirFromServerPath((string) ($server['SERVER_PATH'] ?? ''));
        $shell = 'command -v bash >/dev/null 2>&1 && exec bash -l || exec sh -l';
        if ($cwd !== '') {
            $shell = 'cd ' . escapeshellarg($cwd) . ' 2>/dev/null || true; ' . $shell;
        }

        $execId = DockerExec::createExec(
            $containerId,
            ['sh', '-c', $shell],
            $cols,
            $rows,
            $cwd,
        );

        $sessionId = bin2hex(random_bytes(16));
        $dir = self::sessionDir($sessionId);
        if (!is_dir(self::baseDir()) && !mkdir(self::baseDir(), 0775, true) && !is_dir(self::baseDir())) {
            throw new HttpException('terminal.unavailable', 500);
        }
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException('terminal.unavailable', 500);
        }

        file_put_contents($dir . '/out.bin', '');
        file_put_contents($dir . '/in.bin', '');
        file_put_contents($dir . '/in.offset', '0');

        $meta = [
            'session_id' => $sessionId,
            'server_key' => $serverKey,
            'container' => $container,
            'exec_id' => $execId,
            'cols' => $cols,
            'rows' => $rows,
            'cwd' => $cwd,
            'created_at' => time(),
            'pid' => null,
            'closed' => false,
            'exit_code' => null,
            'app_name' => (string) ($server['APP_NAME'] ?? ''),
            'domain' => (string) ($server['DOMAIN_NAME'] ?? ''),
        ];
        self::writeMeta($dir, $meta);

        $worker = realpath(__DIR__ . '/../bin/terminal-worker.php');
        if ($worker === false || !is_file($worker)) {
            self::wipeDir($dir);
            throw new HttpException('terminal.unavailable', 500);
        }

        $log = $dir . '/worker.log';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($sessionId)
            . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $pidOut = [];
        $code = 0;
        exec('sh -c ' . escapeshellarg($cmd), $pidOut, $code);
        $pid = isset($pidOut[0]) ? (int) trim((string) $pidOut[0]) : 0;
        if ($code !== 0 || $pid <= 0) {
            self::wipeDir($dir);
            throw new HttpException('terminal.attach_failed', 502);
        }

        $meta['pid'] = $pid;
        self::writeMeta($dir, $meta);

        // Best-effort initial resize after attach starts.
        usleep(50000);
        try {
            DockerExec::resizeExec($execId, $cols, $rows);
        } catch (\Throwable) {
            // ignore
        }

        return [
            'session_id' => $sessionId,
            'container' => $container,
            'app_name' => $meta['app_name'],
            'domain' => $meta['domain'],
            'cwd' => $cwd,
        ];
    }

    /**
     * @return array{offset: int, data: string, closed: bool, exit_code: ?int}
     */
    public static function readOutput(string $id, int $since): array
    {
        $dir = self::requireDir($id);
        $meta = self::readMeta($dir);
        $outFile = $dir . '/out.bin';
        clearstatcache(true, $outFile);
        $size = is_file($outFile) ? (int) filesize($outFile) : 0;
        $since = max(0, $since);
        $data = '';
        if ($size > $since) {
            $fp = fopen($outFile, 'rb');
            if ($fp !== false) {
                fseek($fp, $since);
                $chunk = stream_get_contents($fp);
                fclose($fp);
                if (is_string($chunk)) {
                    $data = $chunk;
                }
            }
        }

        return [
            'offset' => $since + strlen($data),
            'data' => $data,
            'closed' => (bool) ($meta['closed'] ?? false),
            'exit_code' => isset($meta['exit_code']) ? (is_int($meta['exit_code']) ? $meta['exit_code'] : null) : null,
        ];
    }

    /**
     * Short wait after stdin so the PTY echo can return on the same request.
     *
     * @return array{offset: int, data: string, closed: bool, exit_code: ?int}
     */
    public static function readOutputWait(string $id, int $since, int $waitMs = 80): array
    {
        $waitMs = max(0, min(200, $waitMs));
        $deadline = microtime(true) + ($waitMs / 1000);
        do {
            $result = self::readOutput($id, $since);
            if ($result['data'] !== '' || $result['closed'] || $waitMs === 0) {
                return $result;
            }
            usleep(4000);
        } while (microtime(true) < $deadline);

        return self::readOutput($id, $since);
    }

    public static function streamSse(string $id, int $since): void
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

        $since = max(0, $since);
        $lastPing = time();
        $started = time();
        while (!connection_aborted()) {
            if ((time() - $started) > 1200) {
                echo "event: reconnect\ndata: {}\n\n";
                flush();

                return;
            }
            try {
                $result = self::readOutput($id, $since);
            } catch (HttpException) {
                echo "event: gone\ndata: {}\n\n";
                flush();

                return;
            }
            if ($result['data'] !== '') {
                $since = $result['offset'];
                $payload = json_encode([
                    'offset' => $result['offset'],
                    'data' => base64_encode($result['data']),
                    'closed' => $result['closed'],
                    'exit_code' => $result['exit_code'],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                echo 'id: ' . $since . "\n";
                echo 'data: ' . $payload . "\n\n";
                flush();
            }
            if (!empty($result['closed'])) {
                echo "event: closed\ndata: {}\n\n";
                flush();

                return;
            }
            if ((time() - $lastPing) >= 15) {
                echo ": ping\n\n";
                flush();
                $lastPing = time();
            }
            usleep(30000);
        }
    }

    public static function writeInput(string $id, string $raw): void
    {
        $dir = self::requireDir($id);
        $meta = self::readMeta($dir);
        if (!empty($meta['closed'])) {
            throw new HttpException('terminal.disconnected', 409);
        }
        if ($raw === '') {
            return;
        }
        $fp = fopen($dir . '/in.bin', 'ab');
        if ($fp === false) {
            throw new HttpException('terminal.unavailable', 500);
        }
        flock($fp, LOCK_EX);
        fwrite($fp, $raw);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public static function resize(string $id, int $cols, int $rows): void
    {
        $dir = self::requireDir($id);
        $meta = self::readMeta($dir);
        if (!empty($meta['closed'])) {
            return;
        }
        $cols = max(40, min(300, $cols));
        $rows = max(10, min(120, $rows));
        $meta['cols'] = $cols;
        $meta['rows'] = $rows;
        self::writeMeta($dir, $meta);
        $execId = (string) ($meta['exec_id'] ?? '');
        if ($execId !== '') {
            DockerExec::resizeExec($execId, $cols, $rows);
        }
    }

    public static function close(string $id): void
    {
        $dir = self::sessionDir($id);
        if (!is_dir($dir)) {
            return;
        }
        $meta = self::readMeta($dir);
        $pid = (int) ($meta['pid'] ?? 0);
        if ($pid > 0 && function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        }
        $meta['closed'] = true;
        self::writeMeta($dir, $meta);
        // Leave files briefly for final output poll; cleanupExpired will wipe.
        $meta['created_at'] = time() - self::TTL_SECONDS;
        self::writeMeta($dir, $meta);
    }

    public static function cleanupExpired(int $ttlSeconds = self::TTL_SECONDS): void
    {
        $base = self::baseDir();
        if (!is_dir($base)) {
            return;
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $meta = self::readMeta($dir);
            $created = (int) ($meta['created_at'] ?? 0);
            $closed = !empty($meta['closed']);
            $stale = $created > 0 && (time() - $created) > $ttlSeconds;
            if (!$closed && !$stale) {
                continue;
            }
            $pid = (int) ($meta['pid'] ?? 0);
            if ($pid > 0 && function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
            self::wipeDir($dir);
        }
    }

    private static function requireDir(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{16,64}$/', $id)) {
            throw new HttpException('error.not_found', 404);
        }
        $dir = self::sessionDir($id);
        if (!is_dir($dir) || !is_file($dir . '/meta.json')) {
            throw new HttpException('error.not_found', 404);
        }

        return $dir;
    }

    /** @return array<string, mixed> */
    private static function readMeta(string $dir): array
    {
        $path = $dir . '/meta.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $meta */
    private static function writeMeta(string $dir, array $meta): void
    {
        file_put_contents(
            $dir . '/meta.json',
            json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private static function wipeDir(string $dir): void
    {
        foreach (glob($dir . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
            $base = basename($file);
            if ($base === '.' || $base === '..') {
                continue;
            }
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
