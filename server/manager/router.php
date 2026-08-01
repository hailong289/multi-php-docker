<?php

declare(strict_types=1);

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/backend/bootstrap.php';
    return true;
}

$publicDir = realpath(__DIR__ . '/public') ?: (__DIR__ . '/public');
$file = $publicDir . ($path === '/' ? '/index.html' : $path);

if ($path !== '/' && is_file($file)) {
    return false;
}

$index = $publicDir . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
    return true;
}

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "Manager UI is not built yet. Run: cd server/manager/frontend && npm install && npm run build\n";
return true;
