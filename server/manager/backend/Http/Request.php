<?php

declare(strict_types=1);

namespace Manager\Http;

final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        private readonly array $json,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($uriPath) ? $uriPath : '/';
        $path = '/' . trim($path, '/');
        if ($path === '/api') {
            $path = '/';
        } elseif (str_starts_with($path, '/api/')) {
            $path = substr($path, 4);
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $raw = file_get_contents('php://input');
        $json = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new HttpException('error.env_object', 400);
            }
            $json = $decoded;
        }

        return new self($method, $path === '' ? '/' : $path, $_GET, $headers, $json);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name, string $default = ''): string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? $default;
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function json(): array
    {
        return $this->json;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $default;
    }
}
