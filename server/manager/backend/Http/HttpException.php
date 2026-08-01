<?php

declare(strict_types=1);

namespace Manager\Http;

final class HttpException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorKey,
        private readonly int $status = 400,
        private readonly array $fields = [],
        private readonly array $parameters = [],
    ) {
        parent::__construct($errorKey, $status);
    }

    public function errorKey(): string
    {
        return $this->errorKey;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }
}
