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
use Manager\Models\DockerConnection;
use Manager\Models\EnvConfig;
use Manager\Models\HostsSync;
use Manager\Models\ComposeFileParser;
use Manager\Models\ComposeFileRuntime;
use Manager\Models\ComposeInclude;
use Manager\Models\InfraCompose;
use Manager\Models\InfraRuntime;
use Manager\Models\NginxManagement;
use Manager\Models\PhpControllerDaemon;
use Manager\Models\PhpExtensionCatalog;
use Manager\Models\PhpIniEditor;
use Manager\Models\PhpRuntime;
use Manager\Models\PhpVersionId;
use Manager\Models\SupervisorRuntime;

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

$entriesOpcache = PhpExtensionCatalog::entries('php-8.5', ['Core', 'Zend OPcache'], '');
$byOpcache = [];
foreach ($entriesOpcache as $e) {
    $byOpcache[$e['name']] = $e['status'];
}
assert_true(($byOpcache['opcache'] ?? '') === 'loaded', 'Zend OPcache maps to loaded opcache');

$removed = $editor->removeExtensionContent("extension=foo.so;\n;extension=imagick.so;\nmemory_limit=1G\n", 'imagick');
assert_true($editor->extensionLineStatus($removed, 'imagick') === 'absent', 'remove imagick lines');
assert_true(str_contains($removed, 'extension=foo.so'), 'keep redis line');

assert_true(PhpExtensionCatalog::isCurated('redis'), 'curated redis');
assert_true(PhpExtensionCatalog::isCurated('pdo_pgsql'), 'curated pdo_pgsql');
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

use Manager\Support\ControllerRequests;

$staleDir = sys_get_temp_dir() . '/mgr-req-stale-' . bin2hex(random_bytes(4));
mkdir($staleDir, 0775, true);
$staleFile = $staleDir . '/abc__nginx__start.json';
file_put_contents($staleFile, "{}\n");
touch($staleFile, time() - 7200);
assert_true(ControllerRequests::hasBlocking($staleDir, 'nginx', ['start']) === false, 'stale nginx request purged');
assert_true(!is_file($staleFile), 'stale request file removed');
$freshFile = $staleDir . '/def__nginx__start.json';
file_put_contents($freshFile, "{}\n");
assert_true(ControllerRequests::hasBlocking($staleDir, 'nginx', ['start']) === true, 'fresh nginx request blocks');
@unlink($freshFile);
$extReq = $staleDir . '/aaa__php-8.5__install-ext-opcache.json';
file_put_contents($extReq, "{}\n");
assert_true(
    ControllerRequests::hasBlocking($staleDir, 'php-8.5', ['install-ext', 'uninstall-ext']) === true,
    'install-ext-opcache request blocks',
);
$unextReq = $staleDir . '/bbb__php-8.5__uninstall-ext-redis.json';
file_put_contents($unextReq, "{}\n");
assert_true(
    ControllerRequests::hasBlocking($staleDir, 'php-8.5', ['install-ext', 'uninstall-ext']) === true,
    'uninstall-ext-redis request blocks',
);
@unlink($extReq);
@unlink($unextReq);
@rmdir($staleDir);

$infraTargets = InfraRuntime::targets();
assert_true(isset($infraTargets['mysql'], $infraTargets['postgres'], $infraTargets['redis'], $infraTargets['rabbitmq'], $infraTargets['kafka'], $infraTargets['mailpit'], $infraTargets['minio']), 'infra targets');
assert_true($infraTargets['mysql']['profile'] === 'mysql', 'mysql profile');
assert_true($infraTargets['postgres']['profile'] === 'postgres', 'postgres profile');
assert_true($infraTargets['postgres']['container'] === 'postgres_container', 'postgres container');
assert_true($infraTargets['postgres']['compose_file'] === 'compose/postgres.yml', 'postgres compose file');
assert_true($infraTargets['redis']['container'] === 'redis_container', 'redis container');
assert_true($infraTargets['mysql']['compose_file'] === 'compose/mysql.yml', 'mysql compose file');
assert_true(str_contains($infraTargets['rabbitmq']['create_command'], '--profile rabbitmq'), 'rabbitmq create cmd');
assert_true($infraTargets['kafka']['profile'] === 'kafka', 'kafka profile');
assert_true($infraTargets['kafka']['container'] === 'kafka_container', 'kafka container');
assert_true($infraTargets['kafka']['compose_file'] === 'compose/kafka.yml', 'kafka compose file');
assert_true($infraTargets['mailpit']['profile'] === 'mailpit', 'mailpit profile');
assert_true($infraTargets['mailpit']['container'] === 'mailpit_container', 'mailpit container');
assert_true($infraTargets['mailpit']['compose_file'] === 'compose/mailpit.yml', 'mailpit compose file');
assert_true($infraTargets['minio']['profile'] === 'minio', 'minio profile');
assert_true($infraTargets['minio']['container'] === 'minio_container', 'minio container');
assert_true($infraTargets['minio']['compose_file'] === 'compose/minio.yml', 'minio compose file');

$runningDaemon = new PhpControllerDaemon(static fn (): string => 'running');
$stoppedDaemon = new PhpControllerDaemon(static fn (): string => 'stopped');
$missingDaemon = new PhpControllerDaemon(static fn (): string => 'not_created');
$nullDaemon = new PhpControllerDaemon(static fn (): ?string => null);

$infraTmp = sys_get_temp_dir() . '/infra-runtime-' . bin2hex(random_bytes(4));
mkdir($infraTmp . '/requests', 0775, true);
mkdir($infraTmp . '/status', 0775, true);
$infra = new InfraRuntime($infraTmp, $runningDaemon);
$statuses = $infra->statuses();
$mysqlState = $statuses['mysql']['state'] ?? '';
assert_true(
    in_array($mysqlState, ['not_created', 'running', 'stopped'], true),
    'mysql default state from files or live docker',
);
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
file_put_contents($composeProj . '/compose/php-8.5.yml', "services:\n  php-8.5: {}\n");
$list = $compose->list();
assert_true(count($list) >= 2, 'compose list has files');
$listedNames = array_column($list, 'name');
assert_true(!in_array('php-8.5.yml', $listedNames, true), 'php compose hidden from services list');
assert_true(in_array('mysql.yml', $listedNames, true), 'mysql compose listed');
$phpList = $compose->list('php');
$phpListedNames = array_column($phpList, 'name');
assert_true(in_array('php-8.5.yml', $phpListedNames, true), 'php compose listed with scope=php');
assert_true(!in_array('mysql.yml', $phpListedNames, true), 'mysql hidden from php scope list');
assert_true($compose->isCoreFile('mysql.yml'), 'mysql is core');
assert_true($compose->isCoreFile('postgres.yml'), 'postgres is core');
assert_true($compose->isCoreFile('kafka.yml'), 'kafka is core');
assert_true($compose->isCoreFile('mailpit.yml'), 'mailpit is core');
assert_true($compose->isCoreFile('minio.yml'), 'minio is core');
assert_true($compose->isProtectedFile('php-8.1.yml'), 'php compose protected');
try {
    $compose->deleteFile('mysql.yml');
    assert_true(false, 'core compose protected');
} catch (\Manager\Http\HttpException $e) {
    assert_true($e->errorKey() === 'services.compose_core_protected', 'core compose protected');
}
$compose->deleteFile('custom.yml');
assert_true(!is_file($composeProj . '/compose/custom.yml'), 'custom compose deleted');
$mysqlCtx = $compose->actionContextForFile('mysql.yml');
assert_true(($mysqlCtx['runtime'] ?? '') === 'infra', 'mysql compose runtime');
assert_true(($mysqlCtx['service'] ?? '') === 'mysql', 'mysql compose service');
assert_true(($mysqlCtx['pull_recreate'] ?? false) === true, 'mysql compose pull recreate');
$postgresInfraCtx = $compose->actionContextForFile('postgres.yml');
assert_true(($postgresInfraCtx['runtime'] ?? '') === 'infra', 'postgres compose runtime');
assert_true(($postgresInfraCtx['service'] ?? '') === 'postgres', 'postgres compose service');
assert_true(($postgresInfraCtx['pull_recreate'] ?? false) === true, 'postgres compose pull recreate');
file_put_contents($composeProj . '/compose/php-8.5.yml', "services:\n  php-8.5: {}\n");
$phpCtx = $compose->actionContextForFile('php-8.5.yml');
assert_true(($phpCtx['runtime'] ?? '') === 'php', 'php compose runtime');
assert_true(($phpCtx['service'] ?? '') === 'php-8.5', 'php compose service');
assert_true(($phpCtx['pull_recreate'] ?? true) === false, 'php compose no pull recreate');
assert_true($compose->actionContextForFile('custom.yml') === null, 'custom compose no runtime');
$kafkaYaml = <<<'YAML'
services:
  kafka:
    profiles: ["kafka"]
    image: apache/kafka:latest
    container_name: kafka_container
YAML;
$parsed = ComposeFileParser::services($kafkaYaml);
assert_true(($parsed[0]['name'] ?? '') === 'kafka', 'parser service name');
assert_true(($parsed[0]['has_build'] ?? true) === false, 'image-only service has no build');
$buildYaml = <<<'YAML'
services:
  app:
    profiles: ["app"]
    build:
      context: .
    container_name: app_container
YAML;
$built = ComposeFileParser::services($buildYaml);
assert_true(($built[0]['has_build'] ?? false) === true, 'build-only service needs build');
$bothYaml = <<<'YAML'
services:
  mysql:
    image: mysql:8
    build:
      context: .
YAML;
$both = ComposeFileParser::services($bothYaml);
assert_true(($both[0]['has_build'] ?? true) === false, 'image plus build uses pull not build button');
assert_true(($parsed[0]['profile'] ?? '') === 'kafka', 'parser profile');
assert_true(($parsed[0]['container'] ?? '') === 'kafka_container', 'parser container');
$withNetworks = <<<'YAML'
services:
  kafka:
    profiles: ["kafka"]
    image: apache/kafka:latest
    container_name: kafka_container

networks:
  app-network:
    driver: bridge
YAML;
$parsedNetworks = ComposeFileParser::services($withNetworks);
assert_true(count($parsedNetworks) === 1, 'parser ignores networks section');
assert_true(($parsedNetworks[0]['name'] ?? '') === 'kafka', 'parser networks section service name');
$minioYaml = <<<'YAML'
services:
  minio:
    profiles: ["minio"]
    image: minio/minio:latest
    container_name: minio_container
YAML;
$customYaml = <<<'YAML'
services:
  meilisearch:
    profiles: ["meilisearch"]
    image: getmeili/meilisearch:latest
    container_name: meilisearch_container
YAML;
$customCtx = (new InfraCompose($composeProj))->actionContextForFile('meilisearch.yml', $customYaml);
assert_true(($customCtx['runtime'] ?? '') === 'compose', 'custom compose runtime');
assert_true(($customCtx['has_build'] ?? true) === false, 'custom compose no build');
$kafkaInfraCtx = (new InfraCompose($composeProj))->actionContextForFile('kafka.yml');
assert_true(($kafkaInfraCtx['runtime'] ?? '') === 'infra', 'kafka compose runtime');
assert_true(($kafkaInfraCtx['service'] ?? '') === 'kafka', 'kafka compose service');
assert_true(($kafkaInfraCtx['pull_recreate'] ?? false) === true, 'kafka compose pull recreate');
$mailpitInfraCtx = (new InfraCompose($composeProj))->actionContextForFile('mailpit.yml');
assert_true(($mailpitInfraCtx['runtime'] ?? '') === 'infra', 'mailpit compose runtime');
assert_true(($mailpitInfraCtx['service'] ?? '') === 'mailpit', 'mailpit compose service');
assert_true(($mailpitInfraCtx['pull_recreate'] ?? false) === true, 'mailpit compose pull recreate');
$minioInfraCtx = (new InfraCompose($composeProj))->actionContextForFile('minio.yml', $minioYaml);
assert_true(($minioInfraCtx['runtime'] ?? '') === 'infra', 'minio compose runtime');
assert_true(($minioInfraCtx['service'] ?? '') === 'minio', 'minio compose service');
assert_true(($minioInfraCtx['pull_recreate'] ?? false) === true, 'minio compose pull recreate');
file_put_contents($composeProj . '/compose/meilisearch.yml', $customYaml);
$composeProjDocker = sys_get_temp_dir() . '/compose-include-' . bin2hex(random_bytes(4));
mkdir($composeProjDocker . '/compose', 0775, true);
file_put_contents(
    $composeProjDocker . '/docker-compose.yml',
    "include:\n  - path: compose/redis.yml\n    project_directory: .\n\nservices: {}\n",
);
$composeInclude = new ComposeInclude($composeProjDocker);
$composeInclude->ensureIncluded('meilisearch.yml');
assert_true($composeInclude->isIncluded('meilisearch.yml'), 'meilisearch included in docker-compose');
$composeFileRuntime = new ComposeFileRuntime($infraTmp, $composeProj, $runningDaemon);
$composeReq = $composeFileRuntime->request('meilisearch.yml', 'create');
assert_true(strlen($composeReq) === 32, 'compose file create request id');
assert_true($composeFileRuntime->hasBlockingRequests('meilisearch.yml'), 'compose file blocking create');

$logDir = $infraTmp . '/status';
file_put_contents($logDir . '/compose-file__meilisearch.yml.last-create.log', "create ok\n");
file_put_contents(
    $logDir . '/compose-file__meilisearch.yml.json',
    '{"compose_file":"meilisearch.yml","queue_key":"compose-file__meilisearch.yml","state":"error","message_key":"php_controller.action_failed","request_id":"a","updated_at":"2026-08-28T10:00:00Z"}' . "\n",
);
$actionLogs = $composeFileRuntime->actionLogs('meilisearch.yml');
assert_true(($actionLogs['state'] ?? '') === 'error', 'compose action logs state');
assert_true(str_contains((string) ($actionLogs['content'] ?? ''), 'create ok'), 'compose action logs content');
$pullId = (new InfraRuntime($infraTmp, $runningDaemon))->request('redis', 'pull-recreate');
assert_true(strlen($pullId) === 32, 'pull-recreate request id');
assert_true((new InfraRuntime($infraTmp, $runningDaemon))->hasBlockingRequests('redis'), 'redis blocking pull-recreate');
try {
    (new InfraRuntime($infraTmp, $runningDaemon))->request('rabbitmq', 'delete');
    assert_true(false, 'delete must use deleteContainer');
} catch (\Manager\Http\HttpException $e) {
    assert_true($e->errorKey() === 'services.invalid_action', 'delete not queued');
}
try {
    (new InfraRuntime($infraTmp, $runningDaemon))->request('rabbitmq', 'delete-image');
    assert_true(false, 'delete-image must use deleteImage');
} catch (\Manager\Http\HttpException $e) {
    assert_true($e->errorKey() === 'services.invalid_action', 'delete-image not queued');
}

$parsedImage = ComposeFileParser::services("services:\n  mysql:\n    image: mysql:8.4\n");
assert_true(($parsedImage[0]['image'] ?? '') === 'mysql:8.4', 'compose parser image field');
$infraTargets = InfraRuntime::targets();
assert_true(array_key_exists('image', $infraTargets['mysql'] ?? []), 'infra targets image key');
assert_true(array_key_exists('image_present', $infraTargets['mysql'] ?? []), 'infra targets image_present key');
assert_true(
    \Manager\Support\DockerImageIndex::listed('mysql:8.4', ['mysql:8.4', 'redis:7']),
    'image index matches repo tag',
);
assert_true(
    \Manager\Support\DockerImageIndex::listed('docker.io/library/mysql:8.4', ['mysql:8.4']),
    'image index strips docker.io library prefix',
);
assert_true(
    \Manager\Support\DockerImageIndex::listed('mysql', ['mysql:latest']) === true,
    'image index defaults bare name to latest',
);
assert_true(
    \Manager\Support\DockerImageIndex::listed('mysql:8.0', ['mysql:8.4']) === false,
    'image index rejects a different tag',
);
assert_true(\Manager\Support\DockerImageIndex::normalize('docker.io/library/redis') === 'redis:latest', 'normalize library image');

$frame = pack('C', 1) . "\0\0\0" . pack('N', 5) . 'hello';
assert_true(\Manager\Support\DockerExec::decodeLogStream($frame) === 'hello', 'decode multiplexed docker logs');
assert_true(\Manager\Support\DockerExec::decodeLogStream("plain\nlog") === "plain\nlog", 'decode raw docker logs');
$mysqlLogs = (new InfraRuntime($infraTmp))->logs('mysql', 50);
assert_true(($mysqlLogs['service'] ?? '') === 'mysql', 'infra logs service');
assert_true(($mysqlLogs['container'] ?? '') === 'mysql_container', 'infra logs container');
assert_true(array_key_exists('available', $mysqlLogs), 'infra logs available key');
try {
    (new InfraRuntime($infraTmp))->logs('nope');
    assert_true(false, 'invalid infra logs service');
} catch (\Manager\Http\HttpException $e) {
    assert_true($e->errorKey() === 'services.invalid_service', 'invalid infra logs service');
}

$phpLogs = (new PhpRuntime())->logs('php-8.5', 50);
assert_true(($phpLogs['service'] ?? '') === 'php-8.5', 'php logs service');
assert_true(($phpLogs['container'] ?? '') === 'php8.5_container', 'php logs container');
assert_true(array_key_exists('available', $phpLogs), 'php logs available key');
try {
    (new PhpRuntime())->logs('nope');
    assert_true(false, 'invalid php logs service');
} catch (\Manager\Http\HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.invalid_service', 'invalid php logs service');
}

use Manager\Models\TerminalSession;
use Manager\Support\Config;

$allowed = TerminalSession::allowedPhpContainers(Config::projectPath());
assert_true(isset($allowed['php8.5_container']), 'default php container allowlisted');
assert_true(!isset($allowed['nginx_container']), 'nginx not allowlisted');

assert_true(
    TerminalSession::projectDirFromServerPath('/var/www/source/spa-fnb-retail/public')
        === '/var/www/source/spa-fnb-retail',
    'terminal cwd strips /public',
);
assert_true(
    TerminalSession::projectDirFromServerPath('/var/www/source/posapp-yii-backend/web')
        === '/var/www/source/posapp-yii-backend',
    'terminal cwd strips /web',
);
assert_true(
    TerminalSession::projectDirFromServerPath('/var/www/source/app/webroot')
        === '/var/www/source/app',
    'terminal cwd strips /webroot',
);
assert_true(
    TerminalSession::projectDirFromServerPath('/tmp/evil') === '',
    'terminal cwd rejects non-source paths',
);
assert_true(
    TerminalSession::projectDirFromServerPath('/var/www/source_php8.5/app/public') === '',
    'terminal cwd rejects legacy version path',
);
assert_true(PhpVersionId::sourcePrefix('php-8.5') === '/var/www/source', 'shared source prefix 8.5');
assert_true(PhpVersionId::sourcePrefix('php-7.4') === '/var/www/source', 'shared source prefix 7.4');
assert_true(PhpVersionId::sourceDirName('php-8.4-alpine') === 'source', 'variant shares source dir');

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

$installStoppedProj = sys_get_temp_dir() . '/php-install-stopped-' . bin2hex(random_bytes(4));
mkdir($installStoppedProj . '/compose', 0775, true);
file_put_contents(
    $installStoppedProj . '/docker-compose.yml',
    "include:\n  - path: compose/php-8.5.yml\n    project_directory: .\n\nservices: {}\n",
);
try {
    (new PhpVersionInstaller($installStoppedProj, $stoppedDaemon))->install('8.6');
    assert_true(false, 'PHP install must refuse when daemon stopped');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_running', 'PHP install refuse key');
}
assert_true(
    !is_dir($installStoppedProj . '/docker_files/generated')
        && !is_file($installStoppedProj . '/compose/php-8.6.yml'),
    'PHP install wrote no files when daemon stopped',
);

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

$clearEnvPath = $envMissingDir . '/clear-domain-env.json';
file_put_contents(
    $clearEnvPath,
    "{\"SERVER_NAME1\":{\"DOMAIN_NAME\":\"app1.test\",\"APP_NAME\":\"app1\",\"SERVER_PATH\":\"/var/www/source/app1/public\"}}\n",
);
$clearEnv = new EnvConfig($clearEnvPath);
$clearHosts = new HostsSync($hostsTmp);
$clearHosts->saveExtras(['app1.test', 'other.test']);
$clearedDomain = $clearHosts->normalizeDomain((string) ($clearEnv->all()['SERVER_NAME1']['DOMAIN_NAME'] ?? ''));
$clearedServer = $clearEnv->clearDomain('SERVER_NAME1');
$clearHosts->removeExtra($clearedDomain);
$afterClear = $clearEnv->all();
assert_true(isset($afterClear['SERVER_NAME1']), 'clearDomain keeps the server');
assert_true(($clearedServer['DOMAIN_NAME'] ?? 'x') === '', 'clearDomain blanks DOMAIN_NAME');
assert_true(($afterClear['SERVER_NAME1']['APP_NAME'] ?? '') === 'app1', 'clearDomain keeps the app');
assert_true($clearHosts->extras() === ['other.test'], 'server domain delete drops the matching hosts extra');
$listedAfterClear = $clearHosts->listedDomains($afterClear, null);
assert_true(
    count($listedAfterClear) === 1 && ($listedAfterClear[0]['domain_name'] ?? '') === 'other.test',
    'listedDomains hides a domain cleared from both sources',
);
$restored = $clearEnv->assignDomain('SERVER_NAME1', 'app1.test');
assert_true(($restored['DOMAIN_NAME'] ?? '') === 'app1.test', 'assignDomain restores DOMAIN_NAME');
assert_true(($clearEnv->assignDomain('SERVER_NAME1', 'other.test')['DOMAIN_NAME'] ?? '') === 'app1.test', 'assignDomain does not replace a different domain');
$clearHosts->ensureExtra('app1.test');
assert_true(in_array('app1.test', $clearHosts->extras(), true), 'ensureExtra puts a hosts name back');

assert_true(HostsSync::normalizeWriteToken('DEADBEEFcafe') === 'deadbeefcafe', 'normalizeWriteToken lowercases hex');
assert_true(HostsSync::normalizeWriteToken('not a token!') === '', 'normalizeWriteToken rejects junk');
assert_true(HostsSync::normalizeWriteToken('abcd') === '', 'normalizeWriteToken rejects short token');

$hosts->request(true, 'solo.test', 'deadbeefcafebabe');
$sync = json_decode((string) file_get_contents($hostsTmp . '/hosts.sync'), true);
assert_true(is_array($sync), 'hosts.sync is json');
assert_true(($sync['request_id'] ?? '') === 'deadbeefcafebabe', 'request stores write token as request_id');
assert_true(($sync['force_admin'] ?? false) === true, 'request force_admin');
assert_true(($sync['focus_domain'] ?? '') === 'solo.test', 'request focus_domain');

$hosts->request(false, '', 'bad token');
$sync2 = json_decode((string) file_get_contents($hostsTmp . '/hosts.sync'), true);
assert_true(($sync2['request_id'] ?? 'bad token') !== 'bad token', 'junk token not stored');
assert_true(preg_match('/^[a-f0-9]{16}$/', (string) ($sync2['request_id'] ?? '')) === 1, 'generated request_id is 16 hex');

assert_true($hosts->validateDomain('solo.test') === null, 'solo.test valid');
assert_true($hosts->validateDomain('solo.local') === null, 'solo.local valid');
assert_true($hosts->validateDomain('api.solo.test') === null, 'subdomain.test valid');
assert_true($hosts->validateDomain('solo.com') === null, 'custom tld .com valid');
assert_true($hosts->validateDomain('shop.lan') === null, 'custom tld .lan valid');
assert_true(($hosts->validateDomain('solo')['key'] ?? '') === 'validation.local_domain', 'reject missing tld');
assert_true(($hosts->validateDomain('.test')['key'] ?? '') === 'validation.local_domain', 'reject empty name');

use Manager\Models\SslCertificates;

$sslRoot = sys_get_temp_dir() . '/mgr-ssl-' . bin2hex(random_bytes(4));
mkdir($sslRoot, 0775, true);
$ssl = new SslCertificates($sslRoot);

assert_true(SslCertificates::isEnabled([]) === false, 'ssl default off');
assert_true(SslCertificates::isEnabled(['SSL_ENABLED' => true]) === true, 'ssl enabled true');
assert_true(SslCertificates::mode(['SSL_ENABLED' => true, 'SSL_MODE' => 'generated']) === 'generated', 'ssl mode generated');

$ssl->generate('my-app', 'my-app.test');
assert_true($ssl->filesPresent('my-app'), 'generate writes cert and key');
$certPem = (string) file_get_contents($ssl->directoryFor('my-app') . '/cert.pem');
$parsed = openssl_x509_parse($certPem);
$san = (string) ($parsed['extensions']['subjectAltName'] ?? '');
assert_true(str_contains($san, 'DNS:my-app.test'), 'generated SAN has domain');
assert_true($ssl->namesMatch('my-app', 'my-app.test'), 'names match generated domain');
assert_true($ssl->namesMatch('my-app', 'other.test') === false, 'names mismatch other domain');

$ssl->generate('my-app', 'renamed.test');
assert_true($ssl->namesMatch('my-app', 'renamed.test'), 'regenerate updates SAN');

$pairCert = (string) file_get_contents($ssl->directoryFor('my-app') . '/cert.pem');
$pairKey = (string) file_get_contents($ssl->directoryFor('my-app') . '/key.pem');
$ssl->writeUploaded('uploaded-app', $pairCert, $pairKey);
assert_true($ssl->filesPresent('uploaded-app'), 'upload writes files');

try {
    $ssl->writeUploaded('bad', 'not-a-cert', $pairKey);
    assert_true(false, 'non-PEM cert should throw');
} catch (HttpException $e) {
    assert_true($e->status() === 422, 'non-PEM cert is 422');
    assert_true(isset($e->fields()['ssl_certificate']), 'error on ssl_certificate');
}

try {
    $ssl->writeUploaded('bad', $pairCert, '');
    assert_true(false, 'missing key should throw');
} catch (HttpException $e) {
    assert_true(isset($e->fields()['ssl_private_key']), 'error on ssl_private_key');
}

$ssl->generate('old-app', 'old.test');
$ssl->persist(
    ['APP_NAME' => 'old-app', 'DOMAIN_NAME' => 'old.test', 'SSL_ENABLED' => true, 'SSL_MODE' => 'generated'],
    ['APP_NAME' => 'new-app', 'DOMAIN_NAME' => 'old.test', 'SSL_ENABLED' => true, 'SSL_MODE' => 'generated'],
);
assert_true($ssl->filesPresent('new-app'), 'persist renames app dir');
assert_true($ssl->filesPresent('old-app') === false, 'old app dir gone');

$ssl->deleteApp('new-app');
assert_true($ssl->filesPresent('new-app') === false, 'deleteApp removes dir');

$enriched = $ssl->enrich([
    'APP_NAME' => 'uploaded-app',
    'DOMAIN_NAME' => 'renamed.test',
    'SSL_ENABLED' => true,
    'SSL_MODE' => 'uploaded',
]);
assert_true(($enriched['ssl_enabled'] ?? null) === true, 'enrich ssl_enabled');
assert_true(($enriched['ssl_mode'] ?? '') === 'uploaded', 'enrich ssl_mode');
assert_true(($enriched['ssl_files_present'] ?? false) === true, 'enrich files present');
assert_true(($enriched['ssl_names_match'] ?? true) === true, 'enrich names match uploaded cert');
assert_true(!isset($enriched['ssl_private_key']), 'enrich omits private key');

$tmpEnv = sys_get_temp_dir() . '/mgr-ssl-env-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($tmpEnv, "{}\n");
$envSsl = new EnvConfig($tmpEnv);
$vOff = $envSsl->validate([
    'app_name' => 'app-one',
    'domain_name' => 'app-one.test',
    'server_path' => '/var/www/source/app-one/public',
    'php_version' => 'php-8.5',
], []);
assert_true(($vOff['server']['SSL_ENABLED'] ?? true) === false, 'validate default ssl off');
assert_true(!isset($vOff['server']['SSL_MODE']), 'no ssl mode when off');

$vOn = $envSsl->validate([
    'app_name' => 'app-one',
    'domain_name' => 'app-one.test',
    'server_path' => '/var/www/source/app-one/public',
    'php_version' => 'php-8.5',
    'ssl_enabled' => true,
], []);
assert_true($vOn['errors'] === [] && $vOn['server']['SSL_ENABLED'] === true, 'ssl_enabled on');
assert_true(($vOn['server']['SSL_MODE'] ?? '') === 'generated', 'default mode generated');

$vUp = $envSsl->validate([
    'app_name' => 'app-one',
    'domain_name' => 'app-one.test',
    'server_path' => '/var/www/source/app-one/public',
    'php_version' => 'php-8.5',
    'ssl_enabled' => true,
    'ssl_certificate' => 'x',
    'ssl_private_key' => 'y',
], []);
assert_true(($vUp['server']['SSL_MODE'] ?? '') === 'uploaded', 'both pems set uploaded mode');

$vOne = $envSsl->validate([
    'app_name' => 'app-one',
    'domain_name' => 'app-one.test',
    'server_path' => '/var/www/source/app-one/public',
    'php_version' => 'php-8.5',
    'ssl_enabled' => true,
    'ssl_certificate' => 'x',
], []);
assert_true(isset($vOne['errors']['ssl_private_key']), 'one pem requires key');

$existing = ['SERVER_NAME1' => $vOn['server']];
$vKeep = $envSsl->validate([
    'app_name' => 'app-one',
    'domain_name' => 'app-one.test',
    'server_path' => '/var/www/source/app-one/public',
    'php_version' => 'php-8.5',
    'enabled' => false,
], $existing, 'SERVER_NAME1');
assert_true($vKeep['server']['SSL_ENABLED'] === true, 'omitted ssl_enabled preserves previous');
assert_true(($vKeep['server']['SSL_MODE'] ?? '') === 'generated', 'omitted mode preserves generated');

$proj = sys_get_temp_dir() . '/mgr-ssl-ctrl-' . bin2hex(random_bytes(4));
mkdir($proj, 0775, true);
file_put_contents($proj . '/env.json', "{}\n");
$envC = new EnvConfig($proj . '/env.json');
$sslC = new SslCertificates($proj);
$validated = $envC->validate([
    'app_name' => 'ctrl-app',
    'domain_name' => 'ctrl.test',
    'server_path' => '/var/www/source/ctrl-app/public',
    'php_version' => 'php-8.5',
    'ssl_enabled' => true,
], []);
$sslC->persist([], $validated['server']);
$envC->save(['SERVER_NAME1' => $validated['server']]);
assert_true($sslC->filesPresent('ctrl-app'), 'controller persist generates files');
$sslC->deleteApp('ctrl-app');
assert_true(!$sslC->filesPresent('ctrl-app'), 'deleteApp after destroy');

use Manager\Models\PhpSnippetRunner;
use Manager\Support\DockerExec;

assert_true(
    PhpSnippetRunner::normalizeCode('echo 1;') === "<?php\necho 1;",
    'snippet prepends php tag',
);
assert_true(
    PhpSnippetRunner::normalizeCode("<?php\necho 1;") === "<?php\necho 1;",
    'snippet keeps php tag',
);
assert_true(
    PhpSnippetRunner::normalizeCode('<?= 2 ?>') === '<?= 2 ?>',
    'snippet keeps short echo tag',
);
assert_true(
    PhpSnippetRunner::normalizeCode("\xEF\xBB\xBFecho 1;") === "<?php\necho 1;",
    'snippet strips bom then prepends',
);

$frameOut = chr(1) . "\0\0\0" . pack('N', 5) . 'hello';
$frameErr = chr(2) . "\0\0\0" . pack('N', 3) . 'err';
[$muxOut, $muxErr, $muxRest] = DockerExec::splitMultiplexed($frameOut . $frameErr);
assert_true($muxOut === 'hello', 'multiplex stdout');
assert_true($muxErr === 'err', 'multiplex stderr');
assert_true($muxRest === '', 'multiplex remainder empty');

$partial = chr(1) . "\0\0\0" . pack('N', 10) . 'abc';
[$pOut, $pErr, $pRest] = DockerExec::splitMultiplexed($partial);
assert_true($pOut === '' && $pErr === '' && $pRest === $partial, 'incomplete multiplex frame stays remainder');

use Manager\Models\PhpScratchPad;

$scratchDir = sys_get_temp_dir() . '/php-scratch-' . bin2hex(random_bytes(4));
$pad = new PhpScratchPad($scratchDir);
$empty = $pad->read('php-8.5');
assert_true($empty['code'] === PhpScratchPad::DEFAULT_CODE, 'scratch default code');
assert_true($empty['result'] === null, 'scratch default result');
assert_true(count($empty['sessions']) === 1, 'scratch default one session');
assert_true(PhpScratchPad::isValidId((string) $empty['id']), 'scratch default session id');
$written = $pad->write('php-8.5', "<?php echo 1;\n");
assert_true($written['code'] === "<?php echo 1;\n", 'scratch write code');
assert_true($written['updated_at'] !== '', 'scratch updated_at');
$reread = $pad->read('php-8.5');
assert_true($reread['code'] === "<?php echo 1;\n", 'scratch reread code');
$withOut = $pad->write('php-8.5', "<?php echo 1;\n", [
    'stdout' => "1\n",
    'stderr' => '',
    'exit_code' => 0,
    'timed_out' => false,
    'truncated' => false,
    'duration_ms' => 12,
    'php_version' => 'PHP 8.5',
]);
assert_true(($withOut['result']['stdout'] ?? '') === "1\n", 'scratch stores result');
$keep = $pad->write('php-8.5', "<?php echo 2;\n");
assert_true($keep['code'] === "<?php echo 2;\n", 'scratch updates code');
assert_true(($keep['result']['stdout'] ?? '') === "1\n", 'scratch preserves last result');
$created = $pad->create('php-8.5', 'Helpers');
assert_true($created['name'] === 'Helpers', 'scratch create name');
assert_true(count($created['sessions']) === 2, 'scratch create second session');
assert_true($created['code'] === PhpScratchPad::DEFAULT_CODE, 'scratch create default code');
$firstId = $keep['id'];
$secondId = $created['id'];
$renamed = $pad->rename('php-8.5', $secondId, '  Utils  ');
assert_true($renamed['name'] === 'Utils', 'scratch rename');
$pad->write('php-8.5', "<?php echo 'b';\n", null, true, $secondId);
$activated = $pad->activate('php-8.5', $firstId);
assert_true($activated['id'] === $firstId, 'scratch activate first');
assert_true($activated['code'] === "<?php echo 2;\n", 'scratch activate keeps first code');
$deleted = $pad->delete('php-8.5', $firstId);
assert_true($deleted['id'] === $secondId, 'scratch delete switches to remaining');
assert_true(count($deleted['sessions']) === 1, 'scratch delete leaves one');
$legacyDir = sys_get_temp_dir() . '/php-scratch-legacy-' . bin2hex(random_bytes(4));
mkdir($legacyDir, 0775, true);
file_put_contents($legacyDir . '/php-8.5.json', json_encode([
    'service' => 'php-8.5',
    'code' => "<?php echo 'legacy';\n",
    'result' => ['stdout' => "legacy\n", 'stderr' => '', 'exit_code' => 0],
    'updated_at' => '2026-08-25T00:00:00+00:00',
], JSON_THROW_ON_ERROR));
$legacyPad = new PhpScratchPad($legacyDir);
$migrated = $legacyPad->read('php-8.5');
assert_true($migrated['code'] === "<?php echo 'legacy';\n", 'scratch migrates legacy code');
assert_true(($migrated['result']['stdout'] ?? '') === "legacy\n", 'scratch migrates legacy result');
assert_true(count($migrated['sessions']) === 1, 'scratch migrates to one session');
$migratedAgain = $legacyPad->read('php-8.5');
assert_true($migratedAgain['id'] === $migrated['id'], 'scratch migration keeps session id');
try {
    $pad->read('../evil');
    assert_true(false, 'scratch rejects invalid service');
} catch (\Manager\Http\HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.invalid_service', 'scratch rejects invalid service');
}
try {
    $pad->rename('php-8.5', 'ffffffffffff', 'Nope');
    assert_true(false, 'scratch rejects missing session');
} catch (\Manager\Http\HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.session_not_found', 'scratch rejects missing session');
}

$runningStatus = $runningDaemon->status();
assert_true($runningStatus['container'] === 'php_controller_container', 'daemon container name');
assert_true($runningStatus['state'] === 'running', 'daemon running state');
assert_true($runningStatus['start_available'] === false, 'daemon running not startable');

$stoppedStatus = $stoppedDaemon->status();
assert_true($stoppedStatus['state'] === 'stopped', 'daemon stopped state');
assert_true($stoppedStatus['start_available'] === true, 'daemon stopped is startable');

$missingStatus = $missingDaemon->status();
assert_true($missingStatus['state'] === 'not_created', 'daemon missing state');
assert_true($missingStatus['start_available'] === false, 'daemon missing not startable');

$nullStatus = $nullDaemon->status();
assert_true($nullStatus['state'] === 'not_created', 'daemon probe-null aliases not_created');
assert_true($nullStatus['start_available'] === false, 'daemon probe-null not startable');

$runningDaemon->assertRunning();

try {
    $stoppedDaemon->assertRunning();
    assert_true(false, 'stopped daemon must fail assertRunning');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_running', 'assertRunning key');
    assert_true($e->status() === 409, 'assertRunning 409');
}

$already = $runningDaemon->start();
assert_true($already['message_key'] === 'php_controller.daemon_already_running', 'start no-op when running');
assert_true($already['php_controller_daemon']['state'] === 'running', 'start no-op status');

try {
    $missingDaemon->start();
    assert_true(false, 'start missing must 409');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_created', 'start missing key');
    assert_true($e->status() === 409, 'start missing 409');
}

$nullStart = new PhpControllerDaemon(static fn (): ?string => null);
try {
    $nullStart->start();
    assert_true(false, 'start probe-null must 503');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_docker_unavailable', 'start probe-null key');
    assert_true($e->status() === 503, 'start probe-null 503');
}

$started = new PhpControllerDaemon(
    static fn (): string => 'stopped',
    static fn (): int => 204,
);
$startOk = $started->start();
assert_true($startOk['message_key'] === 'php_controller.daemon_started', 'start 204 key');
assert_true($startOk['php_controller_daemon']['state'] === 'running', 'start 204 reports running');
assert_true($startOk['php_controller_daemon']['start_available'] === false, 'start 204 not startable');
assert_true($startOk['php_controller_daemon']['create_available'] === false, 'start 204 not creatable');
assert_true($runningStatus['create_available'] === false, 'daemon running not creatable');
assert_true($missingStatus['create_available'] === true, 'daemon missing is creatable');

$createState = 'not_created';
$createStateful = new PhpControllerDaemon(
    static function () use (&$createState): string {
        return $createState;
    },
    null,
    null,
    null,
    null,
    null,
    null,
    static function () use (&$createState): bool {
        $createState = 'running';

        return true;
    },
);
$createOkResult = $createStateful->create();
assert_true($createOkResult['message_key'] === 'php_controller.daemon_created', 'create ok key');
assert_true($createOkResult['php_controller_daemon']['state'] === 'running', 'create ok state');
assert_true($createOkResult['php_controller_daemon']['create_available'] === false, 'create ok not creatable');

$createAlready = new PhpControllerDaemon(static fn (): string => 'stopped');
$createAlreadyResult = $createAlready->create();
assert_true($createAlreadyResult['message_key'] === 'php_controller.daemon_already_installed', 'create already key');

$createFail = new PhpControllerDaemon(
    static fn (): string => 'not_created',
    null,
    null,
    null,
    null,
    null,
    null,
    static fn (): bool => false,
);
try {
    $createFail->create();
    assert_true(false, 'create fail must 502');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_create_failed', 'create fail key');
    assert_true($e->status() === 502, 'create fail 502');
}

$started304 = new PhpControllerDaemon(
    static fn (): string => 'stopped',
    static fn (): int => 304,
);
assert_true($started304->start()['message_key'] === 'php_controller.daemon_started', 'start 304 key');

$started404 = new PhpControllerDaemon(
    static fn (): string => 'stopped',
    static fn (): int => 404,
);
try {
    $started404->start();
    assert_true(false, 'start 404 must 409');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_created', 'start 404 key');
    assert_true($e->status() === 409, 'start 404 409');
}

$startedFail = new PhpControllerDaemon(
    static fn (): string => 'stopped',
    static fn (): int => 500,
);
try {
    $startedFail->start();
    assert_true(false, 'start 500 must 502');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_start_failed', 'start 500 key');
    assert_true($e->status() === 502, 'start 500 502');
}

$stopAlready = new PhpControllerDaemon(static fn (): string => 'stopped');
$stopAlreadyResult = $stopAlready->stop();
assert_true($stopAlreadyResult['message_key'] === 'php_controller.daemon_already_stopped', 'stop already key');
assert_true($stopAlreadyResult['php_controller_daemon']['state'] === 'stopped', 'stop already state');

$stopOk = new PhpControllerDaemon(
    static fn (): string => 'running',
    null,
    static fn (): bool => true,
);
$stopOkResult = $stopOk->stop();
assert_true($stopOkResult['message_key'] === 'php_controller.daemon_stopped', 'stop ok key');
assert_true($stopOkResult['php_controller_daemon']['state'] === 'stopped', 'stop ok state');
assert_true($stopOkResult['php_controller_daemon']['start_available'] === true, 'stop ok startable');

$stopFail = new PhpControllerDaemon(
    static fn (): string => 'running',
    null,
    static fn (): bool => false,
);
try {
    $stopFail->stop();
    assert_true(false, 'stop fail must 502');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_stop_failed', 'stop fail key');
    assert_true($e->status() === 502, 'stop fail 502');
}

try {
    $missingDaemon->stop();
    assert_true(false, 'stop missing must 409');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_created', 'stop missing key');
}

$restartOk = new PhpControllerDaemon(
    static fn (): string => 'running',
    null,
    null,
    static fn (): bool => true,
);
$restartOkResult = $restartOk->restart();
assert_true($restartOkResult['message_key'] === 'php_controller.daemon_restarted', 'restart ok key');
assert_true($restartOkResult['php_controller_daemon']['state'] === 'running', 'restart ok state');

$restartFail = new PhpControllerDaemon(
    static fn (): string => 'stopped',
    null,
    null,
    static fn (): bool => false,
);
try {
    $restartFail->restart();
    assert_true(false, 'restart fail must 502');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_restart_failed', 'restart fail key');
}

try {
    $missingDaemon->restart();
    assert_true(false, 'restart missing must 409');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_created', 'restart missing key');
}

$removeOk = new PhpControllerDaemon(
    static fn (): string => 'stopped',
    null,
    null,
    null,
    static fn (): bool => true,
);
$removeOkResult = $removeOk->remove();
assert_true($removeOkResult['message_key'] === 'php_controller.daemon_removed', 'remove ok key');
assert_true($removeOkResult['php_controller_daemon']['state'] === 'not_created', 'remove ok state');

$removeFail = new PhpControllerDaemon(
    static fn (): string => 'running',
    null,
    null,
    null,
    static fn (): bool => false,
);
try {
    $removeFail->remove();
    assert_true(false, 'remove fail must 502');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_remove_failed', 'remove fail key');
}

try {
    $missingDaemon->remove();
    assert_true(false, 'remove missing must 409');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_created', 'remove missing key');
}

$nullLifecycle = new PhpControllerDaemon(static fn (): ?string => null);
try {
    $nullLifecycle->stop();
    assert_true(false, 'stop probe-null must 503');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_docker_unavailable', 'stop probe-null key');
    assert_true($e->status() === 503, 'stop probe-null 503');
}

$detailsDaemon = new PhpControllerDaemon(
    static fn (): string => 'running',
    null,
    null,
    null,
    null,
    static fn (): array => [
        'Config' => ['Image' => 'docker:cli'],
        'Created' => '2026-01-01T00:00:00Z',
        'State' => ['StartedAt' => '2026-01-02T00:00:00Z'],
    ],
);
$details = $detailsDaemon->details();
assert_true($details['image'] === 'docker:cli', 'details image');
assert_true($details['created'] === '2026-01-01T00:00:00Z', 'details created');
assert_true($details['started_at'] === '2026-01-02T00:00:00Z', 'details started_at');

$logsDaemon = new PhpControllerDaemon(
    static fn (): string => 'running',
    null,
    null,
    null,
    null,
    null,
    static fn (int $tail): string => "line\n",
);
$logs = $logsDaemon->logs(50);
assert_true($logs['container'] === 'php_controller_container', 'logs container');
assert_true($logs['content'] === "line\n", 'logs content');

try {
    $missingDaemon->logs();
    assert_true(false, 'logs missing must 409');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_created', 'logs missing key');
}

$phpTmp = sys_get_temp_dir() . '/php-runtime-' . bin2hex(random_bytes(4));
mkdir($phpTmp . '/requests', 0775, true);
mkdir($phpTmp . '/status', 0775, true);

$phpStopped = new PhpRuntime($phpTmp, $stoppedDaemon);
try {
    $phpStopped->request('php-8.5', 'start');
    assert_true(false, 'php request must refuse when daemon stopped');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_running', 'php request refuse key');
}
$phpReqFiles = glob($phpTmp . '/requests/*.json') ?: [];
assert_true($phpReqFiles === [], 'php request wrote no files');

$supervisorTmp = sys_get_temp_dir() . '/supervisor-runtime-' . bin2hex(random_bytes(4));
mkdir($supervisorTmp . '/requests', 0775, true);
$supervisorStopped = new SupervisorRuntime($supervisorTmp, Config::projectPath(), $stoppedDaemon);
try {
    $supervisorStopped->request('supervisor-8.5', 'restart');
    assert_true(false, 'supervisor request must refuse when daemon stopped');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_running', 'supervisor request refuse key');
}
assert_true((glob($supervisorTmp . '/requests/*.json') ?: []) === [], 'supervisor request wrote no files');

$infraStopped = new InfraRuntime($infraTmp, $stoppedDaemon);
try {
    $infraStopped->request('mysql', 'start');
    assert_true(false, 'infra request must refuse when daemon stopped');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_running', 'infra request refuse key');
}

$nginxTmp = sys_get_temp_dir() . '/nginx-mgmt-' . bin2hex(random_bytes(4));
mkdir($nginxTmp . '/requests', 0775, true);
mkdir($nginxTmp . '/status', 0775, true);
file_put_contents($nginxTmp . '/requests/aaa__nginx__start.json', "{}\n");
$nginxBusyDown = new NginxManagement($nginxTmp, '', '', $stoppedDaemon);
$nginxStatusDown = $nginxBusyDown->status();
assert_true(($nginxStatusDown['state'] ?? '') !== 'busy', 'nginx not busy overlay when daemon down');
@unlink($nginxTmp . '/requests/aaa__nginx__start.json');
try {
    $nginxBusyDown->requestAction('start');
    assert_true(false, 'nginx request must refuse when daemon stopped');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'php_controller.daemon_not_running', 'nginx request refuse key');
}
assert_true((glob($nginxTmp . '/requests/*.json') ?: []) === [], 'nginx request wrote no files');

file_put_contents($infraTmp . '/requests/ccc__mysql__start.json', "{}\n");
$infraBusyDown = new InfraRuntime($infraTmp, $stoppedDaemon);
$downStatuses = $infraBusyDown->statuses();
assert_true(($downStatuses['mysql']['state'] ?? '') !== 'busy', 'mysql not busy overlay when daemon down');

file_put_contents($phpTmp . '/requests/ddd__php-8.5__start.json', "{}\n");
$phpStatusesDown = $phpStopped->statuses();
assert_true(($phpStatusesDown['php-8.5']['state'] ?? '') !== 'busy', 'PHP not busy overlay when daemon down');

$dcDefaults = DockerConnection::defaults();
assert_true($dcDefaults['mode'] === DockerConnection::MODE_LOCAL, 'docker connection default mode local');
assert_true($dcDefaults['tcp']['port'] === 2376, 'docker connection default tcp port');
assert_true($dcDefaults['ssh']['port'] === 22, 'docker connection default ssh port');
assert_true($dcDefaults['remote_project_path'] === '', 'docker connection default remote path empty');

$dcNormalized = DockerConnection::normalize([
    'mode' => 'bogus',
    'tcp' => ['host' => '  example.com ', 'port' => 99999, 'tls' => false],
    'ssh' => ['user' => ' u ', 'host' => ' h ', 'port' => 0],
    'remote_project_path' => '/tmp/project/',
]);
assert_true($dcNormalized['mode'] === DockerConnection::MODE_LOCAL, 'normalize falls back to local');
assert_true($dcNormalized['tcp']['host'] === 'example.com', 'normalize trims tcp host');
assert_true($dcNormalized['tcp']['port'] === 65535, 'normalize clamps tcp port high');
assert_true($dcNormalized['tcp']['tls'] === false, 'normalize keeps tls false');
assert_true($dcNormalized['ssh']['user'] === 'u', 'normalize trims ssh user');
assert_true($dcNormalized['ssh']['port'] === 1, 'normalize clamps ssh port low');
assert_true($dcNormalized['remote_project_path'] === '/tmp/project', 'normalize trims remote trailing slash');

$dcLocal = new DockerConnection(static fn (): array => DockerConnection::defaults());
$dcLocal->validate(DockerConnection::defaults());
assert_true(true, 'validate accepts local defaults');

try {
    $dcLocal->validate(DockerConnection::normalize([
        'mode' => DockerConnection::MODE_TCP,
        'tcp' => ['host' => '', 'port' => 2376, 'tls' => false],
        'remote_project_path' => '/remote/project',
    ]));
    assert_true(false, 'validate tcp must require host');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'docker_connection.tcp_host_required', 'tcp host required key');
}

try {
    $dcLocal->validate(DockerConnection::normalize([
        'mode' => DockerConnection::MODE_TCP,
        'tcp' => ['host' => 'docker.example', 'port' => 2376, 'tls' => true, 'ca' => '', 'cert' => '', 'key' => ''],
        'remote_project_path' => '/remote/project',
    ]));
    assert_true(false, 'validate tcp tls must require files');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'docker_connection.tls_files_required', 'tls files required key');
}

try {
    $dcLocal->validate(DockerConnection::normalize([
        'mode' => DockerConnection::MODE_SSH,
        'ssh' => ['user' => '', 'host' => 'remote', 'port' => 22, 'identity_file' => ''],
        'remote_project_path' => '/remote/project',
    ]));
    assert_true(false, 'validate ssh must require user/host');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'docker_connection.ssh_required', 'ssh required key');
}

try {
    $dcLocal->validate(DockerConnection::normalize([
        'mode' => DockerConnection::MODE_TCP,
        'tcp' => ['host' => 'docker.example', 'port' => 2375, 'tls' => false],
        'remote_project_path' => '',
    ]));
    assert_true(false, 'validate remote must require project path');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'docker_connection.remote_project_required', 'remote project required key');
}

$dcTlsDir = sys_get_temp_dir() . '/docker-tls-' . bin2hex(random_bytes(4));
mkdir($dcTlsDir, 0775, true);
$dcCa = $dcTlsDir . '/ca.pem';
$dcCert = $dcTlsDir . '/cert.pem';
$dcKey = $dcTlsDir . '/key.pem';
file_put_contents($dcCa, "ca\n");
file_put_contents($dcCert, "cert\n");
file_put_contents($dcKey, "key\n");
$dcId = $dcTlsDir . '/id_ed25519';
file_put_contents($dcId, "key\n");

$dcTcpOk = DockerConnection::normalize([
    'mode' => DockerConnection::MODE_TCP,
    'tcp' => ['host' => 'docker.example', 'port' => 2376, 'tls' => true, 'ca' => $dcCa, 'cert' => $dcCert, 'key' => $dcKey],
    'remote_project_path' => '/opt/web',
]);
$dcLocal->validate($dcTcpOk);
assert_true(true, 'validate accepts complete tcp_tls');
assert_true(DockerConnection::labelFor($dcTcpOk) === 'tcp://docker.example:2376', 'tcp label');
assert_true(DockerConnection::projectPathFor($dcTcpOk) === '/opt/web', 'tcp project path');

$dcSshOk = DockerConnection::normalize([
    'mode' => DockerConnection::MODE_SSH,
    'ssh' => ['user' => 'deploy', 'host' => 'builder', 'port' => 2222, 'identity_file' => $dcId],
    'remote_project_path' => '/opt/web',
]);
$dcLocal->validate($dcSshOk);
assert_true(true, 'validate accepts complete ssh');
assert_true(DockerConnection::labelFor($dcSshOk) === 'ssh://deploy@builder:2222', 'ssh label');
assert_true(DockerConnection::projectPathFor(DockerConnection::defaults()) === null, 'local project path null');

$dcRuntime = sys_get_temp_dir() . '/docker-conn-rt-' . bin2hex(random_bytes(4));
$dcPhpRt = sys_get_temp_dir() . '/docker-conn-php-' . bin2hex(random_bytes(4));
mkdir($dcRuntime, 0775, true);
mkdir($dcPhpRt, 0775, true);
$prevRuntime = getenv('MANAGER_RUNTIME_PATH');
$prevPhpCtrl = getenv('MANAGER_PHP_CONTROLLER_PATH');
putenv('MANAGER_RUNTIME_PATH=' . $dcRuntime);
putenv('MANAGER_PHP_CONTROLLER_PATH=' . $dcPhpRt);
$savedCfg = null;
$dcSaveModel = new DockerConnection(
    function () use (&$savedCfg): array {
        return $savedCfg ?? DockerConnection::defaults();
    },
    function (array $cfg) use (&$savedCfg): void {
        $savedCfg = $cfg;
    },
);
$saved = $dcSaveModel->save([
    'mode' => DockerConnection::MODE_TCP,
    'tcp' => ['host' => 'docker.example', 'port' => 2376, 'tls' => true, 'ca' => $dcCa, 'cert' => $dcCert, 'key' => $dcKey],
    'remote_project_path' => '/opt/web',
]);
assert_true($saved['mode'] === DockerConnection::MODE_TCP, 'save returns tcp mode');
assert_true($saved['updated_at'] !== '', 'save sets updated_at');
assert_true(is_string($savedCfg) === false && is_array($savedCfg), 'save invoked saver');
$dcEnvFile = $dcPhpRt . '/docker.env';
assert_true(is_file($dcEnvFile), 'save wrote docker.env');
$dcEnvBody = (string) file_get_contents($dcEnvFile);
assert_true(str_contains($dcEnvBody, 'DOCKER_HOST=tcp://docker.example:2376'), 'docker.env DOCKER_HOST');
assert_true(str_contains($dcEnvBody, 'DOCKER_TLS_VERIFY=1'), 'docker.env TLS verify');
assert_true(str_contains($dcEnvBody, 'DOCKER_CERT_PATH=' . $dcTlsDir), 'docker.env cert path');
assert_true(str_contains($dcEnvBody, 'HOST_PROJECT_PATH=/opt/web'), 'docker.env remote project path');
$dcStatus = $dcSaveModel->status(false);
assert_true(($dcStatus['effective']['mode'] ?? '') === DockerConnection::MODE_TCP, 'status effective mode');
assert_true(array_key_exists('reachable', $dcStatus) && $dcStatus['reachable'] === null, 'status without probe leaves reachable null');
if ($prevRuntime === false) {
    putenv('MANAGER_RUNTIME_PATH');
} else {
    putenv('MANAGER_RUNTIME_PATH=' . $prevRuntime);
}
if ($prevPhpCtrl === false) {
    putenv('MANAGER_PHP_CONTROLLER_PATH');
} else {
    putenv('MANAGER_PHP_CONTROLLER_PATH=' . $prevPhpCtrl);
}

$routes = require dirname(__DIR__) . '/routes.php';
$phpControllerRouteKeys = [];
$dockerConnectionRouteKeys = [];
foreach ($routes as $route) {
    if (($route[1] ?? '') === '/php-controller'
        || str_starts_with((string) ($route[1] ?? ''), '/php-controller/')) {
        $phpControllerRouteKeys[] = ($route[0] ?? '') . ' ' . ($route[1] ?? '');
    }
    if (($route[1] ?? '') === '/docker-connection'
        || str_starts_with((string) ($route[1] ?? ''), '/docker-connection/')) {
        $dockerConnectionRouteKeys[] = ($route[0] ?? '') . ' ' . ($route[1] ?? '');
    }
}
assert_true(in_array('GET /php-controller', $phpControllerRouteKeys, true), 'GET /php-controller route registered');
assert_true(in_array('POST /php-controller/create', $phpControllerRouteKeys, true), 'POST /php-controller/create route registered');
assert_true(in_array('POST /php-controller/start', $phpControllerRouteKeys, true), 'POST /php-controller/start route registered');
assert_true(in_array('POST /php-controller/stop', $phpControllerRouteKeys, true), 'POST /php-controller/stop route registered');
assert_true(in_array('POST /php-controller/restart', $phpControllerRouteKeys, true), 'POST /php-controller/restart route registered');
assert_true(in_array('POST /php-controller/remove', $phpControllerRouteKeys, true), 'POST /php-controller/remove route registered');
assert_true(in_array('GET /php-controller/logs', $phpControllerRouteKeys, true), 'GET /php-controller/logs route registered');
assert_true(in_array('GET /docker-connection', $dockerConnectionRouteKeys, true), 'GET /docker-connection route registered');
assert_true(in_array('PUT /docker-connection', $dockerConnectionRouteKeys, true), 'PUT /docker-connection route registered');
assert_true(in_array('POST /docker-connection/test', $dockerConnectionRouteKeys, true), 'POST /docker-connection/test route registered');

$bootCtrl = new class extends \Manager\Controllers\Controller {
    public function payload(): array
    {
        return $this->bootstrapPayload();
    }
};
$boot = $bootCtrl->payload();
assert_true(isset($boot['php_controller_daemon']['container']), 'bootstrap daemon key');
assert_true($boot['php_controller_daemon']['container'] === 'php_controller_container', 'bootstrap daemon container');
assert_true(in_array($boot['php_controller_daemon']['state'], ['running', 'stopped', 'not_created'], true), 'bootstrap daemon state');
assert_true(array_key_exists('start_available', $boot['php_controller_daemon']), 'bootstrap start_available');

use Manager\Models\SourceLogs;

assert_true(SourceLogs::normalizeRelative('storage/logs') === 'storage/logs', 'log path keeps relative dir');
assert_true(SourceLogs::normalizeRelative('/storage/logs/') === 'storage/logs', 'log path trims slashes');
assert_true(SourceLogs::normalizeRelative('../secret') === null, 'log path rejects traversal');
assert_true(SourceLogs::normalizeRelative('storage//logs') === null, 'log path rejects empty segment');
assert_true(SourceLogs::detectFramework('/var/www/source/shop/public') === 'laravel', 'detect laravel from public');
assert_true(SourceLogs::detectFramework('/var/www/source/shop/web') === 'yii', 'detect yii from web');
assert_true(SourceLogs::detectFramework('/var/www/source/shop/webroot') === 'cakephp', 'detect cakephp from webroot');
assert_true(SourceLogs::detectFramework('/var/www/source/shop') === 'plain', 'detect plain project root');
assert_true(SourceLogs::frameworkOf(['SERVER_PATH' => '/var/www/source/shop/public']) === 'laravel', 'old source guesses laravel');
assert_true(
    SourceLogs::effectiveRelative(['SERVER_PATH' => '/var/www/source/shop/public']) === 'storage/logs',
    'old source uses laravel preset',
);
assert_true(
    SourceLogs::effectiveRelative([
        'SERVER_PATH' => '/var/www/source/shop/public',
        'FRAMEWORK' => 'symfony',
        'LOG_PATH' => 'var/log/custom.log',
    ]) === 'var/log/custom.log',
    'log path override wins',
);

$sourceRoot = sys_get_temp_dir() . '/mgr-source-logs-' . bin2hex(random_bytes(4));
$appDir = $sourceRoot . '/server/source/shop';
mkdir($appDir . '/storage/logs', 0775, true);
file_put_contents($appDir . '/storage/logs/laravel.log', "hello log\nline two\n");
file_put_contents($appDir . '/storage/logs/queue.log', "queue\n");
$outside = sys_get_temp_dir() . '/mgr-source-logs-out-' . bin2hex(random_bytes(4));
mkdir($outside, 0775, true);
file_put_contents($outside . '/secret.log', "secret\n");
symlink($outside . '/secret.log', $appDir . '/storage/logs/secret.log');
$envFile = $sourceRoot . '/env.json';
$shop = [
    'APP_NAME' => 'shop',
    'DOMAIN_NAME' => 'shop.test',
    'SERVER_PATH' => '/var/www/source/shop/public',
    'CONTAINER_PHP_VERSION' => 'php8.5_container',
    'ENABLED' => true,
    'SSL_ENABLED' => false,
];
file_put_contents($envFile, json_encode(['SERVER_NAME2' => $shop], JSON_THROW_ON_ERROR));
$sourceLogs = new SourceLogs($sourceRoot, new EnvConfig($envFile));
assert_true(
    $sourceLogs->hostProjectDir('/var/www/source/shop/public') === $appDir,
    'host path maps container source dir',
);
$described = $sourceLogs->describe('SERVER_NAME2', $shop);
assert_true($described['framework'] === 'laravel', 'missing framework detects laravel');
assert_true($described['framework_stored'] === false, 'detected framework is not stored');
assert_true($described['kind'] === 'directory', 'laravel preset is a directory');
assert_true(count($described['files']) === 2, 'symlink outside project is skipped');
$names = array_column($described['files'], 'name');
assert_true(in_array('laravel.log', $names, true) && !in_array('secret.log', $names, true), 'only inside log files');
$read = $sourceLogs->read('SERVER_NAME2', 'laravel.log', 50);
assert_true(str_contains((string) ($read['log']['content'] ?? ''), 'hello log'), 'tail reads laravel.log');
$cleared = $sourceLogs->clear('SERVER_NAME2', 'laravel.log');
assert_true(($cleared['log']['content'] ?? 'x') === '', 'clear truncates log file');
assert_true(is_file($appDir . '/storage/logs/laravel.log'), 'clear keeps the file');
$written = $sourceLogs->write('SERVER_NAME2', 'laravel.log', "edited line\n");
assert_true(($written['log']['content'] ?? '') === "edited line\n", 'write replaces log contents');
assert_true(($written['log']['full'] ?? false) === true, 'write returns the full file');
$deleted = $sourceLogs->delete('SERVER_NAME2', 'laravel.log');
assert_true(!is_file($appDir . '/storage/logs/laravel.log'), 'delete removes the log file');
$left = array_column($deleted['source']['files'] ?? [], 'name');
assert_true(!in_array('laravel.log', $left, true) && in_array('queue.log', $left, true), 'delete drops only that file');
try {
    $sourceLogs->delete('SERVER_NAME2', 'secret.log');
    assert_true(false, 'delete rejects file outside the list');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'source_logs.file_missing', 'outside log file is missing');
}
try {
    $sourceLogs->saveConfig('SERVER_NAME2', 'custom', '../etc/passwd');
    assert_true(false, 'save rejects traversal');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'validation.failed', 'traversal is validation failed');
    assert_true(isset($e->fields()['log_path']), 'traversal error on log_path');
}
try {
    $sourceLogs->saveConfig('SERVER_NAME2', 'custom', '');
    assert_true(false, 'custom framework requires a path');
} catch (HttpException $e) {
    assert_true(isset($e->fields()['log_path']), 'empty custom path is required');
}
$saved = $sourceLogs->saveConfig('SERVER_NAME2', 'symfony', 'var/log');
assert_true($saved['framework'] === 'symfony' && $saved['log_path'] === 'var/log', 'save stores framework and path');
$storedServers = json_decode((string) file_get_contents($envFile), true);
assert_true(($storedServers['SERVER_NAME2']['FRAMEWORK'] ?? '') === 'symfony', 'env keeps FRAMEWORK');
assert_true(($storedServers['SERVER_NAME2']['LOG_PATH'] ?? '') === 'var/log', 'env keeps LOG_PATH');

$envKeep = new EnvConfig($envFile);
$kept = $envKeep->validate([
    'app_name' => 'shop',
    'domain_name' => 'shop.test',
    'server_path' => '/var/www/source/shop/public',
    'php_version' => 'php-8.5',
    'enabled' => true,
], ['SERVER_NAME2' => $storedServers['SERVER_NAME2']], 'SERVER_NAME2');
assert_true(($kept['server']['FRAMEWORK'] ?? '') === 'symfony', 'validate keeps FRAMEWORK');
assert_true(($kept['server']['LOG_PATH'] ?? '') === 'var/log', 'validate keeps LOG_PATH');
$clearedPath = $envKeep->validate([
    'app_name' => 'shop',
    'domain_name' => 'shop.test',
    'server_path' => '/var/www/source/shop/public',
    'php_version' => 'php-8.5',
    'framework' => 'laravel',
    'log_path' => '',
], ['SERVER_NAME2' => $storedServers['SERVER_NAME2']], 'SERVER_NAME2');
assert_true(($clearedPath['server']['FRAMEWORK'] ?? '') === 'laravel', 'validate stores framework');
assert_true(!isset($clearedPath['server']['LOG_PATH']), 'empty log_path clears override');

$hasSourceLogs = false;
foreach ($routes as $route) {
    if (($route[0] ?? null) === 'GET' && ($route[1] ?? null) === '/sources/logs') {
        $hasSourceLogs = true;
        break;
    }
}
assert_true($hasSourceLogs, 'GET /sources/logs route registered');

$hasLogStream = false;
foreach ($routes as $route) {
    if (($route[0] ?? null) === 'GET' && str_contains((string) ($route[1] ?? ''), '/logs/stream')) {
        $hasLogStream = true;
        break;
    }
}
assert_true($hasLogStream, 'GET source log stream route registered');

$hasSupervisorStream = false;
foreach ($routes as $route) {
    if (
        ($route[0] ?? null) === 'GET'
        && str_contains((string) ($route[1] ?? ''), '/supervisor/')
        && str_ends_with((string) ($route[1] ?? ''), '/logs/stream')
    ) {
        $hasSupervisorStream = true;
        break;
    }
}
assert_true($hasSupervisorStream, 'GET supervisor log stream route registered');

$supRoot = sys_get_temp_dir() . '/mgr-sup-' . bin2hex(random_bytes(4));
mkdir($supRoot . '/compose', 0775, true);
mkdir($supRoot . '/logs/supervisor-8.5', 0775, true);
file_put_contents($supRoot . '/compose/php-8.5.yml', "name: test\n");
file_put_contents($supRoot . '/logs/supervisor-8.5/supervisord.log', "ready\n");
$supervisorLogs = new SupervisorRuntime($supRoot, $supRoot);
$supervisorLogPath = $supervisorLogs->resolveLogPath('supervisor-8.5', 'supervisord.log');
assert_true(str_ends_with($supervisorLogPath, '/supervisord.log'), 'supervisor log path resolves');
try {
    $supervisorLogs->resolveLogPath('supervisor-8.5', '../compose/php-8.5.yml');
    assert_true(false, 'supervisor log rejects traversal');
} catch (HttpException $e) {
    assert_true($e->errorKey() === 'supervisor.invalid_log', 'supervisor log rejects traversal');
}

$followFile = sys_get_temp_dir() . '/mgr-follow-' . bin2hex(random_bytes(4)) . '.log';
file_put_contents($followFile, "one\n");
$followLogs = new SourceLogs();
$followStat = stat($followFile);
$followInode = (int) ($followStat['ino'] ?? 0);
$followOffset = (int) ($followStat['size'] ?? 0);
$idle = $followLogs->readFollowChunk($followFile, $followOffset, $followInode);
assert_true(($idle['event'] ?? '') === 'append' && ($idle['content'] ?? 'x') === '', 'follow idle sends no bytes');
file_put_contents($followFile, "two\n", FILE_APPEND);
$appended = $followLogs->readFollowChunk($followFile, $followOffset, $followInode);
assert_true(($appended['content'] ?? '') === "two\n", 'follow appends new bytes');
file_put_contents($followFile, 'x');
$reset = $followLogs->readFollowChunk($followFile, 100, $followInode);
assert_true(($reset['event'] ?? '') === 'reset' && ($reset['content'] ?? '') === 'x', 'follow reset after truncate');
unlink($followFile);
$gone = $followLogs->readFollowChunk($followFile, 0, $followInode);
assert_true(($gone['event'] ?? '') === 'gone', 'follow ends when file disappears');

echo "All checks passed\n";
