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

use Manager\Http\HttpException;
use Manager\Models\EnvConfig;
use Manager\Models\HostsSync;
use Manager\Models\InfraCompose;
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

assert_true(PhpIniEditor::relativePath('php-8.5') === 'configs/php8.5/php.ini', 'path 8.5');
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

assert_true(PhpVersionId::defaultService() === 'php-8.5', 'default service');
assert_true(PhpVersionId::supervisorService('php-8.5') === 'supervisor-8.5', 'default supervisor service');
assert_true(PhpVersionId::supervisorContainer('php-8.5') === 'supervisor85_container', 'default supervisor container');
assert_true(PhpVersionId::supervisorConfDir('php-8.5') === 'configs/supervisor.d/php8.5', 'default supervisor conf dir');
assert_true(PhpVersionId::supervisorService('php-8.2') === 'supervisor-8.2', '8.2 supervisor service');
assert_true(PhpVersionId::supervisorConfDir('php-8.2') === 'configs/supervisor.d/php8.2', '8.2 supervisor conf dir');
assert_true(PhpVersionId::supervisorConfDir('php-8.1') === 'configs/supervisor.d/php8.1', '8.1 supervisor conf dir');
assert_true(PhpVersionId::supervisorService('php-8.1') === 'supervisor-8.1', '8.1 supervisor service');
assert_true(PhpVersionId::supervisorContainer('php-8.1') === 'supervisor81_container', '8.1 supervisor container');
assert_true(PhpVersionId::phpServiceFromSupervisor('supervisor') === 'php-8.5', 'legacy supervisor → php-8.5');
assert_true(PhpVersionId::phpServiceFromSupervisor('supervisor-8.5') === 'php-8.5', 'supervisor-8.5 → php-8.5');
assert_true(PhpVersionId::phpServiceFromSupervisor('supervisor-8.1') === 'php-8.1', 'supervisor-8.1 → php-8.1');
assert_true(PhpVersionId::isValidSupervisorService('supervisor'), 'valid supervisor');
assert_true(PhpVersionId::isValidSupervisorService('supervisor-8.2.33-alpine'), 'valid alpine supervisor');

$infraTargets = InfraRuntime::targets();
assert_true(isset($infraTargets['mysql'], $infraTargets['redis'], $infraTargets['rabbitmq']), 'infra targets');
assert_true($infraTargets['mysql']['profile'] === 'mysql', 'mysql profile');
assert_true($infraTargets['redis']['container'] === 'redis_container', 'redis container');
assert_true($infraTargets['mysql']['compose_file'] === 'compose/mysql.yml', 'mysql compose file');
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

$composeProj = sys_get_temp_dir() . '/infra-compose-' . bin2hex(random_bytes(4));
mkdir($composeProj . '/compose', 0775, true);
file_put_contents($composeProj . '/compose/mysql.yml', "services:\n  mysql:\n    image: mysql:8\n");
$compose = new InfraCompose($composeProj);
$read = $compose->read('mysql');
assert_true(($read['relative_path'] ?? '') === 'compose/mysql.yml', 'compose relative path');
assert_true(str_contains($read['content'] ?? '', 'mysql:8'), 'compose read content');
$written = $compose->write('mysql', "services:\n  mysql:\n    image: mysql:8.4\n");
assert_true(($written['size'] ?? 0) > 10, 'compose write size');
assert_true(str_contains((string) file_get_contents($composeProj . '/compose/mysql.yml'), 'mysql:8.4'), 'compose file updated');
$created = $compose->writeFile('custom.yml', "services:\n  custom:\n    image: alpine\n", true);
assert_true(($created['name'] ?? '') === 'custom.yml', 'compose create custom');
$list = $compose->list();
assert_true(count($list) >= 2, 'compose list has files');
assert_true($compose->isCoreFile('mysql.yml'), 'mysql is core');
assert_true($compose->isProtectedFile('php-8.1.yml'), 'php compose protected');
try {
    $compose->deleteFile('mysql.yml');
    assert_true(false, 'core compose protected');
} catch (\Manager\Http\HttpException $e) {
    assert_true($e->errorKey() === 'services.compose_core_protected', 'core compose protected');
}
$compose->deleteFile('custom.yml');
assert_true(!is_file($composeProj . '/compose/custom.yml'), 'custom compose deleted');
$pullId = (new InfraRuntime($infraTmp))->request('redis', 'pull-recreate');
assert_true(strlen($pullId) === 32, 'pull-recreate request id');
assert_true((new InfraRuntime($infraTmp))->hasBlockingRequests('redis'), 'redis blocking pull-recreate');

use Manager\Support\RemoteAuth;

putenv('MANAGER_REMOTE=0');
assert_true(RemoteAuth::isRemote() === false, 'remote off by default');

putenv('MANAGER_REMOTE=1');
putenv('MANAGER_USERNAME=');
putenv('MANAGER_PASSWORD=');
assert_true(RemoteAuth::isRemote() === true, 'remote on');
assert_true(RemoteAuth::isLocked() === true, 'locked without credentials');

putenv('MANAGER_USERNAME=admin');
putenv('MANAGER_PASSWORD=secret');
assert_true(RemoteAuth::credentialsConfigured() === true, 'credentials ok');
assert_true(RemoteAuth::isLocked() === false, 'not locked with credentials');

putenv('MANAGER_REMOTE=0');
putenv('MANAGER_USERNAME=');
putenv('MANAGER_PASSWORD=');

use Manager\Models\TerminalSession;
use Manager\Support\Config;

$allowed = TerminalSession::allowedPhpContainers(Config::projectPath());
assert_true(isset($allowed['php8.5_container']), 'default php container allowlisted');
assert_true(!isset($allowed['nginx_container']), 'nginx not allowlisted');

assert_true(
    TerminalSession::projectDirFromServerPath('/var/www/source_php8.5/spa-fnb-retail/public')
        === '/var/www/source_php8.5/spa-fnb-retail',
    'terminal cwd strips /public',
);
assert_true(
    TerminalSession::projectDirFromServerPath('/var/www/source_php8.5/posapp-yii-backend/web')
        === '/var/www/source_php8.5/posapp-yii-backend',
    'terminal cwd strips /web',
);
assert_true(
    TerminalSession::projectDirFromServerPath('/var/www/source_php7.4/app/webroot')
        === '/var/www/source_php7.4/app',
    'terminal cwd strips /webroot',
);
assert_true(
    TerminalSession::projectDirFromServerPath('/tmp/evil') === '',
    'terminal cwd rejects non-source paths',
);

use Manager\Models\DockerHubPhpTags;

assert_true(DockerHubPhpTags::isVersionStem('5.6'), '5.6 is version stem');
assert_true(DockerHubPhpTags::isVersionStem('7.4.33'), '7.4.33 is version stem');
assert_true(!DockerHubPhpTags::isVersionStem('fpm'), 'fpm is not version stem');
assert_true(DockerHubPhpTags::tagMatchesStem('5.6-fpm', '5.6'), '5.6-fpm matches 5.6');
assert_true(DockerHubPhpTags::tagMatchesStem('5.6.40-fpm-alpine', '5.6'), '5.6.40 matches 5.6');
assert_true(!DockerHubPhpTags::tagMatchesStem('8.5.6-fpm', '5.6'), '8.5.6 must not match 5.6');
assert_true(DockerHubPhpTags::isFpmTag('5.6-fpm-jessie'), 'jessie fpm tagged');
assert_true(!DockerHubPhpTags::isFpmTag('5.6-cli'), 'cli is not fpm');

use Manager\Models\PhpVersionInstaller;
use Manager\Support\AtomicFile;

$atomicDir = sys_get_temp_dir() . '/atomic-' . bin2hex(random_bytes(4));
mkdir($atomicDir, 0775, true);
$atomicPath = $atomicDir . '/req.json';
assert_true(AtomicFile::write($atomicPath, "{\"ok\":true}\n") === true, 'AtomicFile write');
assert_true(is_file($atomicPath) && str_contains((string) file_get_contents($atomicPath), '"ok":true'), 'AtomicFile content');

$installProj = sys_get_temp_dir() . '/php-install-' . bin2hex(random_bytes(4));
mkdir($installProj . '/compose', 0775, true);
$crlfCompose = "include:\r\n"
    . "  - path: compose/php-8.5.yml\r\n"
    . "    project_directory: .\r\n"
    . "  - path: compose/redis.yml\r\n"
    . "    project_directory: .\r\n"
    . "\r\n"
    . "services: {}\r\n";
file_put_contents($installProj . '/docker-compose.yml', $crlfCompose);
$installer = new PhpVersionInstaller($installProj);
$ensureInclude = new ReflectionMethod(PhpVersionInstaller::class, 'ensureComposeInclude');
$ensureInclude->invoke($installer, 'php-8.6');
$updatedCompose = (string) file_get_contents($installProj . '/docker-compose.yml');
assert_true(str_contains($updatedCompose, 'path: compose/php-8.6.yml'), 'CRLF compose include php-8.6');
assert_true(!str_contains($updatedCompose, "\r"), 'compose rewritten as LF');
$hasInclude = new ReflectionMethod(PhpVersionInstaller::class, 'hasComposeInclude');
assert_true($hasInclude->invoke($installer, 'php-8.6') === true, 'hasComposeInclude finds php-8.6');
assert_true($hasInclude->invoke($installer, 'php-9.9') === false, 'hasComposeInclude misses unknown');
file_put_contents($installProj . '/compose/php-8.6.yml', "services:\n  php-8.6: {}\n");
$installer->repairComposeInclude('php-8.6');
assert_true($hasInclude->invoke($installer, 'php-8.6') === true, 'repairComposeInclude idempotent');

$envMissingDir = sys_get_temp_dir() . '/mgr-env-missing-' . bin2hex(random_bytes(4));
mkdir($envMissingDir, 0775, true);
$missingPath = $envMissingDir . '/env.json';
$envMissing = new EnvConfig($missingPath);
assert_true($envMissing->allOrEmpty() === [], 'allOrEmpty missing file');
try {
    $envMissing->all();
    assert_true(false, 'all() should throw when missing');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'error.env_missing', 'all() throws env_missing');
}

$validPath = $envMissingDir . '/valid-env.json';
file_put_contents($validPath, "{\"SERVER_NAME1\":{\"DOMAIN_NAME\":\"a.test\",\"APP_NAME\":\"a\"}}\n");
$envValid = new EnvConfig($validPath);
$loaded = $envValid->allOrEmpty();
assert_true(isset($loaded['SERVER_NAME1']), 'allOrEmpty loads existing env');

$hostsTmp = sys_get_temp_dir() . '/hosts-sync-' . bin2hex(random_bytes(4));
mkdir($hostsTmp, 0775, true);
$hosts = new HostsSync($hostsTmp);
$hosts->saveExtras(['solo.test']);
$desired = $hosts->desiredDomains([]);
assert_true($desired === ['solo.test'], 'desiredDomains extras only');
$listed = $hosts->listedDomains([], null);
assert_true(count($listed) === 1 && ($listed[0]['source'] ?? '') === 'hosts', 'listedDomains hosts-only');

echo "All checks passed\n";
