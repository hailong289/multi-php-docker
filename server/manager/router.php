<?php

declare(strict_types=1);

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$originalPath = $path;

$underManageBase = $path === '/server-manage' || str_starts_with($path, '/server-manage/');

// Local UX: http://127.0.0.1:8080/ → /server-manage/
if (!$underManageBase && $path === '/') {
    header('Location: /server-manage/', true, 302);
    return true;
}

// Support being mounted at /server-manage (nginx or direct :8080).
if ($underManageBase) {
    $path = substr($path, strlen('/server-manage')) ?: '/';
    if ($path === '') {
        $path = '/';
    }
}

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/backend/bootstrap.php';
    return true;
}

$publicDir = realpath(__DIR__ . '/public') ?: (__DIR__ . '/public');
$file = $publicDir . ($path === '/' ? '/index.html' : $path);

if ($path !== '/' && is_file($file)) {
    // PHP built-in server's "return false" resolves the *original* REQUEST_URI
    // against -t. That breaks /server-manage/assets/* (no such dir under public/).
    serve_static_file($file);
    return true;
}

$index = $publicDir . '/index.html';
if (is_file($index)) {
    header('Content-Type: ' . 'text/html; charset=utf-8');
    readfile($index);
    return true;
}

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "Manager UI is not built yet. Run: cd server/manager/frontend && npm install && npm run build\n";
return true;

/**
 * @param non-empty-string $file
 */
function serve_static_file(string $file): void
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'ico' => 'image/x-icon',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'webp' => 'image/webp',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    $size = filesize($file);
    if ($size !== false) {
        header('Content-Length: ' . (string) $size);
    }
    readfile($file);
}
