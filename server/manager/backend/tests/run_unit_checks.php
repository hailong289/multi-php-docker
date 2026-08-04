<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Manager\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Manager\Models\PhpExtensionCatalog;
use Manager\Models\PhpIniEditor;

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
}

$editor = new PhpIniEditor('/tmp');

$sample = "extension=sockets.so;\n;extension=imagick.so;\nmemory_limit=1024M\n";
assert_true($editor->extensionLineStatus($sample, 'sockets') === 'active', 'sockets active');
assert_true($editor->extensionLineStatus($sample, 'imagick') === 'commented', 'imagick commented');
assert_true($editor->extensionLineStatus($sample, 'redis') === 'absent', 'redis absent');

$enabled = $editor->toggleExtensionContent($sample, 'imagick', true);
assert_true($editor->extensionLineStatus($enabled, 'imagick') === 'active', 'enable imagick');
$disabled = $editor->toggleExtensionContent($enabled, 'imagick', false);
assert_true($editor->extensionLineStatus($disabled, 'imagick') === 'commented', 'disable imagick');
$withRedis = $editor->toggleExtensionContent($sample, 'redis', true);
assert_true(str_contains($withRedis, 'extension=redis.so'), 'append redis');

assert_true(PhpIniEditor::relativePath('php-8.2') === 'configs/php8/php.ini', 'path 8.2');
assert_true(PhpIniEditor::relativePath('php-8.1') === 'configs/php8.1/php.ini', 'path 8.1');
assert_true(PhpIniEditor::relativePath('php-8.0') === 'configs/php8.0/php.ini', 'path 8.0');
assert_true(PhpIniEditor::relativePath('php-7.4') === 'configs/php7.4/php.ini', 'path 7.4');

$modules = ['Core', 'redis', 'sockets'];
$ini = "extension=sockets.so;\n;extension=imagick.so;\n";
$entries = PhpExtensionCatalog::entries('php-8.2', $modules, $ini);
$byName = [];
foreach ($entries as $e) {
    $byName[$e['name']] = $e['status'];
}
assert_true($byName['redis'] === 'loaded', 'redis loaded');
assert_true($byName['imagick'] === 'disabled_in_ini', 'imagick disabled_in_ini');
assert_true($byName['mongodb'] === 'available_to_install', 'mongodb available');

$iniActive = "extension=imagick.so;\n";
$entriesActive = PhpExtensionCatalog::entries('php-8.2', ['Core'], $iniActive);
$byActive = [];
foreach ($entriesActive as $e) {
    $byActive[$e['name']] = $e['status'];
}
assert_true($byActive['imagick'] === 'enabled_in_ini', 'imagick enabled_in_ini');

$removed = $editor->removeExtensionContent("extension=foo.so;\n;extension=imagick.so;\nmemory_limit=1G\n", 'imagick');
assert_true($editor->extensionLineStatus($removed, 'imagick') === 'absent', 'remove imagick lines');
assert_true(str_contains($removed, 'extension=foo.so'), 'keep redis line');

assert_true(PhpExtensionCatalog::isCurated('redis'), 'curated redis');
assert_true(!PhpExtensionCatalog::isCurated('foobar'), 'not curated foobar');
assert_true(PhpExtensionCatalog::isValidName('gd'), 'valid gd');
assert_true(PhpExtensionCatalog::isValidName('pdo_mysql'), 'valid pdo_mysql');
assert_true(!PhpExtensionCatalog::isValidName('PDO'), 'invalid uppercase');
assert_true(!PhpExtensionCatalog::isValidName('1gd'), 'invalid leading digit');

$customEntries = PhpExtensionCatalog::entries('php-8.2', ['Core', 'yaml'], '', ['yaml']);
$byCustom = [];
foreach ($customEntries as $e) {
    $byCustom[$e['name']] = $e['status'];
}
assert_true(($byCustom['yaml'] ?? '') === 'loaded', 'custom yaml loaded');
assert_true(($byCustom['redis'] ?? '') === 'available_to_install', 'curated still listed');

echo "All checks passed\n";
