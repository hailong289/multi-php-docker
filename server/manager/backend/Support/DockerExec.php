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
     * @param list<string> $cmd
     */
    public static function createExec(
        string $containerId,
        array $cmd,
        int $cols,
        int $rows,
        string $workingDir = '',
    ): string {
        $body = [
            'AttachStdin' => true,
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Tty' => true,
            'Cmd' => array_values($cmd),
            'ConsoleSize' => [$rows, $cols],
        ];
        if ($workingDir !== '') {
            $body['WorkingDir'] = $workingDir;
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
     * Open a hijacked attach stream for an exec (TTY=true → raw bytes).
     *
     * @return array{0: resource, 1: string} socket and any bytes already received after headers
     */
    public static function openAttach(string $execId): array
    {
        $sock = DockerLiveState::socketPath();
        $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 3.0);
        if ($fp === false) {
            throw new HttpException('terminal.docker_unavailable', 503);
        }

        stream_set_timeout($fp, 5);
        $body = '{"Detach":false,"Tty":true}';
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
