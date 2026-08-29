<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Http\HttpException;

/**
 * Docker Engine Exec helpers over the Manager unix socket (TTY attach for terminals).
 */
final class DockerExec
{
    public static function containerIdByName(string $name): ?string
    {
        $name = ltrim($name, '/');
        if ($name === '' || !DockerLiveState::available()) {
            return null;
        }

        $raw = self::httpRequest('GET', '/containers/json?all=true');
        if ($raw === null) {
            return null;
        }

        try {
            $list = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $names = $item['Names'] ?? [];
            if (!is_array($names)) {
                continue;
            }
            foreach ($names as $n) {
                if (!is_string($n) || $n === '') {
                    continue;
                }
                if (ltrim($n, '/') === $name) {
                    $id = (string) ($item['Id'] ?? '');

                    return $id !== '' ? $id : null;
                }
            }
        }

        return null;
    }

    /**
     * Start a container by name via the Engine API.
     * 0 = socket unreachable; otherwise the HTTP status (204/304/404/5xx).
     */
    public static function startNamedContainer(string $name): int
    {
        $name = ltrim($name, '/');
        if ($name === '' || !DockerLiveState::available()) {
            return 0;
        }

        $sock = DockerLiveState::socketPath();
        $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 2.0);
        if ($fp === false) {
            return 0;
        }

        stream_set_timeout($fp, 5);
        $path = '/containers/' . rawurlencode($name) . '/start';
        $request = "POST {$path} HTTP/1.0\r\nHost: localhost\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        if (fwrite($fp, $request) === false) {
            fclose($fp);

            return 0;
        }

        $response = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($response) || $response === '') {
            return 0;
        }
        if (!preg_match('/^HTTP\/\d\.\d\s+(\d{3})\b/', $response, $m)) {
            return 0;
        }

        return (int) $m[1];
    }

    /**
     * @param list<string> $cmd
     */
    public static function createExec(
        string $containerId,
        array $cmd,
        int $cols,
        int $rows,
        string $workingDir = '',
        bool $tty = true,
        bool $attachStdin = true,
        array $env = [],
    ): string {
        $body = [
            'AttachStdin' => $attachStdin,
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Tty' => $tty,
            'Cmd' => array_values($cmd),
        ];
        if ($tty) {
            $body['ConsoleSize'] = [$rows, $cols];
        }
        if ($workingDir !== '') {
            $body['WorkingDir'] = $workingDir;
        }
        if ($env !== []) {
            $body['Env'] = array_values($env);
        }
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $raw = self::httpRequest(
            'POST',
            '/containers/' . rawurlencode($containerId) . '/exec',
            $payload,
            'application/json',
            [200, 201],
        );
        if ($raw === null) {
            throw new HttpException('terminal.attach_failed', 502);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException('terminal.attach_failed', 502);
        }
        $id = is_array($data) ? (string) ($data['Id'] ?? '') : '';
        if ($id === '') {
            throw new HttpException('terminal.attach_failed', 502);
        }

        return $id;
    }

    public static function resizeExec(string $execId, int $cols, int $rows): void
    {
        $cols = max(40, min(300, $cols));
        $rows = max(10, min(120, $rows));
        $path = '/exec/' . rawurlencode($execId) . '/resize?h=' . $rows . '&w=' . $cols;
        self::httpRequest('POST', $path, '', null, [200, 201, 204, 404]);
    }

    /**
     * Open a hijacked attach stream for an exec (TTY → raw bytes; otherwise multiplexed).
     *
     * @return array{0: resource, 1: string} socket and any bytes already received after headers
     */
    public static function openAttach(string $execId, bool $tty = true): array
    {
        $sock = DockerLiveState::socketPath();
        $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 3.0);
        if ($fp === false) {
            throw new HttpException('terminal.docker_unavailable', 503);
        }

        stream_set_timeout($fp, 5);
        $body = json_encode(['Detach' => false, 'Tty' => $tty], JSON_THROW_ON_ERROR);
        $path = '/exec/' . rawurlencode($execId) . '/start';
        $request = "POST {$path} HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: Upgrade\r\n"
            . "Upgrade: tcp\r\n"
            . "\r\n"
            . $body;

        if (fwrite($fp, $request) === false) {
            fclose($fp);
            throw new HttpException('terminal.attach_failed', 502);
        }

        $buffer = '';
        $deadline = microtime(true) + 5.0;
        while (!str_contains($buffer, "\r\n\r\n")) {
            if (microtime(true) > $deadline) {
                fclose($fp);
                throw new HttpException('terminal.attach_failed', 502);
            }
            $chunk = fread($fp, 4096);
            if ($chunk === false || $chunk === '') {
                usleep(10000);
                continue;
            }
            $buffer .= $chunk;
            if (strlen($buffer) > 65536) {
                fclose($fp);
                throw new HttpException('terminal.attach_failed', 502);
            }
        }

        $parts = explode("\r\n\r\n", $buffer, 2);
        $headerBlock = $parts[0];
        $preface = $parts[1] ?? '';

        if (!preg_match('/^HTTP\/\d\.\d\s+(101|200)\b/', $headerBlock)) {
            fclose($fp);
            throw new HttpException('terminal.attach_failed', 502);
        }

        stream_set_blocking($fp, false);
        stream_set_timeout($fp, 0, 200000);

        return [$fp, $preface];
    }

    public static function inspectExec(string $execId): ?array
    {
        $raw = self::httpRequest('GET', '/exec/' . rawurlencode($execId) . '/json', null, null, [200]);
        if ($raw === null) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * One-shot non-TTY exec. Writes $stdin then half-closes, collects multiplexed stdout/stderr.
     *
     * @param list<string> $cmd
     * @return array{stdout: string, stderr: string, exit_code: int, timed_out: bool, truncated: bool}
     */
    public static function run(
        string $containerId,
        array $cmd,
        string $stdin = '',
        int $timeoutSeconds = 12,
        int $maxOutputBytes = 262144,
        string $workingDir = '',
        array $env = [],
    ): array {
        if (!DockerLiveState::available()) {
            throw new HttpException('php_controller.run_unavailable', 503);
        }

        $timeoutSeconds = max(1, min(30, $timeoutSeconds));
        $maxOutputBytes = max(1024, min(1048576, $maxOutputBytes));

        try {
            $execId = self::createExec(
                $containerId,
                $cmd,
                80,
                24,
                $workingDir,
                false,
                $stdin !== '',
                $env,
            );
            [$fp, $preface] = self::openAttach($execId, false);
        } catch (HttpException $e) {
            if (in_array($e->errorKey(), ['php_controller.run_unavailable', 'terminal.docker_unavailable'], true)) {
                throw new HttpException('php_controller.run_unavailable', 503);
            }
            throw new HttpException('php_controller.run_failed', 502);
        }

        if ($stdin !== '' && fwrite($fp, $stdin) === false) {
            fclose($fp);
            throw new HttpException('php_controller.run_failed', 502);
        }
        if ($stdin !== '') {
            @stream_socket_shutdown($fp, STREAM_SHUT_WR);
        }

        [$stdout, $stderr, $timedOut, $truncated] = self::collectMultiplexed(
            $fp,
            $preface,
            $timeoutSeconds,
            $maxOutputBytes,
        );

        $info = self::inspectExec($execId);
        $running = is_array($info) && !empty($info['Running']);
        $exit = is_array($info) ? (int) ($info['ExitCode'] ?? -1) : -1;
        if ($running) {
            $timedOut = true;
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $timedOut && $running ? -1 : $exit,
            'timed_out' => $timedOut,
            'truncated' => $truncated,
        ];
    }

    /**
     * Split Docker multiplexed attach frames (Tty=false).
     *
     * @return array{0: string, 1: string, 2: string} stdout, stderr, remainder
     */
    public static function splitMultiplexed(string $data, int $maxOutputBytes = 1048576): array
    {
        $stdout = '';
        $stderr = '';
        $offset = 0;
        $len = strlen($data);
        $cap = $maxOutputBytes;
        while ($offset + 8 <= $len) {
            $header = substr($data, $offset, 8);
            $type = ord($header[0]);
            $size = unpack('N', substr($header, 4, 4));
            $payloadSize = is_array($size) ? (int) ($size[1] ?? 0) : 0;
            if ($payloadSize < 0 || $payloadSize > 16 * 1024 * 1024) {
                break;
            }
            if ($offset + 8 + $payloadSize > $len) {
                break;
            }
            $payload = substr($data, $offset + 8, $payloadSize);
            $offset += 8 + $payloadSize;
            if ($type === 1) {
                $stdout .= $payload;
                if (strlen($stdout) > $cap) {
                    $stdout = substr($stdout, 0, $cap);
                }
            } elseif ($type === 2) {
                $stderr .= $payload;
                if (strlen($stderr) > $cap) {
                    $stderr = substr($stderr, 0, $cap);
                }
            }
        }

        return [$stdout, $stderr, substr($data, $offset)];
    }

    /**
     * Decode docker logs API bytes (multiplexed stdout/stderr, or raw TTY).
     */
    public static function decodeLogStream(string $data, int $maxBytes = 262144): string
    {
        if ($data === '') {
            return '';
        }
        $len = strlen($data);
        $looksMultiplexed = $len >= 8
            && in_array(ord($data[0]), [1, 2], true)
            && $data[1] === "\0"
            && $data[2] === "\0"
            && $data[3] === "\0";
        if (!$looksMultiplexed) {
            $out = $data;
            if (strlen($out) > $maxBytes) {
                $out = substr($out, -$maxBytes);
            }
            if (!preg_match('//u', $out)) {
                $out = (string) iconv('UTF-8', 'UTF-8//IGNORE', $out);
            }

            return $out;
        }

        $out = '';
        $offset = 0;
        while ($offset + 8 <= $len) {
            $header = substr($data, $offset, 8);
            $type = ord($header[0]);
            $size = unpack('N', substr($header, 4, 4));
            $payloadSize = is_array($size) ? (int) ($size[1] ?? 0) : 0;
            if ($payloadSize < 0 || $payloadSize > 16 * 1024 * 1024 || $offset + 8 + $payloadSize > $len) {
                break;
            }
            if ($type === 1 || $type === 2) {
                $out .= substr($data, $offset + 8, $payloadSize);
                if (strlen($out) > $maxBytes) {
                    $out = substr($out, -$maxBytes);
                }
            }
            $offset += 8 + $payloadSize;
        }
        if (!preg_match('//u', $out)) {
            $out = (string) iconv('UTF-8', 'UTF-8//IGNORE', $out);
        }

        return $out;
    }

    /**
     * Last stdout/stderr lines for a container name (`docker logs`).
     */
    public static function containerLogs(string $containerName, int $tail = 300): ?string
    {
        $id = self::containerIdByName($containerName);
        if ($id === null) {
            return null;
        }
        $tail = max(1, min(2000, $tail));
        $path = '/containers/' . rawurlencode($id)
            . '/logs?stdout=1&stderr=1&timestamps=1&tail=' . $tail;
        $raw = self::httpRequest('GET', $path, null, null, [200]);
        if ($raw === null) {
            return null;
        }

        return self::decodeLogStream($raw);
    }

    /**
     * @param resource $fp
     * @return array{0: string, 1: string, 2: bool, 3: bool} stdout, stderr, timed_out, truncated
     */
    private static function collectMultiplexed(
        $fp,
        string $preface,
        int $timeoutSeconds,
        int $maxOutputBytes,
    ): array {
        stream_set_blocking($fp, false);
        $buffer = $preface;
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $truncated = false;
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if (strlen($stdout) + strlen($stderr) >= $maxOutputBytes) {
                $truncated = true;
                break;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }
            $read = [$fp];
            $write = null;
            $except = null;
            $sec = (int) $remaining;
            $usec = (int) round(($remaining - $sec) * 1000000);
            if ($usec >= 1000000) {
                $sec++;
                $usec -= 1000000;
            }
            $n = @stream_select($read, $write, $except, $sec, max(0, $usec));
            if ($n === false) {
                break;
            }
            if ($n === 0) {
                $timedOut = true;
                break;
            }
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($fp)) {
                    break;
                }
                continue;
            }
            $buffer .= $chunk;
            [$out, $err, $rest] = self::splitMultiplexed($buffer, $maxOutputBytes);
            $stdout .= $out;
            $stderr .= $err;
            $buffer = $rest;
            if (strlen($stdout) > $maxOutputBytes) {
                $stdout = substr($stdout, 0, $maxOutputBytes);
                $truncated = true;
                break;
            }
            if (strlen($stderr) > $maxOutputBytes) {
                $stderr = substr($stderr, 0, $maxOutputBytes);
                $truncated = true;
                break;
            }
        }

        if ($buffer !== '' && !$truncated && $stdout === '' && $stderr === '') {
            $stdout = $buffer;
        }

        fclose($fp);

        return [$stdout, $stderr, $timedOut, $truncated];
    }

    /**
     * @param list<int>|null $okStatuses
     */
    private static function httpRequest(
        string $method,
        string $path,
        ?string $body = null,
        ?string $contentType = null,
        ?array $okStatuses = null,
    ): ?string {
        if (!DockerLiveState::available()) {
            return null;
        }

        $sock = DockerLiveState::socketPath();
        $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 2.0);
        if ($fp === false) {
            return null;
        }

        stream_set_timeout($fp, 5);
        $okStatuses ??= [200];
        $headers = [
            "{$method} {$path} HTTP/1.0",
            'Host: localhost',
            'Connection: close',
        ];
        if ($body !== null) {
            if ($contentType !== null) {
                $headers[] = 'Content-Type: ' . $contentType;
            }
            $headers[] = 'Content-Length: ' . (string) strlen($body);
        }
        $request = implode("\r\n", $headers) . "\r\n\r\n" . ($body ?? '');
        if (fwrite($fp, $request) === false) {
            fclose($fp);

            return null;
        }

        $response = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($response) || $response === '') {
            return null;
        }

        $parts = explode("\r\n\r\n", $response, 2);
        if (count($parts) < 2) {
            return null;
        }
        if (!preg_match('/^HTTP\/\d\.\d\s+(\d{3})\b/', $parts[0], $m)) {
            return null;
        }
        $code = (int) $m[1];
        if (!in_array($code, $okStatuses, true)) {
            return null;
        }

        $body = $parts[1];
        if (preg_match('/^Transfer-Encoding:\s*chunked\b/mi', $parts[0])) {
            $body = self::decodeChunked($body);
        }

        return $body;
    }

    private static function decodeChunked(string $body): string
    {
        $out = '';
        $offset = 0;
        $len = strlen($body);
        while ($offset < $len) {
            $nl = strpos($body, "\r\n", $offset);
            if ($nl === false) {
                break;
            }
            $sizeLine = substr($body, $offset, $nl - $offset);
            if (str_contains($sizeLine, ';')) {
                $sizeLine = explode(';', $sizeLine, 2)[0];
            }
            $size = hexdec(trim($sizeLine));
            $offset = $nl + 2;
            if ($size === 0) {
                break;
            }
            if ($offset + $size > $len) {
                break;
            }
            $out .= substr($body, $offset, $size);
            $offset += $size;
            if (substr($body, $offset, 2) === "\r\n") {
                $offset += 2;
            }
        }

        return $out;
    }
}
