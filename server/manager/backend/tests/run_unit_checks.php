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

use Manager\Models\InfraRuntime;
use Manager\Models\PhpExtensionCatalog;
use Manager\Models\PhpIniEditor;
use Manager\Models\PhpVersionId;

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

assert_true(PhpVersionId::supervisorService('php-8.2') === 'supervisor', 'default supervisor service');
assert_true(PhpVersionId::supervisorContainer('php-8.2') === 'supervisor_container', 'default supervisor container');
assert_true(PhpVersionId::supervisorService('php-8.1') === 'supervisor-8.1', '8.1 supervisor service');
assert_true(PhpVersionId::supervisorContainer('php-8.1') === 'supervisor81_container', '8.1 supervisor container');
assert_true(PhpVersionId::phpServiceFromSupervisor('supervisor') === 'php-8.2', 'supervisor → php-8.2');
assert_true(PhpVersionId::phpServiceFromSupervisor('supervisor-8.1') === 'php-8.1', 'supervisor-8.1 → php-8.1');
assert_true(PhpVersionId::isValidSupervisorService('supervisor'), 'valid supervisor');
assert_true(PhpVersionId::isValidSupervisorService('supervisor-8.2.33-alpine'), 'valid alpine supervisor');

$infraTargets = InfraRuntime::targets();
assert_true(isset($infraTargets['mysql'], $infraTargets['redis'], $infraTargets['rabbitmq']), 'infra targets');
assert_true($infraTargets['mysql']['profile'] === 'mysql', 'mysql profile');
assert_true($infraTargets['redis']['container'] === 'redis_container', 'redis container');
assert_true(str_contains($infraTargets['rabbitmq']['create_command'], '--profile rabbitmq'), 'rabbitmq create cmd');

$infraTmp = sys_get_temp_dir() . '/infra-runtime-' . bin2hex(random_bytes(4));
mkdir($infraTmp . '/requests', 0775, true);
mkdir($infraTmp . '/status', 0775, true);
$infra = new InfraRuntime($infraTmp);
$statuses = $infra->statuses();
assert_true(($statuses['mysql']['state'] ?? '') === 'not_created', 'mysql not_created default');
$requestId = $infra->request('mysql', 'create');
assert_true(strlen($requestId) === 32, 'infra request id');
assert_true($infra->hasBlockingRequests('mysql'), 'mysql has blocking create');
$busyStatuses = $infra->statuses();
assert_true(($busyStatuses['mysql']['state'] ?? '') === 'busy', 'mysql busy while queued');

echo "All checks passed\n";
