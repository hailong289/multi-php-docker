<?php

declare(strict_types=1);

if (($_GET['q'] ?? '') === 'info') {
    phpinfo();
    exit;
}

$supportedLocales = ['vi', 'en'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLocales, true)) {
    setcookie('tool_locale', $_GET['lang'], [
        'expires' => time() + 31536000,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Location: /', true, 303);
    exit;
}

$cookieLocale = (string) ($_COOKIE['tool_locale'] ?? '');
$locale = in_array($cookieLocale, $supportedLocales, true)
    ? $cookieLocale
    : (preg_match('/^\s*vi(?:[-_,;]|$)/i', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) === 1 ? 'vi' : 'en');

$translations = [
    'en' => [
        'nav.tagline' => 'Local development environment',
        'hero.eyebrow' => 'Docker-powered PHP workspace',
        'hero.title' => 'Build across PHP versions, without rebuilding your workflow.',
        'hero.description' => 'A ready-to-use local stack with Nginx, multiple PHP runtimes, databases, queues, Supervisor, and a visual Server Manager.',
        'hero.manager' => 'Open Server Manager',
        'hero.rabbitmq' => 'RabbitMQ Management',
        'hero.phpinfo' => 'View PHP Info',
        'hero.running' => 'Environment online',
        'hero.php' => 'Current runtime',
        'services.eyebrow' => 'Included services',
        'services.title' => 'Everything your PHP projects need',
        'service.nginx' => 'Virtual hosts generated from env.json and reloaded safely.',
        'service.php' => 'PHP 8.2 by default, with optional 8.1, 8.0, and 7.4 profiles.',
        'service.mysql' => 'Persistent relational database for local applications.',
        'service.redis' => 'Fast cache, session storage, and queue backend.',
        'service.rabbitmq' => 'Message broker with a dedicated management dashboard.',
        'service.supervisor' => 'Long-running workers managed in a separate container.',
        'service.manager' => 'Add, edit, delete, and reload local virtual servers visually.',
        'quick.eyebrow' => 'Quick start',
        'quick.title' => 'From source code to local domain in three steps',
        'quick.one.title' => 'Configure a server',
        'quick.one.text' => 'Open Server Manager, choose a PHP version, and enter the project document root.',
        'quick.two.title' => 'Register the hostname',
        'quick.two.text' => 'Run ./scripts/hosts/add_hostname.sh to map every configured .test domain to 127.0.0.1.',
        'quick.three.title' => 'Open your project',
        'quick.three.text' => 'Apply the Nginx configuration and visit the new local domain in your browser.',
        'runtime.eyebrow' => 'Live runtime',
        'runtime.title' => 'This request is served by',
        'runtime.server' => 'Web server',
        'runtime.php' => 'PHP version',
        'runtime.root' => 'Document root',
        'footer.text' => 'Multi-PHP Docker · Local development made predictable.',
    ],
    'vi' => [
        'nav.tagline' => 'Môi trường phát triển local',
        'hero.eyebrow' => 'Không gian PHP vận hành bằng Docker',
        'hero.title' => 'Phát triển trên nhiều phiên bản PHP mà không phải dựng lại quy trình.',
        'hero.description' => 'Bộ công cụ local sẵn dùng gồm Nginx, nhiều PHP runtime, cơ sở dữ liệu, queue, Supervisor và Server Manager trực quan.',
        'hero.manager' => 'Mở Server Manager',
        'hero.rabbitmq' => 'Quản lý RabbitMQ',
        'hero.phpinfo' => 'Xem PHP Info',
        'hero.running' => 'Môi trường đang hoạt động',
        'hero.php' => 'Runtime hiện tại',
        'services.eyebrow' => 'Dịch vụ tích hợp',
        'services.title' => 'Đầy đủ thành phần cho dự án PHP',
        'service.nginx' => 'Tạo virtual host từ env.json và tải lại cấu hình an toàn.',
        'service.php' => 'Mặc định PHP 8.2, hỗ trợ thêm profile 8.1, 8.0 và 7.4.',
        'service.mysql' => 'Cơ sở dữ liệu quan hệ có lưu trữ bền vững cho ứng dụng local.',
        'service.redis' => 'Cache tốc độ cao, lưu session và xử lý queue.',
        'service.rabbitmq' => 'Message broker kèm giao diện quản trị riêng.',
        'service.supervisor' => 'Quản lý worker chạy dài hạn trong container riêng.',
        'service.manager' => 'Thêm, sửa, xóa và tải lại virtual server bằng giao diện.',
        'quick.eyebrow' => 'Bắt đầu nhanh',
        'quick.title' => 'Từ source code đến domain local trong ba bước',
        'quick.one.title' => 'Cấu hình server',
        'quick.one.text' => 'Mở Server Manager, chọn phiên bản PHP và nhập document root của project.',
        'quick.two.title' => 'Đăng ký hostname',
        'quick.two.text' => 'Chạy ./scripts/hosts/add_hostname.sh để ánh xạ các domain .test đã cấu hình về 127.0.0.1.',
        'quick.three.title' => 'Mở project',
        'quick.three.text' => 'Áp dụng cấu hình Nginx rồi truy cập domain local mới trong trình duyệt.',
        'runtime.eyebrow' => 'Runtime trực tiếp',
        'runtime.title' => 'Request này được phục vụ bởi',
        'runtime.server' => 'Web server',
        'runtime.php' => 'Phiên bản PHP',
        'runtime.root' => 'Document root',
        'footer.text' => 'Multi-PHP Docker · Phát triển local ổn định và dễ dự đoán.',
    ],
];

$t = static fn (string $key): string => $translations[$locale][$key] ?? $translations['en'][$key] ?? $key;
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1')) ?: '127.0.0.1';
$rabbitMqUrl = 'http://' . $host . ':15672';
$services = [
    ['code' => 'NG', 'name' => 'Nginx', 'meta' => '80 · 443', 'text' => 'service.nginx'],
    ['code' => 'PHP', 'name' => 'PHP-FPM', 'meta' => '8.2 · 8.1 · 8.0 · 7.4', 'text' => 'service.php'],
    ['code' => 'DB', 'name' => 'MySQL', 'meta' => '3306', 'text' => 'service.mysql'],
    ['code' => 'RD', 'name' => 'Redis', 'meta' => '6379', 'text' => 'service.redis'],
    ['code' => 'MQ', 'name' => 'RabbitMQ', 'meta' => '5672 · 15672', 'text' => 'service.rabbitmq'],
    ['code' => 'SV', 'name' => 'Supervisor', 'meta' => 'Workers', 'text' => 'service.supervisor'],
    ['code' => 'UI', 'name' => 'Server Manager', 'meta' => '8080', 'text' => 'service.manager'],
];
?>
<!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Multi-PHP Docker local development environment">
    <title>Multi-PHP Docker</title>
    <style>
        :root { color-scheme:light; --bg:#f5f7fb; --surface:#fff; --surface-soft:#eef4ff; --line:#dbe3ef; --text:#152033; --muted:#607089; --primary:#1268d4; --primary-hover:#0c55b1; --accent:#18a978; --accent-soft:#ddf7ec; --shadow:rgba(26,48,82,.12); --code:#e8eef8; }
        @media (prefers-color-scheme:dark) { :root { color-scheme:dark; --bg:#08111f; --surface:#101c2e; --surface-soft:#142641; --line:#263954; --text:#edf3fc; --muted:#9dafc7; --primary:#58a6ff; --primary-hover:#80bbff; --accent:#3ddc97; --accent-soft:#12382d; --shadow:rgba(0,0,0,.28); --code:#0a1526; } }
        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body { margin:0; min-height:100vh; font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:radial-gradient(circle at 12% 0,var(--surface-soft),transparent 34rem),var(--bg); color:var(--text); }
        a { color:inherit; }
        a:focus-visible { outline:3px solid var(--primary); outline-offset:3px; }
        .shell { width:min(1160px,calc(100% - 32px)); margin:0 auto; }
        nav { min-height:76px; display:flex; align-items:center; justify-content:space-between; gap:18px; }
        .brand { display:flex; align-items:center; gap:12px; text-decoration:none; }
        .brand-mark { display:grid; place-items:center; width:40px; height:40px; border-radius:12px; color:#fff; background:linear-gradient(135deg,var(--primary),var(--accent)); font-weight:850; box-shadow:0 10px 24px var(--shadow); }
        .brand strong,.brand small { display:block; }
        .brand small { margin-top:2px; color:var(--muted); font-size:12px; }
        .language { display:flex; gap:5px; padding:4px; border:1px solid var(--line); border-radius:11px; background:var(--surface); }
        .language a { padding:7px 9px; border-radius:7px; color:var(--muted); text-decoration:none; font-size:13px; font-weight:800; }
        .language a.active { color:#fff; background:var(--primary); }
        .hero { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(280px,.65fr); gap:48px; align-items:center; padding:76px 0 86px; }
        .eyebrow { margin:0 0 14px; color:var(--primary); font-size:12px; font-weight:850; letter-spacing:.13em; text-transform:uppercase; }
        h1 { max-width:800px; margin:0; font-size:clamp(42px,6vw,72px); line-height:1.02; letter-spacing:-.055em; }
        .lead { max-width:730px; margin:24px 0 0; color:var(--muted); font-size:clamp(17px,2vw,20px); line-height:1.7; }
        .actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:30px; }
        .button { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 16px; border:1px solid var(--line); border-radius:11px; background:var(--surface); color:var(--text); text-decoration:none; font-size:14px; font-weight:780; box-shadow:0 8px 24px var(--shadow); transition:transform .18s ease,border-color .18s ease; }
        .button:hover { transform:translateY(-2px); border-color:var(--primary); }
        .button.primary { border-color:var(--primary); background:var(--primary); color:#fff; }
        .runtime-card { padding:25px; border:1px solid var(--line); border-radius:20px; background:var(--surface); box-shadow:0 24px 70px var(--shadow); }
        .online { display:inline-flex; align-items:center; gap:8px; padding:7px 10px; border-radius:999px; color:var(--accent); background:var(--accent-soft); font-size:12px; font-weight:800; }
        .online::before { content:""; width:8px; height:8px; border-radius:50%; background:currentColor; box-shadow:0 0 0 4px color-mix(in srgb,var(--accent) 18%,transparent); }
        .runtime-card h2 { margin:22px 0 6px; font-size:38px; letter-spacing:-.04em; }
        .runtime-card p { margin:0; color:var(--muted); }
        .terminal { margin-top:22px; padding:15px; border-radius:12px; background:var(--code); font:12px/1.75 "SFMono-Regular",Consolas,monospace; color:var(--muted); overflow-wrap:anywhere; }
        section { padding:70px 0; }
        .section-title { max-width:700px; margin:0 0 30px; font-size:clamp(30px,4vw,46px); line-height:1.1; letter-spacing:-.04em; }
        .service-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:15px; }
        .card { min-height:190px; padding:22px; border:1px solid var(--line); border-radius:16px; background:var(--surface); box-shadow:0 12px 34px var(--shadow); }
        .card-top { display:flex; justify-content:space-between; gap:12px; align-items:start; }
        .service-code { display:grid; place-items:center; width:42px; height:42px; border-radius:12px; color:var(--primary); background:var(--surface-soft); font-size:12px; font-weight:900; }
        .meta { color:var(--muted); font:11px/1.4 "SFMono-Regular",Consolas,monospace; }
        .card h3 { margin:22px 0 8px; font-size:18px; }
        .card p { margin:0; color:var(--muted); line-height:1.65; font-size:14px; }
        .steps { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:15px; counter-reset:steps; }
        .step { position:relative; padding:25px; border-top:3px solid var(--primary); border-radius:4px 4px 16px 16px; background:var(--surface); box-shadow:0 12px 34px var(--shadow); counter-increment:steps; }
        .step::before { content:"0" counter(steps); color:var(--primary); font-size:13px; font-weight:900; }
        .step h3 { margin:30px 0 10px; font-size:20px; }
        .step p { margin:0; color:var(--muted); line-height:1.65; }
        .step code { display:block; margin-top:14px; padding:10px; border-radius:8px; background:var(--code); color:var(--text); font-size:11px; overflow-wrap:anywhere; }
        .runtime { display:grid; grid-template-columns:.7fr 1.3fr; gap:30px; align-items:start; padding:30px; border:1px solid var(--line); border-radius:20px; background:var(--surface); box-shadow:0 18px 50px var(--shadow); }
        .runtime h2 { margin:0; font-size:clamp(28px,4vw,42px); letter-spacing:-.04em; }
        .runtime-list { display:grid; gap:12px; }
        .runtime-row { display:grid; grid-template-columns:130px 1fr; gap:18px; padding:13px 0; border-bottom:1px solid var(--line); }
        .runtime-row:last-child { border:0; }
        .runtime-row span { color:var(--muted); font-size:13px; }
        .runtime-row code { font:13px/1.5 "SFMono-Regular",Consolas,monospace; overflow-wrap:anywhere; }
        footer { padding:42px 0; color:var(--muted); text-align:center; font-size:13px; }
        @media (max-width:900px) { .hero { grid-template-columns:1fr; padding:55px 0 65px; } .runtime-card { max-width:520px; } .service-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .runtime { grid-template-columns:1fr; } }
        @media (max-width:620px) { nav { align-items:flex-start; padding:16px 0; } .brand small { display:none; } .hero { gap:30px; } .actions .button { width:100%; } .service-grid,.steps { grid-template-columns:1fr; } section { padding:52px 0; } .runtime-row { grid-template-columns:1fr; gap:5px; } }
    </style>
</head>
<body>
<nav class="shell" aria-label="Primary navigation">
    <a class="brand" href="/">
        <span class="brand-mark">MP</span>
        <span><strong>Multi-PHP Docker</strong><small><?= $e($t('nav.tagline')) ?></small></span>
    </a>
    <div class="language" aria-label="Language">
        <a href="/?lang=vi" class="<?= $locale === 'vi' ? 'active' : '' ?>" lang="vi" aria-current="<?= $locale === 'vi' ? 'page' : 'false' ?>">VI</a>
        <a href="/?lang=en" class="<?= $locale === 'en' ? 'active' : '' ?>" lang="en" aria-current="<?= $locale === 'en' ? 'page' : 'false' ?>">EN</a>
    </div>
</nav>

<main>
    <div class="shell hero">
        <div>
            <p class="eyebrow"><?= $e($t('hero.eyebrow')) ?></p>
            <h1><?= $e($t('hero.title')) ?></h1>
            <p class="lead"><?= $e($t('hero.description')) ?></p>
            <div class="actions">
                <a class="button primary" href="http://127.0.0.1:8080"><?= $e($t('hero.manager')) ?></a>
                <a class="button" href="<?= $e($rabbitMqUrl) ?>"><?= $e($t('hero.rabbitmq')) ?></a>
                <a class="button" href="/?q=info"><?= $e($t('hero.phpinfo')) ?></a>
            </div>
        </div>
        <aside class="runtime-card">
            <span class="online"><?= $e($t('hero.running')) ?></span>
            <h2>PHP <?= $e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?></h2>
            <p><?= $e($t('hero.php')) ?></p>
            <div class="terminal">$ docker compose up -d<br>&gt; nginx_container ready<br>&gt; php8.2_container ready<br>&gt; supervisor_container ready</div>
        </aside>
    </div>

    <section class="shell">
        <p class="eyebrow"><?= $e($t('services.eyebrow')) ?></p>
        <h2 class="section-title"><?= $e($t('services.title')) ?></h2>
        <div class="service-grid">
            <?php foreach ($services as $service): ?>
                <article class="card">
                    <div class="card-top"><span class="service-code"><?= $e($service['code']) ?></span><span class="meta"><?= $e($service['meta']) ?></span></div>
                    <h3><?= $e($service['name']) ?></h3>
                    <p><?= $e($t($service['text'])) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="shell">
        <p class="eyebrow"><?= $e($t('quick.eyebrow')) ?></p>
        <h2 class="section-title"><?= $e($t('quick.title')) ?></h2>
        <div class="steps">
            <article class="step"><h3><?= $e($t('quick.one.title')) ?></h3><p><?= $e($t('quick.one.text')) ?></p><code>http://127.0.0.1:8080</code></article>
            <article class="step"><h3><?= $e($t('quick.two.title')) ?></h3><p><?= $e($t('quick.two.text')) ?></p><code>./scripts/hosts/add_hostname.sh</code></article>
            <article class="step"><h3><?= $e($t('quick.three.title')) ?></h3><p><?= $e($t('quick.three.text')) ?></p><code>http://my-project.test</code></article>
        </div>
    </section>

    <section class="shell">
        <div class="runtime">
            <div><p class="eyebrow"><?= $e($t('runtime.eyebrow')) ?></p><h2><?= $e($t('runtime.title')) ?></h2></div>
            <div class="runtime-list">
                <div class="runtime-row"><span><?= $e($t('runtime.server')) ?></span><code><?= $e((string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Nginx')) ?></code></div>
                <div class="runtime-row"><span><?= $e($t('runtime.php')) ?></span><code><?= $e(PHP_VERSION) ?></code></div>
                <div class="runtime-row"><span><?= $e($t('runtime.root')) ?></span><code><?= $e((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?></code></div>
            </div>
        </div>
    </section>
</main>

<footer class="shell"><?= $e($t('footer.text')) ?></footer>
</body>
</html>
