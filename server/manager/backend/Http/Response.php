<?php

declare(strict_types=1);

namespace Manager\Http;

final class Response
{
    public function __construct(
        private readonly int $status,
        private readonly array $data,
    ) {
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self($status, $data);
    }

    public static function error(
        string $key,
        int $status = 400,
        array $fields = [],
        array $parameters = [],
    ): self {
        $error = ['key' => $key];
        if ($parameters !== []) {
            $error['parameters'] = $parameters;
        }
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return new self($status, ['error' => $error]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
