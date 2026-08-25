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
    'server_path' => '/var/www/source_php8.5/app-one/public',
    'php_version' => 'php-8.5',
], []);
assert_true(($vOff['server']['SSL_ENABLED'] ?? true) === false, 'validate default ssl off');
assert_true(!isset($vOff['server']['SSL_MODE']), 'no ssl mode when off');

$vOn = $envSsl->validate([
    'app_name' => 'app-one',
    'domain_name' => 'app-one.test',
    'server_path' => '/var/www/source_php8.5/app-one/public',
    'php_version' => 'php-8.5',
    'ssl_enabled' => true,
], []);
assert_true($vOn['errors'] === [] && $vOn['server']['SSL_ENABLED'] === true, 'ssl_enabled on');
assert_true(($vOn['server']['SSL_MODE'] ?? '') === 'generated', 'default mode generated');

$vUp = $envSsl->validate([
    'app_name' => 'app-one',
    'domain_name' => 'app-one.test',
    'server_path' => '/var/www/source_php8.5/app-one/public',
    'php_version' => 'php-8.5',
    'ssl_enabled' => true,
    'ssl_certificate' => 'x',
    'ssl_private_key' => 'y',
], []);
assert_true(($vUp['server']['SSL_MODE'] ?? '') === 'uploaded', 'both pems set uploaded mode');

$vOne = $envSsl->validate([
    'app_name' => 'app-one',
    'domain_name' => 'app-one.test',
    'server_path' => '/var/www/source_php8.5/app-one/public',
    'php_version' => 'php-8.5',
    'ssl_enabled' => true,
    'ssl_certificate' => 'x',
], []);
assert_true(isset($vOne['errors']['ssl_private_key']), 'one pem requires key');

$existing = ['SERVER_NAME1' => $vOn['server']];
$vKeep = $envSsl->validate([
    'app_name' => 'app-one',
    'domain_name' => 'app-one.test',
    'server_path' => '/var/www/source_php8.5/app-one/public',
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
    'server_path' => '/var/www/source_php8.5/ctrl-app/public',
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

echo "All checks passed\n";
