<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/i18n.php';
require __DIR__ . '/lib.php';

$envPath = getenv('MANAGER_ENV_PATH') ?: '/var/host-project/env.json';
$runtimePath = getenv('MANAGER_RUNTIME_PATH') ?: '/runtime';
$phpControllerPath = getenv('MANAGER_PHP_CONTROLLER_PATH') ?: '/runtime/php-controller';
$locale = manager_locale($_SESSION, (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
$versions = manager_php_versions();
$errors = [];
$fatalError = null;
$editingKey = null;
$form = [
    'app_name' => '',
    'domain_name' => '',
    'server_path' => '/var/www/source_php8.2/',
    'php_version' => 'php-8.2',
];

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $servers = manager_load_servers($envPath, $locale);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            throw new RuntimeException(manager_translate($locale, 'error.invalid_csrf'));
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'set_locale') {
            $requestedLocale = (string) ($_POST['locale'] ?? '');
            if (in_array($requestedLocale, manager_supported_locales(), true)) {
                $_SESSION['locale'] = $requestedLocale;
            }
            $returnTo = (string) ($_POST['return_to'] ?? '/');
            if (preg_match('#^/(?:\?edit=SERVER_NAME\d+)?$#', $returnTo) !== 1) {
                $returnTo = '/';
            }
            header('Location: ' . $returnTo);
            exit;
        }

        if ($action === 'reload_nginx') {
            if (!is_dir($runtimePath) && !mkdir($runtimePath, 0775, true) && !is_dir($runtimePath)) {
                throw new RuntimeException(manager_translate($locale, 'error.runtime_directory'));
            }
            if (file_put_contents($runtimePath . '/nginx.reload', date(DATE_ATOM), LOCK_EX) === false) {
                throw new RuntimeException(manager_translate($locale, 'error.reload_request'));
            }
            $_SESSION['flash'] = manager_translate($locale, 'reload.requested');
            header('Location: /');
            exit;
        }

        if ($action === 'php_action') {
            $service = (string) ($_POST['service'] ?? '');
            $containerAction = (string) ($_POST['container_action'] ?? '');
            try {
                manager_request_php_action($phpControllerPath, $service, $containerAction);
            } catch (InvalidArgumentException | RuntimeException $controllerException) {
                $messageKey = $controllerException->getMessage();
                if (!str_starts_with($messageKey, 'php_controller.')) {
                    $messageKey = 'php_controller.request_failed';
                }
                throw new RuntimeException(manager_translate($locale, $messageKey));
            }
            $target = manager_php_controller_targets()[$service];
            $_SESSION['flash'] = manager_translate($locale, 'php_controller.requested', [
                'action' => manager_translate($locale, 'php_controller.' . $containerAction),
                'version' => $target['label'],
            ]);
            header('Location: /');
            exit;
        }

        if ($action === 'delete') {
            $key = (string) ($_POST['key'] ?? '');
            if (!preg_match('/^SERVER_NAME\d+$/', $key) || !isset($servers[$key])) {
                throw new RuntimeException(manager_translate($locale, 'error.server_missing'));
            }
            unset($servers[$key]);
            manager_save_servers($envPath, $servers, $locale);
            $_SESSION['flash'] = manager_translate($locale, 'flash.deleted');
            header('Location: /');
            exit;
        }

        if ($action === 'save') {
            $editingKey = (string) ($_POST['key'] ?? '');
            if ($editingKey === '') {
                $editingKey = null;
            } elseif (!preg_match('/^SERVER_NAME\d+$/', $editingKey) || !isset($servers[$editingKey])) {
                throw new RuntimeException(manager_translate($locale, 'error.server_missing'));
            }

            $form = [
                'app_name' => (string) ($_POST['app_name'] ?? ''),
                'domain_name' => (string) ($_POST['domain_name'] ?? ''),
                'server_path' => (string) ($_POST['server_path'] ?? ''),
                'php_version' => (string) ($_POST['php_version'] ?? ''),
            ];
            $validation = manager_validate_server($form, $servers, $editingKey);
            $errors = $validation['errors'];
            if ($errors === []) {
                $key = $editingKey ?? manager_next_key($servers);
                $servers[$key] = $validation['server'];
                manager_save_servers($envPath, $servers, $locale);
                $_SESSION['flash'] = $editingKey === null
                    ? manager_translate($locale, 'flash.added')
                    : manager_translate($locale, 'flash.updated');
                header('Location: /');
                exit;
            }
        }
    } elseif (isset($_GET['edit'])) {
        $editingKey = (string) $_GET['edit'];
        if (preg_match('/^SERVER_NAME\d+$/', $editingKey) && isset($servers[$editingKey])) {
            $server = $servers[$editingKey];
            $form = [
                'app_name' => (string) ($server['APP_NAME'] ?? ''),
                'domain_name' => (string) ($server['DOMAIN_NAME'] ?? ''),
                'server_path' => (string) ($server['SERVER_PATH'] ?? ''),
                'php_version' => manager_version_from_container((string) ($server['CONTAINER_PHP_VERSION'] ?? '')),
            ];
        } else {
            $editingKey = null;
        }
    }
} catch (Throwable $exception) {
    $servers = $servers ?? [];
    $fatalError = $exception->getMessage();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$reloadStatus = null;
$statusFile = $runtimePath . '/nginx.status.json';
if (is_file($statusFile) && is_readable($statusFile)) {
    $decodedStatus = json_decode((string) file_get_contents($statusFile), true);
    if (is_array($decodedStatus)) {
        $reloadStatus = $decodedStatus;
    }
}
$reloadMessageKeys = [
    'Nginx templates were generated and reloaded successfully.' => 'reload.status.generated',
    'Could not generate Nginx templates. See runtime/nginx.reload.log.' => 'reload.status.generate_failed',
    'Nginx configuration is invalid. Previous configuration was restored.' => 'reload.status.invalid',
    'Nginx could not reload. Previous configuration was restored.' => 'reload.status.failed',
];
$phpControllerTargets = manager_php_controller_targets();
$phpStatuses = manager_load_php_statuses($phpControllerPath);
$profiles = manager_required_profiles($servers);
$profileFlags = implode(' ', array_map(static fn (string $profile): string => '--profile ' . $profile, $profiles));
$applyCommand = 'docker compose' . ($profileFlags !== '' ? ' ' . $profileFlags : '') . ' up -d' . PHP_EOL
    . 'docker compose restart nginx';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validation_message(string $locale, array|string $error): string
{
    if (is_string($error)) {
        return $error;
    }
    return manager_translate(
        $locale,
        (string) ($error['key'] ?? ''),
        is_array($error['parameters'] ?? null) ? $error['parameters'] : []
    );
}

function version_label(string $locale, string $version, array $config): string
{
    return $version === 'php-8.2'
        ? 'PHP 8.2 (' . manager_translate($locale, 'php.default') . ')'
        : (string) $config['label'];
}
?>
<!doctype html>
<html lang="<?= e($locale) ?>" data-theme="dark" data-theme-mode="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(manager_translate($locale, 'page.title')) ?></title>
    <script>
        (() => {
            const allowed = ['system', 'light', 'dark'];
            let mode = 'system';
            try {
                const saved = localStorage.getItem('manager-theme');
                if (allowed.includes(saved)) mode = saved;
            } catch (_) {}
            const effective = mode === 'system'
                ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : mode;
            document.documentElement.dataset.theme = effective;
            document.documentElement.dataset.themeMode = mode;
        })();
    </script>
    <style>
        :root,[data-theme="dark"] { color-scheme:dark; --bg:#09111f; --bg-accent:#14284a; --panel:#111c2e; --panel-strong:#0d1728; --input:#0b1525; --command:#07101d; --line:#263752; --input-line:#30425f; --text:#e8eef8; --muted:#9dafc8; --blue:#58a6ff; --primary:#1677d2; --primary-line:#268bea; --badge-bg:#142d4d; --badge-line:#31557e; --badge-text:#9dceff; --button-bg:#172842; --button-line:#365273; --success-text:#b5f5d9; --success-line:#28664f; --success-bg:#12372d; --failure-text:#ffc1c7; --failure-line:#753744; --failure-bg:#3a1d27; --danger-text:#ffb1b8; --danger-line:#713745; --danger-bg:#301a24; --code:#b9d8ff; --command-text:#b9f6dc; --shadow:rgba(0,0,0,.22); }
        [data-theme="light"] { color-scheme:light; --bg:#f4f7fb; --bg-accent:#dbeafe; --panel:#ffffff; --panel-strong:#f2f6fc; --input:#ffffff; --command:#edf5ff; --line:#d5dfec; --input-line:#b7c5d8; --text:#172033; --muted:#5d6e85; --blue:#0969da; --primary:#0969da; --primary-line:#075fca; --badge-bg:#e9f3ff; --badge-line:#9bc5f5; --badge-text:#075fca; --button-bg:#f2f6fc; --button-line:#b7c5d8; --success-text:#17633f; --success-line:#7bc6a3; --success-bg:#e8f8f0; --failure-text:#9d2434; --failure-line:#e0a1aa; --failure-bg:#fff0f2; --danger-text:#a12838; --danger-line:#dc9ba5; --danger-bg:#fff0f2; --code:#195ca8; --command-text:#17633f; --shadow:rgba(33,54,84,.12); }
        * { box-sizing: border-box; }
        body { margin:0; font-family:Inter,ui-sans-serif,system-ui,-apple-system,sans-serif; background:radial-gradient(circle at top left,var(--bg-accent) 0,var(--bg) 42%); color:var(--text); min-height:100vh; }
        a { color:var(--blue); text-decoration:none; }
        .shell { width:min(1240px,calc(100% - 32px)); margin:0 auto; padding:36px 0 64px; }
        header { display:flex; justify-content:space-between; gap:20px; align-items:end; margin-bottom:24px; }
        h1 { font-size:clamp(28px,4vw,44px); margin:0 0 6px; letter-spacing:-.04em; }
        h2 { margin:0 0 18px; font-size:20px; }
        p { color:var(--muted); margin:0; }
        .header-actions,.switcher { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .switcher-label { color:var(--muted); font-size:12px; font-weight:700; }
        .badge { border:1px solid var(--badge-line); background:var(--badge-bg); color:var(--badge-text); border-radius:999px; padding:7px 11px; font-size:13px; white-space:nowrap; }
        .grid { display:grid; grid-template-columns:minmax(0,1.6fr) minmax(320px,.8fr); gap:20px; align-items:start; }
        .panel { background:var(--panel); border:1px solid var(--line); border-radius:16px; box-shadow:0 20px 50px var(--shadow); overflow:hidden; }
        .panel-body { padding:22px; }
        .panel-heading { padding:22px; }
        .panel-heading-row { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; }
        .panel-heading-row h2 { margin:0; }
        .panel-heading-actions { display:flex; align-items:center; gap:10px; }
        .table-wrap { overflow:auto; }
        table { width:100%; border-collapse:collapse; min-width:760px; }
        th,td { padding:14px 16px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        th { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.08em; background:var(--panel-strong); }
        td { font-size:14px; }
        tr:last-child td { border-bottom:0; }
        code { font-family:"SFMono-Regular",Consolas,monospace; font-size:12px; color:var(--code); }
        .actions { display:flex; gap:8px; }
        button,.button,select { appearance:none; border:1px solid var(--button-line); background:var(--button-bg); color:var(--text); padding:9px 12px; border-radius:9px; cursor:pointer; font-weight:650; font-size:13px; }
        button:hover,.button:hover { border-color:var(--blue); }
        button:disabled { cursor:not-allowed; opacity:.42; border-color:var(--button-line); }
        .primary { color:#fff; background:var(--primary); border-color:var(--primary-line); }
        .danger { color:var(--danger-text); border-color:var(--danger-line); background:var(--danger-bg); }
        label { display:block; margin:14px 0 7px; color:#c9d6e8; font-size:13px; font-weight:700; }
        input,select { width:100%; background:var(--input); border:1px solid var(--input-line); color:var(--text); border-radius:9px; padding:11px 12px; outline:none; }
        .switcher select { width:auto; padding:7px 30px 7px 10px; }
        .locale-form { display:flex; gap:4px; }
        .locale-form button { padding:7px 9px; }
        .locale-form button[aria-current="true"] { color:#fff; background:var(--primary); border-color:var(--primary-line); }
        input:focus,select:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(88,166,255,.13); }
        .error { color:var(--failure-text); font-size:12px; margin-top:6px; }
        .notice { padding:13px 15px; border-radius:10px; margin-bottom:18px; border:1px solid; }
        .success { color:var(--success-text); border-color:var(--success-line); background:var(--success-bg); }
        .failure { color:var(--failure-text); border-color:var(--failure-line); background:var(--failure-bg); }
        .command { margin-top:20px; background:var(--command); border:1px solid var(--line); border-radius:12px; padding:15px; white-space:pre-wrap; color:var(--command-text); font:13px/1.6 "SFMono-Regular",Consolas,monospace; }
        .empty { padding:34px; text-align:center; color:var(--muted); }
        .form-actions { display:flex; gap:10px; margin-top:20px; }
        .status-line { margin-top:10px; font-size:12px; color:var(--muted); line-height:1.5; }
        .controller-panel { margin-top:20px; }
        .controller-heading { display:flex; justify-content:space-between; gap:16px; align-items:end; }
        .controller-heading h2 { margin:0 0 5px; }
        .state-badge { display:inline-flex; align-items:center; gap:7px; padding:6px 9px; border:1px solid var(--badge-line); border-radius:999px; background:var(--badge-bg); color:var(--badge-text); font-size:12px; font-weight:800; white-space:nowrap; }
        .state-badge::before { content:""; width:7px; height:7px; border-radius:50%; background:currentColor; }
        .state-running { color:var(--success-text); border-color:var(--success-line); background:var(--success-bg); }
        .state-error { color:var(--failure-text); border-color:var(--failure-line); background:var(--failure-bg); }
        .controller-actions { display:flex; flex-wrap:wrap; gap:7px; }
        .controller-actions form { margin:0; }
        .controller-message,.create-hint { margin-top:8px; color:var(--muted); font-size:12px; line-height:1.5; }
        .create-hint code { display:block; margin-top:5px; padding:7px 9px; border-radius:7px; background:var(--command); color:var(--command-text); }
        @media (max-width:900px) { .grid { grid-template-columns:1fr; } header { align-items:start; flex-direction:column; } .header-actions { align-items:flex-start; } .panel-heading-row { align-items:flex-start; flex-direction:column; } }
    </style>
</head>
<body>
<main class="shell">
    <header>
        <div><h1><?= e(manager_translate($locale, 'header.title')) ?></h1><p><?= e(manager_translate($locale, 'header.subtitle')) ?></p></div>
        <div class="header-actions">
            <span class="badge"><?= e(manager_translate($locale, 'header.local_only')) ?></span>
            <div class="switcher">
                <span class="switcher-label"><?= e(manager_translate($locale, 'language.label')) ?></span>
                <form class="locale-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="set_locale">
                    <input type="hidden" name="return_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/')) ?>">
                    <button type="submit" name="locale" value="vi" aria-current="<?= $locale === 'vi' ? 'true' : 'false' ?>">VI</button>
                    <button type="submit" name="locale" value="en" aria-current="<?= $locale === 'en' ? 'true' : 'false' ?>">EN</button>
                </form>
            </div>
            <div class="switcher">
                <label class="switcher-label" for="theme_mode"><?= e(manager_translate($locale, 'theme.label')) ?></label>
                <select id="theme_mode">
                    <option value="system"><?= e(manager_translate($locale, 'theme.system')) ?></option>
                    <option value="light"><?= e(manager_translate($locale, 'theme.light')) ?></option>
                    <option value="dark"><?= e(manager_translate($locale, 'theme.dark')) ?></option>
                </select>
            </div>
        </div>
    </header>

    <?php if ($flash): ?><div class="notice success"><?= e((string) $flash) ?></div><?php endif; ?>
    <?php if ($fatalError): ?><div class="notice failure"><?= e($fatalError) ?></div><?php endif; ?>

    <div class="grid">
        <section class="panel">
            <div class="panel-heading">
                <div class="panel-heading-row">
                    <h2><?= e(manager_translate($locale, 'servers.title')) ?> <span class="badge"><?= count($servers) ?></span></h2>
                    <div class="panel-heading-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="reload_nginx">
                            <button class="primary" type="submit"><?= e(manager_translate($locale, 'reload.button')) ?></button>
                        </form>
                    </div>
                </div>
                <?php if ($reloadStatus): ?>
                    <div class="status-line">
                        <strong><?= e(manager_translate($locale, ($reloadStatus['status'] ?? '') === 'success' ? 'reload.success' : 'reload.error')) ?>:</strong>
                        <?php $reloadMessage = (string) ($reloadStatus['message'] ?? ''); ?>
                        <?= e(isset($reloadMessageKeys[$reloadMessage]) ? manager_translate($locale, $reloadMessageKeys[$reloadMessage]) : ($reloadMessage !== '' ? $reloadMessage : manager_translate($locale, 'reload.unknown'))) ?><br>
                        <?= e((string) ($reloadStatus['updated_at'] ?? '')) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <?php if ($servers === []): ?><div class="empty"><?= e(manager_translate($locale, 'servers.empty')) ?></div><?php else: ?>
                <table>
                    <thead><tr><th><?= e(manager_translate($locale, 'table.app_domain')) ?></th><th><?= e(manager_translate($locale, 'table.php')) ?></th><th><?= e(manager_translate($locale, 'table.document_root')) ?></th><th><?= e(manager_translate($locale, 'table.actions')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($servers as $key => $server): $version = manager_version_from_container((string) ($server['CONTAINER_PHP_VERSION'] ?? '')); ?>
                        <tr>
                            <td><strong><?= e((string) ($server['APP_NAME'] ?? '')) ?></strong><br><a href="http://<?= e((string) ($server['DOMAIN_NAME'] ?? '')) ?>" target="_blank" rel="noreferrer"><?= e((string) ($server['DOMAIN_NAME'] ?? '')) ?></a></td>
                            <td><span class="badge"><?= e(version_label($locale, $version, $versions[$version])) ?></span></td>
                            <td><code><?= e((string) ($server['SERVER_PATH'] ?? '')) ?></code></td>
                            <td><div class="actions"><a class="button" href="/?edit=<?= urlencode((string) $key) ?>"><?= e(manager_translate($locale, 'action.edit')) ?></a><form method="post" onsubmit="return confirm(<?= e(json_encode(manager_translate($locale, 'confirm.delete'), JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="key" value="<?= e((string) $key) ?>"><button class="danger" type="submit"><?= e(manager_translate($locale, 'action.delete')) ?></button></form></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>

        <aside class="panel"><div class="panel-body">
            <h2><?= e(manager_translate($locale, $editingKey ? 'form.edit_title' : 'form.add_title')) ?></h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="key" value="<?= e((string) $editingKey) ?>">

                <label for="app_name"><?= e(manager_translate($locale, 'form.app_name')) ?></label>
                <input id="app_name" name="app_name" value="<?= e($form['app_name']) ?>" placeholder="<?= e(manager_translate($locale, 'form.app_placeholder')) ?>" required>
                <?php if (isset($errors['app_name'])): ?><div class="error"><?= e(validation_message($locale, $errors['app_name'])) ?></div><?php endif; ?>

                <label for="domain_name"><?= e(manager_translate($locale, 'form.domain')) ?></label>
                <input id="domain_name" name="domain_name" value="<?= e($form['domain_name']) ?>" placeholder="<?= e(manager_translate($locale, 'form.domain_placeholder')) ?>" required>
                <?php if (isset($errors['domain_name'])): ?><div class="error"><?= e(validation_message($locale, $errors['domain_name'])) ?></div><?php endif; ?>

                <label for="php_version"><?= e(manager_translate($locale, 'form.php_version')) ?></label>
                <select id="php_version" name="php_version">
                    <?php foreach ($versions as $version => $config): ?><option value="<?= e($version) ?>" data-prefix="<?= e($config['source_prefix']) ?>" <?= $form['php_version'] === $version ? 'selected' : '' ?>><?= e(version_label($locale, $version, $config)) ?></option><?php endforeach; ?>
                </select>
                <?php if (isset($errors['php_version'])): ?><div class="error"><?= e(validation_message($locale, $errors['php_version'])) ?></div><?php endif; ?>

                <label for="server_path"><?= e(manager_translate($locale, 'form.server_path')) ?></label>
                <input id="server_path" name="server_path" value="<?= e($form['server_path']) ?>" placeholder="<?= e(manager_translate($locale, 'form.path_placeholder')) ?>" required>
                <?php if (isset($errors['server_path'])): ?><div class="error"><?= e(validation_message($locale, $errors['server_path'])) ?></div><?php endif; ?>

                <div class="form-actions"><button class="primary" type="submit"><?= e(manager_translate($locale, $editingKey ? 'form.save' : 'form.add')) ?></button><?php if ($editingKey): ?><a class="button" href="/"><?= e(manager_translate($locale, 'action.cancel')) ?></a><?php endif; ?></div>
            </form>

            <div class="command"><?= e($applyCommand) ?></div>
        </div></aside>
    </div>

    <section class="panel controller-panel">
        <div class="panel-body controller-heading">
            <div>
                <h2><?= e(manager_translate($locale, 'php_controller.title')) ?></h2>
                <p><?= e(manager_translate($locale, 'php_controller.subtitle')) ?></p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th><?= e(manager_translate($locale, 'php_controller.version')) ?></th>
                    <th><?= e(manager_translate($locale, 'php_controller.container')) ?></th>
                    <th><?= e(manager_translate($locale, 'php_controller.profile')) ?></th>
                    <th><?= e(manager_translate($locale, 'php_controller.state')) ?></th>
                    <th><?= e(manager_translate($locale, 'php_controller.actions')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($phpControllerTargets as $service => $target):
                    $status = $phpStatuses[$service];
                    $state = (string) $status['state'];
                    $stateKey = 'php_controller.state_' . $state;
                    $messageKey = (string) ($status['message_key'] ?? 'php_controller.status_unavailable');
                ?>
                    <tr>
                        <td><strong><?= e($target['label']) ?></strong><br><code><?= e($service) ?></code></td>
                        <td><code><?= e($target['container']) ?></code></td>
                        <td><?= e($target['profile'] === null ? manager_translate($locale, 'php_controller.default_profile') : $target['profile']) ?></td>
                        <td>
                            <span class="state-badge state-<?= e($state) ?>"><?= e(manager_translate($locale, $stateKey)) ?></span>
                            <div class="controller-message"><?= e(manager_translate($locale, $messageKey)) ?><br><?= e((string) ($status['updated_at'] ?? '')) ?></div>
                            <?php if ($state === 'not_created' && $target['profile'] !== null): ?>
                                <div class="create-hint"><?= e(manager_translate($locale, 'php_controller.create_hint')) ?><code><?= e($target['create_command']) ?></code></div>
                            <?php endif; ?>
                        </td>
                        <td><div class="controller-actions">
                            <?php foreach (['start', 'stop', 'restart'] as $containerAction):
                                $enabled = ($containerAction === 'start' && $state === 'stopped')
                                    || (in_array($containerAction, ['stop', 'restart'], true) && $state === 'running');
                            ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="php_action">
                                    <input type="hidden" name="service" value="<?= e($service) ?>">
                                    <input type="hidden" name="container_action" value="<?= e($containerAction) ?>">
                                    <button type="submit" <?= $enabled ? '' : 'disabled' ?>><?= e(manager_translate($locale, 'php_controller.' . $containerAction)) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
const phpSelect = document.getElementById('php_version');
const pathInput = document.getElementById('server_path');
phpSelect.addEventListener('change', () => {
    const previousPrefix = Object.values(<?= json_encode(array_column($versions, 'source_prefix'), JSON_UNESCAPED_SLASHES) ?>).find(prefix => pathInput.value.startsWith(prefix));
    const nextPrefix = phpSelect.options[phpSelect.selectedIndex].dataset.prefix;
    pathInput.value = previousPrefix ? nextPrefix + pathInput.value.slice(previousPrefix.length) : nextPrefix + '/';
});

const themeSelect = document.getElementById('theme_mode');
const colorScheme = matchMedia('(prefers-color-scheme: dark)');
const allowedThemes = ['system', 'light', 'dark'];

function storedTheme() {
    try {
        const value = localStorage.getItem('manager-theme');
        return allowedThemes.includes(value) ? value : 'system';
    } catch (_) {
        return 'system';
    }
}

function applyTheme(mode) {
    const safeMode = allowedThemes.includes(mode) ? mode : 'system';
    const effectiveTheme = safeMode === 'system' ? (colorScheme.matches ? 'dark' : 'light') : safeMode;
    document.documentElement.dataset.themeMode = safeMode;
    document.documentElement.dataset.theme = effectiveTheme;
    themeSelect.value = safeMode;
}

themeSelect.addEventListener('change', () => {
    const mode = allowedThemes.includes(themeSelect.value) ? themeSelect.value : 'system';
    try {
        localStorage.setItem('manager-theme', mode);
    } catch (_) {}
    applyTheme(mode);
});

colorScheme.addEventListener('change', () => {
    if (storedTheme() === 'system') applyTheme('system');
});

applyTheme(storedTheme());
</script>
</body>
</html>
