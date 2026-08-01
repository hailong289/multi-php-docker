<?php

declare(strict_types=1);

session_start();

spl_autoload_register(static function (string $class): void {
    $prefix = 'Manager\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Manager\Application::boot()->run();
