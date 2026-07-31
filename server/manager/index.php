<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/lib.php';

$envPath = getenv('MANAGER_ENV_PATH') ?: '/data/env.json';
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
    $servers = manager_load_servers($envPath);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            throw new RuntimeException('Invalid CSRF token. Refresh the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete') {
            $key = (string) ($_POST['key'] ?? '');
            if (!preg_match('/^SERVER_NAME\d+$/', $key) || !isset($servers[$key])) {
                throw new RuntimeException('The selected server no longer exists.');
            }
            unset($servers[$key]);
            manager_save_servers($envPath, $servers);
            $_SESSION['flash'] = 'Server deleted. Restart Nginx to apply the change.';
            header('Location: /');
            exit;
        }

        if ($action === 'save') {
            $editingKey = (string) ($_POST['key'] ?? '');
            if ($editingKey === '') {
                $editingKey = null;
            } elseif (!preg_match('/^SERVER_NAME\d+$/', $editingKey) || !isset($servers[$editingKey])) {
                throw new RuntimeException('The selected server no longer exists.');
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
                manager_save_servers($envPath, $servers);
                $_SESSION['flash'] = $editingKey === null
                    ? 'Server added. Restart Nginx to apply the change.'
                    : 'Server updated. Restart Nginx to apply the change.';
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
$profiles = manager_required_profiles($servers);
$profileFlags = implode(' ', array_map(static fn (string $profile): string => '--profile ' . $profile, $profiles));
$applyCommand = 'docker compose' . ($profileFlags !== '' ? ' ' . $profileFlags : '') . ' up -d' . PHP_EOL
    . 'docker compose restart nginx';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Multi-PHP Server Manager</title>
    <style>
        :root { color-scheme: dark; --bg:#09111f; --panel:#111c2e; --line:#263752; --text:#e8eef8; --muted:#9dafc8; --blue:#58a6ff; --green:#3ddc97; --red:#ff6b7a; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:Inter,ui-sans-serif,system-ui,-apple-system,sans-serif; background:radial-gradient(circle at top left,#14284a 0,#09111f 42%); color:var(--text); min-height:100vh; }
        a { color:var(--blue); text-decoration:none; }
        .shell { width:min(1240px,calc(100% - 32px)); margin:0 auto; padding:36px 0 64px; }
        header { display:flex; justify-content:space-between; gap:20px; align-items:end; margin-bottom:24px; }
        h1 { font-size:clamp(28px,4vw,44px); margin:0 0 6px; letter-spacing:-.04em; }
        h2 { margin:0 0 18px; font-size:20px; }
        p { color:var(--muted); margin:0; }
        .badge { border:1px solid #31557e; background:#142d4d; color:#9dceff; border-radius:999px; padding:7px 11px; font-size:13px; white-space:nowrap; }
        .grid { display:grid; grid-template-columns:minmax(0,1.6fr) minmax(320px,.8fr); gap:20px; align-items:start; }
        .panel { background:rgba(17,28,46,.94); border:1px solid var(--line); border-radius:16px; box-shadow:0 20px 50px rgba(0,0,0,.22); overflow:hidden; }
        .panel-body { padding:22px; }
        .table-wrap { overflow:auto; }
        table { width:100%; border-collapse:collapse; min-width:760px; }
        th,td { padding:14px 16px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        th { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.08em; background:#0d1728; }
        td { font-size:14px; }
        tr:last-child td { border-bottom:0; }
        code { font-family:"SFMono-Regular",Consolas,monospace; font-size:12px; color:#b9d8ff; }
        .actions { display:flex; gap:8px; }
        button,.button { appearance:none; border:1px solid #365273; background:#172842; color:var(--text); padding:9px 12px; border-radius:9px; cursor:pointer; font-weight:650; font-size:13px; }
        button:hover,.button:hover { border-color:var(--blue); }
        .primary { background:#1677d2; border-color:#268bea; }
        .danger { color:#ffb1b8; border-color:#713745; background:#301a24; }
        label { display:block; margin:14px 0 7px; color:#c9d6e8; font-size:13px; font-weight:700; }
        input,select { width:100%; background:#0b1525; border:1px solid #30425f; color:var(--text); border-radius:9px; padding:11px 12px; outline:none; }
        input:focus,select:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(88,166,255,.13); }
        .error { color:#ff9ca8; font-size:12px; margin-top:6px; }
        .notice { padding:13px 15px; border-radius:10px; margin-bottom:18px; border:1px solid; }
        .success { color:#b5f5d9; border-color:#28664f; background:#12372d; }
        .failure { color:#ffc1c7; border-color:#753744; background:#3a1d27; }
        .command { margin-top:20px; background:#07101d; border:1px solid var(--line); border-radius:12px; padding:15px; white-space:pre-wrap; color:#b9f6dc; font:13px/1.6 "SFMono-Regular",Consolas,monospace; }
        .empty { padding:34px; text-align:center; color:var(--muted); }
        .form-actions { display:flex; gap:10px; margin-top:20px; }
        @media (max-width:900px) { .grid { grid-template-columns:1fr; } header { align-items:start; flex-direction:column; } }
    </style>
</head>
<body>
<main class="shell">
    <header>
        <div><h1>Server Manager</h1><p>Manage local Nginx virtual servers stored in env.json.</p></div>
        <span class="badge">Local access only · 127.0.0.1:8080</span>
    </header>

    <?php if ($flash): ?><div class="notice success"><?= e((string) $flash) ?></div><?php endif; ?>
    <?php if ($fatalError): ?><div class="notice failure"><?= e($fatalError) ?></div><?php endif; ?>

    <div class="grid">
        <section class="panel">
            <div class="panel-body"><h2>Configured servers <span class="badge"><?= count($servers) ?></span></h2></div>
            <div class="table-wrap">
                <?php if ($servers === []): ?><div class="empty">No servers configured yet.</div><?php else: ?>
                <table>
                    <thead><tr><th>App / domain</th><th>PHP</th><th>Document root</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($servers as $key => $server): $version = manager_version_from_container((string) ($server['CONTAINER_PHP_VERSION'] ?? '')); ?>
                        <tr>
                            <td><strong><?= e((string) ($server['APP_NAME'] ?? '')) ?></strong><br><a href="http://<?= e((string) ($server['DOMAIN_NAME'] ?? '')) ?>" target="_blank" rel="noreferrer"><?= e((string) ($server['DOMAIN_NAME'] ?? '')) ?></a></td>
                            <td><span class="badge"><?= e($versions[$version]['label']) ?></span></td>
                            <td><code><?= e((string) ($server['SERVER_PATH'] ?? '')) ?></code></td>
                            <td><div class="actions"><a class="button" href="/?edit=<?= urlencode((string) $key) ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this server?')"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="key" value="<?= e((string) $key) ?>"><button class="danger" type="submit">Delete</button></form></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>

        <aside class="panel"><div class="panel-body">
            <h2><?= $editingKey ? 'Edit server' : 'Add server' ?></h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="key" value="<?= e((string) $editingKey) ?>">

                <label for="app_name">Application name</label>
                <input id="app_name" name="app_name" value="<?= e($form['app_name']) ?>" placeholder="my-app" required>
                <?php if (isset($errors['app_name'])): ?><div class="error"><?= e($errors['app_name']) ?></div><?php endif; ?>

                <label for="domain_name">Local domain</label>
                <input id="domain_name" name="domain_name" value="<?= e($form['domain_name']) ?>" placeholder="my-app.test" required>
                <?php if (isset($errors['domain_name'])): ?><div class="error"><?= e($errors['domain_name']) ?></div><?php endif; ?>

                <label for="php_version">PHP version</label>
                <select id="php_version" name="php_version">
                    <?php foreach ($versions as $version => $config): ?><option value="<?= e($version) ?>" data-prefix="<?= e($config['source_prefix']) ?>" <?= $form['php_version'] === $version ? 'selected' : '' ?>><?= e($config['label']) ?></option><?php endforeach; ?>
                </select>
                <?php if (isset($errors['php_version'])): ?><div class="error"><?= e($errors['php_version']) ?></div><?php endif; ?>

                <label for="server_path">Document root in container</label>
                <input id="server_path" name="server_path" value="<?= e($form['server_path']) ?>" placeholder="/var/www/source_php8.2/my-app/public" required>
                <?php if (isset($errors['server_path'])): ?><div class="error"><?= e($errors['server_path']) ?></div><?php endif; ?>

                <div class="form-actions"><button class="primary" type="submit"><?= $editingKey ? 'Save changes' : 'Add server' ?></button><?php if ($editingKey): ?><a class="button" href="/">Cancel</a><?php endif; ?></div>
            </form>

            <div class="command"><?= e($applyCommand) ?></div>
        </div></aside>
    </div>
</main>
<script>
const phpSelect = document.getElementById('php_version');
const pathInput = document.getElementById('server_path');
phpSelect.addEventListener('change', () => {
    const previousPrefix = Object.values(<?= json_encode(array_column($versions, 'source_prefix'), JSON_UNESCAPED_SLASHES) ?>).find(prefix => pathInput.value.startsWith(prefix));
    const nextPrefix = phpSelect.options[phpSelect.selectedIndex].dataset.prefix;
    pathInput.value = previousPrefix ? nextPrefix + pathInput.value.slice(previousPrefix.length) : nextPrefix + '/';
});
</script>
</body>
</html>
