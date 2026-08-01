<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Http\HttpException;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function validate(string $token): void
    {
        if ($token === '' || !hash_equals(self::token(), $token)) {
            throw new HttpException('error.invalid_csrf', 403);
        }
    }
}
