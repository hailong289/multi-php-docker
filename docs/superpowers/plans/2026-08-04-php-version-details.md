# PHP Version Details (Extensions + php.ini) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-PHP-version Details page in Manager to list/install curated extensions and edit the host-mounted `php.ini`, with Restart offered after save.

**Architecture:** Manager reads/writes allowlisted `configs/php*/php.ini` on the host mount. Module listing and curated installs go through the existing `php-controller` request-file queue (`modules`, `install-ext`) so Manager never gets `docker.sock`. UI mirrors Nginx management (dedicated Vue route + own load/poll).

**Tech Stack:** Vue 3 + Vue Router + vue-i18n (Manager frontend), PHP 8.2 Manager API (`server/manager/backend`), `scripts/php-controller.sh` + new `scripts/php-ext-install.sh`, Docker exec into `php*_container`.

**Spec:** `docs/superpowers/specs/2026-08-04-php-version-details-design.md`

## Global Constraints

- Services only: `php-8.2`, `php-8.1`, `php-8.0`, `php-7.4`
- Curated extensions only: `redis`, `imagick`, `mongodb`, `xdebug`, `bcmath`, `intl`, `opcache`, `soap`, `exif`, `gmp`
- Ini max size: 256 KiB; reject null bytes
- Ini path map: `php-8.2` → `configs/php8/php.ini`; `php-8.1` → `configs/php8.1/php.ini`; `php-8.0` → `configs/php8.0/php.ini`; `php-7.4` → `configs/php7.4/php.ini`
- Modules sidecar: `php-controller-runtime/status/{service}.modules.json` (not lifecycle `{service}.json`)
- Save ini → offer Restart (never auto-restart)
- Runtime installs are ephemeral on recreate — UI must warn
- CSRF on all mutations via existing `apiSend`
- i18n keys in both `en.js` and `vi.js`
- No PHPUnit in repo — use `php server/manager/backend/tests/run_unit_checks.php` for pure PHP unit checks
- Do not mount Docker socket into Manager; do not add free-form shell

## File structure

| File | Responsibility |
|------|----------------|
| `server/manager/backend/Support/Config.php` | Add `projectPath()` → `/var/host-project` (env override) |
| `server/manager/backend/Models/PhpIniEditor.php` | Path resolve, read/write, enable/disable `extension=` lines |
| `server/manager/backend/Models/PhpExtensionCatalog.php` | Curated names + per-service status computation |
| `server/manager/backend/Models/PhpRuntime.php` | Extend `request()` for `modules` / `install-ext`; read modules sidecar |
| `server/manager/backend/Models/PhpDetails.php` | Aggregate details payload for one service |
| `server/manager/backend/Controllers/PhpControllerController.php` | `details`, `saveIni`, `installExtension`, `enableExtension`, `disableExtension` |
| `server/manager/backend/routes.php` | New routes |
| `server/manager/backend/tests/run_unit_checks.php` | Lightweight assertions |
| `scripts/php-ext-install.sh` | Fixed install recipes per curated name |
| `scripts/php-controller.sh` | Handle `modules` + `install-ext` |
| `server/manager/frontend/src/views/PhpVersionDetailView.vue` | Details UI |
| `server/manager/frontend/src/views/PhpVersionsView.vue` | Details button |
| `server/manager/frontend/src/router/index.js` | Route `/php-versions/:service` |
| `server/manager/frontend/src/i18n/en.js`, `vi.js` | Strings |
| `README.md` | Short note on ephemeral runtime extensions |

---

### Task 1: PhpIniEditor + projectPath + unit checks

**Files:**
- Modify: `server/manager/backend/Support/Config.php`
- Create: `server/manager/backend/Models/PhpIniEditor.php`
- Create: `server/manager/backend/tests/run_unit_checks.php`

**Interfaces:**
- Produces:
  - `Config::projectPath(): string`
  - `PhpIniEditor::relativePath(string $service): string`
  - `PhpIniEditor::absolutePath(string $service): string`
  - `PhpIniEditor::read(string $service): string`
  - `PhpIniEditor::write(string $service, string $content): void`
  - `PhpIniEditor::enableExtension(string $service, string $name): void`
  - `PhpIniEditor::disableExtension(string $service, string $name): void`
  - `PhpIniEditor::extensionLineStatus(string $iniContent, string $name): 'active'|'commented'|'absent'`

- [ ] **Step 1: Write failing unit checks for ini line transforms**

Create `server/manager/backend/tests/run_unit_checks.php`:

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Manager\Models\PhpIniEditor;

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
}

$editor = new PhpIniEditor('/tmp'); // project root unused for pure string helpers

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

echo "All checks passed\n";
```

- [ ] **Step 2: Run checks — expect FAIL (class missing)**

Run: `php server/manager/backend/tests/run_unit_checks.php`

Expected: fatal error / class not found for `PhpIniEditor`

- [ ] **Step 3: Implement Config::projectPath and PhpIniEditor**

Add to `Config.php`:

```php
public static function projectPath(): string
{
    return rtrim(getenv('MANAGER_PROJECT_PATH') ?: '/var/host-project', '/');
}
```

Create `PhpIniEditor.php` with:

```php
<?php
declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;

final class PhpIniEditor
{
    private const MAX_BYTES = 262144; // 256 KiB

    private static array $paths = [
        'php-8.2' => 'configs/php8/php.ini',
        'php-8.1' => 'configs/php8.1/php.ini',
        'php-8.0' => 'configs/php8.0/php.ini',
        'php-7.4' => 'configs/php7.4/php.ini',
    ];

    public function __construct(private readonly string $projectPath = '')
    {
    }

    private function root(): string
    {
        return rtrim($this->projectPath !== '' ? $this->projectPath : Config::projectPath(), '/');
    }

    public static function relativePath(string $service): string
    {
        if (!isset(self::$paths[$service])) {
            throw new HttpException('php_controller.invalid_service', 404);
        }
        return self::$paths[$service];
    }

    public function absolutePath(string $service): string
    {
        $rel = self::relativePath($service);
        $full = $this->root() . '/' . $rel;
        $realRoot = realpath($this->root());
        if ($realRoot === false) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }
        $dir = dirname($full);
        if (!is_dir($dir)) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }
        $realDir = realpath($dir);
        if ($realDir === false || !str_starts_with($realDir, $realRoot)) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }
        return $realDir . '/' . basename($full);
    }

    public function read(string $service): string
    {
        $path = $this->absolutePath($service);
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new HttpException('php_controller.ini_unreadable', 500);
        }
        return $content;
    }

    public function write(string $service, string $content): void
    {
        if (str_contains($content, "\0")) {
            throw new HttpException('php_controller.ini_invalid', 400);
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new HttpException('php_controller.ini_too_large', 400);
        }
        $path = $this->absolutePath($service);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new HttpException('php_controller.ini_write_failed', 500);
        }
    }

    public function extensionLineStatus(string $iniContent, string $name): string
    {
        $name = preg_quote($name, '/');
        if (preg_match('/^\s*extension\s*=\s*' . $name . '(?:\.so)?\s*;?\s*$/mi', $iniContent)) {
            return 'active';
        }
        if (preg_match('/^\s*;\s*extension\s*=\s*' . $name . '(?:\.so)?\s*;?\s*$/mi', $iniContent)) {
            return 'commented';
        }
        return 'absent';
    }

    /** Pure transform used by enable/disable and unit checks. */
    public function toggleExtensionContent(string $iniContent, string $name, bool $enable): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new HttpException('php_controller.invalid_extension', 400);
        }
        $lines = preg_split("/\r\n|\n|\r/", $iniContent) ?: [];
        $patternActive = '/^\s*extension\s*=\s*' . preg_quote($name, '/') . '(?:\.so)?\s*;?\s*$/i';
        $patternCommented = '/^\s*;\s*extension\s*=\s*' . preg_quote($name, '/') . '(?:\.so)?\s*;?\s*$/i';
        $found = false;
        foreach ($lines as $i => $line) {
            if (preg_match($patternActive, $line) || preg_match($patternCommented, $line)) {
                $found = true;
                $lines[$i] = $enable
                    ? ('extension=' . $name . '.so')
                    : (';extension=' . $name . '.so');
            }
        }
        if ($enable && !$found) {
            $lines[] = 'extension=' . $name . '.so';
        }
        $out = implode("\n", $lines);
        if (str_ends_with($iniContent, "\n") && !str_ends_with($out, "\n")) {
            $out .= "\n";
        }
        return $out;
    }

    public function enableExtension(string $service, string $name): void
    {
        $this->write($service, $this->toggleExtensionContent($this->read($service), $name, true));
    }

    public function disableExtension(string $service, string $name): void
    {
        $this->write($service, $this->toggleExtensionContent($this->read($service), $name, false));
    }
}
```

- [ ] **Step 4: Run unit checks — expect PASS**

Run: `php server/manager/backend/tests/run_unit_checks.php`

Expected: `All checks passed`

- [ ] **Step 5: Commit**

```bash
git add server/manager/backend/Support/Config.php \
  server/manager/backend/Models/PhpIniEditor.php \
  server/manager/backend/tests/run_unit_checks.php
git commit -m "$(cat <<'EOF'
feat: add PhpIniEditor for PHP version ini paths and extension toggles

EOF
)"
```

---

### Task 2: PhpExtensionCatalog + status computation

**Files:**
- Create: `server/manager/backend/Models/PhpExtensionCatalog.php`
- Modify: `server/manager/backend/tests/run_unit_checks.php`

**Interfaces:**
- Consumes: `PhpIniEditor::extensionLineStatus`
- Produces:
  - `PhpExtensionCatalog::NAMES: list<string>` (public const array)
  - `PhpExtensionCatalog::isCurated(string $name): bool`
  - `PhpExtensionCatalog::unsupportedOn(string $service, string $name): bool` — return `false` for all in v1
  - `PhpExtensionCatalog::entries(string $service, array $modules, string $iniContent): list<array{name,status}>`
  - Status values: `loaded` | `disabled_in_ini` | `available_to_install` | `unsupported_on_version`

- [ ] **Step 1: Extend unit checks**

Append to `run_unit_checks.php`:

```php
use Manager\Models\PhpExtensionCatalog;

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
assert_true(PhpExtensionCatalog::isCurated('redis'), 'curated redis');
assert_true(!PhpExtensionCatalog::isCurated('foobar'), 'not curated foobar');
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php server/manager/backend/tests/run_unit_checks.php`

Expected: class `PhpExtensionCatalog` not found

- [ ] **Step 3: Implement PhpExtensionCatalog**

```php
<?php
declare(strict_types=1);

namespace Manager\Models;

final class PhpExtensionCatalog
{
    public const NAMES = [
        'redis', 'imagick', 'mongodb', 'xdebug',
        'bcmath', 'intl', 'opcache', 'soap', 'exif', 'gmp',
    ];

    public static function isCurated(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    public static function unsupportedOn(string $service, string $name): bool
    {
        return false; // refine later if a recipe fails on a version
    }

    /**
     * @param list<string> $modules lower/upper case names from php -m
     * @return list<array{name:string,status:string}>
     */
    public static function entries(string $service, array $modules, string $iniContent): array
    {
        $loaded = [];
        foreach ($modules as $m) {
            $loaded[strtolower($m)] = true;
        }
        $editor = new PhpIniEditor();
        $out = [];
        foreach (self::NAMES as $name) {
            if (self::unsupportedOn($service, $name)) {
                $out[] = ['name' => $name, 'status' => 'unsupported_on_version'];
                continue;
            }
            if (isset($loaded[strtolower($name)])) {
                $out[] = ['name' => $name, 'status' => 'loaded'];
                continue;
            }
            $line = $editor->extensionLineStatus($iniContent, $name);
            if ($line === 'commented') {
                $out[] = ['name' => $name, 'status' => 'disabled_in_ini'];
            } else {
                $out[] = ['name' => $name, 'status' => 'available_to_install'];
            }
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run checks — expect PASS**

Run: `php server/manager/backend/tests/run_unit_checks.php`

- [ ] **Step 5: Commit**

```bash
git add server/manager/backend/Models/PhpExtensionCatalog.php \
  server/manager/backend/tests/run_unit_checks.php
git commit -m "$(cat <<'EOF'
feat: add curated PHP extension catalog with status mapping

EOF
)"
```

---

### Task 3: Extend PhpRuntime (modules / install-ext + sidecar)

**Files:**
- Modify: `server/manager/backend/Models/PhpRuntime.php`

**Interfaces:**
- Produces:
  - `PhpRuntime::request(string $service, string $action, ?string $extension = null): string`
  - `PhpRuntime::readModules(string $service): array` → `{ modules: list<string>, updated_at: string, ok: bool, request_id: string }` (empty defaults if missing)
  - `PhpRuntime::modulesStale(string $service, int $maxAgeSeconds = 30): bool`

- [ ] **Step 1: Update `request()` to accept modules / install-ext**

Replace action allowlist and JSON encoding:

```php
public function request(string $service, string $action, ?string $extension = null): string
{
    $targets = self::targets();
    if (!isset($targets[$service])) {
        throw new HttpException('php_controller.invalid_service', 400);
    }
    $allowed = ['start', 'stop', 'restart', 'create', 'modules', 'install-ext'];
    if (!in_array($action, $allowed, true)) {
        throw new HttpException('php_controller.invalid_action', 400);
    }
    if ($action === 'create' && ($targets[$service]['profile'] ?? null) === null) {
        throw new HttpException('php_controller.invalid_action', 400);
    }
    if ($action === 'install-ext') {
        if ($extension === null || !PhpExtensionCatalog::isCurated($extension) || !preg_match('/^[a-z0-9_]+$/', $extension)) {
            throw new HttpException('php_controller.invalid_extension', 400);
        }
        if (PhpExtensionCatalog::unsupportedOn($service, $extension)) {
            throw new HttpException('php_controller.unsupported_extension', 400);
        }
    } elseif ($extension !== null) {
        throw new HttpException('php_controller.invalid_action', 400);
    }

    // ... mkdir as today ...

    $payload = [
        'request_id' => $requestId,
        'service' => $service,
        'action' => $action,
        'requested_at' => date(DATE_ATOM),
    ];
    if ($action === 'install-ext') {
        $payload['extension'] = $extension;
    }
    $request = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $suffix = $action === 'install-ext' ? ($action . '-' . $extension) : $action;
    $finalPath = $requestDir . '/' . $requestId . '__' . $service . '__' . $suffix . '.json';
    // write temp + rename as today
    return $requestId;
}
```

- [ ] **Step 2: Add `readModules` and `modulesStale`**

```php
public function readModules(string $service): array
{
    $defaults = [
        'service' => $service,
        'modules' => [],
        'updated_at' => '',
        'request_id' => '',
        'ok' => false,
    ];
    $file = $this->basePath . '/status/' . $service . '.modules.json';
    if (!is_file($file) || !is_readable($file)) {
        return $defaults;
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded) || ($decoded['service'] ?? null) !== $service) {
        return $defaults;
    }
    $modules = $decoded['modules'] ?? [];
    if (!is_array($modules)) {
        $modules = [];
    }
    $modules = array_values(array_filter($modules, 'is_string'));
    return [
        'service' => $service,
        'modules' => $modules,
        'updated_at' => (string) ($decoded['updated_at'] ?? ''),
        'request_id' => (string) ($decoded['request_id'] ?? ''),
        'ok' => (bool) ($decoded['ok'] ?? false),
    ];
}

public function modulesStale(string $service, int $maxAgeSeconds = 30): bool
{
    $info = $this->readModules($service);
    if ($info['updated_at'] === '') {
        return true;
    }
    $ts = strtotime($info['updated_at']);
    if ($ts === false) {
        return true;
    }
    return (time() - $ts) > $maxAgeSeconds;
}
```

Keep `statuses()` reading only `{service}.json` (unchanged). Treat pending `modules` / `install-ext` request globs as `busy` the same way (existing glob already matches `*__{service}__*.json`).

- [ ] **Step 3: Manual syntax check**

Run: `php -l server/manager/backend/Models/PhpRuntime.php`

Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add server/manager/backend/Models/PhpRuntime.php
git commit -m "$(cat <<'EOF'
feat: queue php-controller modules and install-ext requests

EOF
)"
```

---

### Task 4: PhpDetails model + controller endpoints + routes

**Files:**
- Create: `server/manager/backend/Models/PhpDetails.php`
- Modify: `server/manager/backend/Controllers/PhpControllerController.php`
- Modify: `server/manager/backend/routes.php`

**Interfaces:**
- Consumes: `PhpRuntime`, `PhpIniEditor`, `PhpExtensionCatalog`, `PhpRuntime::targets()`
- Produces HTTP:
  - `GET /php-controllers/{service}/details`
  - `PUT /php-controllers/{service}/ini` body `{content}`
  - `POST /php-controllers/{service}/extensions/{name}/install`
  - `POST /php-controllers/{service}/extensions/{name}/enable`
  - `POST /php-controllers/{service}/extensions/{name}/disable`

- [ ] **Step 1: Implement PhpDetails**

```php
<?php
declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;

final class PhpDetails
{
    public function __construct(
        private readonly PhpRuntime $runtime = new PhpRuntime(),
        private readonly PhpIniEditor $ini = new PhpIniEditor(),
    ) {
    }

    public function forService(string $service): array
    {
        $targets = PhpRuntime::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('php_controller.invalid_service', 404);
        }
        $statuses = $this->runtime->statuses();
        $status = $statuses[$service];
        $iniContent = '';
        $iniReadable = true;
        try {
            $iniContent = $this->ini->read($service);
        } catch (HttpException) {
            $iniReadable = false;
        }
        $modulesInfo = $this->runtime->readModules($service);
        if (($status['state'] ?? '') === 'running' && $this->runtime->modulesStale($service)) {
            try {
                $this->runtime->request($service, 'modules');
            } catch (HttpException) {
                // ignore queue races; UI can refresh
            }
        }
        $extensions = PhpExtensionCatalog::entries(
            $service,
            $modulesInfo['modules'],
            $iniContent,
        );
        return [
            'service' => $service,
            'target' => $targets[$service],
            'status' => $status,
            'ini' => [
                'relative_path' => PhpIniEditor::relativePath($service),
                'content' => $iniContent,
                'readable' => $iniReadable,
            ],
            'modules' => $modulesInfo,
            'extensions' => $extensions,
        ];
    }
}
```

Note: if PHP version in Manager image does not support `new PhpRuntime()` in promoted properties, use constructor body defaults instead:

```php
public function __construct(?PhpRuntime $runtime = null, ?PhpIniEditor $ini = null)
{
    $this->runtime = $runtime ?? new PhpRuntime();
    $this->ini = $ini ?? new PhpIniEditor();
}
```

- [ ] **Step 2: Extend PhpControllerController**

Add methods (keep existing `index` / `action`):

```php
public function details(Request $request, array $params = []): Response
{
    $service = (string) ($params['service'] ?? '');
    return Response::json([
        'php_details' => (new PhpDetails())->forService($service),
    ]);
}

public function saveIni(Request $request, array $params = []): Response
{
    $service = (string) ($params['service'] ?? '');
    $content = $request->json()['content'] ?? null;
    if (!is_string($content)) {
        throw new HttpException('php_controller.ini_invalid', 400);
    }
    (new PhpIniEditor())->write($service, $content);
    return Response::json([
        'message_key' => 'php_controller.ini_saved',
        'php_details' => (new PhpDetails())->forService($service),
    ]);
}

public function installExtension(Request $request, array $params = []): Response
{
    $service = (string) ($params['service'] ?? '');
    $name = (string) ($params['name'] ?? '');
    $runtime = new PhpRuntime();
    $state = $runtime->statuses()[$service]['state'] ?? 'not_created';
    if ($state !== 'running') {
        throw new HttpException('php_controller.container_not_running', 409);
    }
    if ($state === 'busy' || glob(Config::phpControllerPath() . '/requests/*__' . $service . '__*.json')) {
        throw new HttpException('php_controller.busy', 409);
    }
    $requestId = $runtime->request($service, 'install-ext', $name);
    return Response::json([
        'request_id' => $requestId,
        'message_key' => 'php_controller.extension_install_requested',
        'message_parameters' => ['extension' => $name],
    ]);
}

public function enableExtension(Request $request, array $params = []): Response
{
    $service = (string) ($params['service'] ?? '');
    $name = (string) ($params['name'] ?? '');
    if (!PhpExtensionCatalog::isCurated($name)) {
        throw new HttpException('php_controller.invalid_extension', 400);
    }
    (new PhpIniEditor())->enableExtension($service, $name);
    return Response::json([
        'message_key' => 'php_controller.extension_enabled',
        'php_details' => (new PhpDetails())->forService($service),
    ]);
}

public function disableExtension(Request $request, array $params = []): Response
{
    $service = (string) ($params['service'] ?? '');
    $name = (string) ($params['name'] ?? '');
    if (!PhpExtensionCatalog::isCurated($name)) {
        throw new HttpException('php_controller.invalid_extension', 400);
    }
    (new PhpIniEditor())->disableExtension($service, $name);
    return Response::json([
        'message_key' => 'php_controller.extension_disabled',
        'php_details' => (new PhpDetails())->forService($service),
    ]);
}
```

Use `$request->json()` (same as `ServerController` / `DomainController`).

Import `Config`, `PhpDetails`, `PhpIniEditor`, `PhpExtensionCatalog`, `HttpException` as needed.

Fix busy check: read state once; if `busy`, throw 409 before install.

- [ ] **Step 3: Register routes in `routes.php`**

After the existing php-controllers routes, add:

```php
['GET', '/php-controllers/(?P<service>php-[0-9.]+)/details', [PhpControllerController::class, 'details']],
['PUT', '/php-controllers/(?P<service>php-[0-9.]+)/ini', [PhpControllerController::class, 'saveIni']],
['POST', '/php-controllers/(?P<service>php-[0-9.]+)/extensions/(?P<name>[a-z0-9_]+)/install', [PhpControllerController::class, 'installExtension']],
['POST', '/php-controllers/(?P<service>php-[0-9.]+)/extensions/(?P<name>[a-z0-9_]+)/enable', [PhpControllerController::class, 'enableExtension']],
['POST', '/php-controllers/(?P<service>php-[0-9.]+)/extensions/(?P<name>[a-z0-9_]+)/disable', [PhpControllerController::class, 'disableExtension']],
```

- [ ] **Step 4: Syntax-check all touched PHP files**

Run:

```bash
php -l server/manager/backend/Models/PhpDetails.php
php -l server/manager/backend/Controllers/PhpControllerController.php
php -l server/manager/backend/routes.php
php server/manager/backend/tests/run_unit_checks.php
```

Expected: no syntax errors; unit checks pass

- [ ] **Step 5: Commit**

```bash
git add server/manager/backend/Models/PhpDetails.php \
  server/manager/backend/Controllers/PhpControllerController.php \
  server/manager/backend/routes.php
git commit -m "$(cat <<'EOF'
feat: expose PHP details, ini, and extension Manager APIs

EOF
)"
```

---

### Task 5: php-controller modules + install-ext scripts

**Files:**
- Create: `scripts/php-ext-install.sh`
- Modify: `scripts/php-controller.sh`

**Interfaces:**
- Consumes request files with `action` `modules` or `install-ext` (+ `extension`)
- Produces `{service}.modules.json` and `{service}.last-install.log`

- [ ] **Step 1: Create `scripts/php-ext-install.sh`**

```sh
#!/bin/sh
# Usage: php-ext-install.sh <container> <extension>
# extension is already validated by caller (curated + [a-z0-9_]+)
set -eu
container="$1"
ext="$2"

run() {
    docker exec "$container" sh -c "$1"
}

case "$ext" in
    redis)
        run "pecl install -f redis && docker-php-ext-enable redis"
        ;;
    imagick)
        run "apt-get update && apt-get install -y --no-install-recommends libmagickwand-dev && pecl install -f imagick && docker-php-ext-enable imagick && rm -rf /var/lib/apt/lists/*"
        ;;
    mongodb)
        run "pecl install -f mongodb && docker-php-ext-enable mongodb"
        ;;
    xdebug)
        run "pecl install -f xdebug && docker-php-ext-enable xdebug"
        ;;
    bcmath|intl|soap|exif|gmp)
        run "docker-php-ext-install $ext"
        ;;
    opcache)
        run "docker-php-ext-install opcache || docker-php-ext-enable opcache"
        ;;
    *)
        echo "unsupported extension: $ext" >&2
        exit 1
        ;;
esac
```

Make executable: `chmod +x scripts/php-ext-install.sh`

- [ ] **Step 2: Update request validation regex in `php-controller.sh`**

Replace the single grep allowlist with a branch:

```sh
# After reading $request into a single line:
is_lifecycle=$(printf '%s' "$request" | grep -Eq '^\{"request_id":"[0-9a-f]{32}","service":"(php-(8\.2|8\.1|8\.0|7\.4)|nginx)","action":"(start|stop|restart|create)","requested_at":"[0-9T:+-]+"\}$' && echo 1 || echo 0)
is_modules=$(printf '%s' "$request" | grep -Eq '^\{"request_id":"[0-9a-f]{32}","service":"php-(8\.2|8\.1|8\.0|7\.4)","action":"modules","requested_at":"[0-9T:+-]+"\}$' && echo 1 || echo 0)
is_install=$(printf '%s' "$request" | grep -Eq '^\{"request_id":"[0-9a-f]{32}","service":"php-(8\.2|8\.1|8\.0|7\.4)","action":"install-ext","requested_at":"[0-9T:+-]+","extension":"[a-z0-9_]+"\}$' && echo 1 || echo 0)

if [ "$is_lifecycle" -eq 0 ] && [ "$is_modules" -eq 0 ] && [ "$is_install" -eq 0 ]; then
    reject_request "$request_file"
    continue
fi
```

Parse `extension` when `install-ext`:

```sh
extension=$(printf '%s' "$request" | sed -n 's/^.*"extension":"\([a-z0-9_]*\)".*$/\1/p')
```

- [ ] **Step 3: Add handlers before/alongside lifecycle docker actions**

After resolving `$container`, before create/start/stop block:

```sh
write_modules_sidecar() {
    service="$1"
    container="$2"
    request_id="$3"
    updated_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    mods=$(docker exec "$container" php -m 2>/dev/null | tr -d '\r' | grep -v '^\[' | grep -v '^$' | paste -sd',' - | sed 's/,/","/g')
    ok=0
    if [ -n "$mods" ] || docker exec "$container" php -m >/dev/null 2>&1; then
        ok=1
    fi
    # Build JSON array carefully:
    modules_json=$(docker exec "$container" php -m 2>/dev/null | tr -d '\r' | awk '
        BEGIN { printf "[" }
        /^\[/ { next }
        NF==0 { next }
        {
          if (n++) printf ","
          gsub(/\\/,"\\\\"); gsub(/"/,"\\\"")
          printf "\"%s\"", $0
        }
        END { printf "]" }
    ')
    temp_file="$STATUS_DIR/$service.modules.json.tmp"
    printf '{"service":"%s","modules":%s,"updated_at":"%s","request_id":"%s","ok":%s}\n' \
        "$service" "$modules_json" "$updated_at" "$request_id" "$ok" > "$temp_file"
    mv "$temp_file" "$STATUS_DIR/$service.modules.json"
}

if [ "$action" = "modules" ]; then
    write_status "$service" "busy" "php_controller.processing" "$request_id"
    if [ "$(container_state "$container")" = "running" ] && write_modules_sidecar "$service" "$container" "$request_id"; then
        write_status "$service" "running" "php_controller.action_success" "$request_id"
    else
        write_status "$service" "$(container_state "$container")" "php_controller.action_failed" "$request_id"
    fi
    rm -f "$request_file"
    continue
fi

if [ "$action" = "install-ext" ]; then
    write_status "$service" "busy" "php_controller.processing" "$request_id"
    ok=0
    if [ "$(container_state "$container")" = "running" ]; then
        if /project/scripts/php-ext-install.sh "$container" "$extension" \
            >"$STATUS_DIR/$service.last-install.log" 2>&1; then
            ok=1
            write_modules_sidecar "$service" "$container" "$request_id" || true
        fi
    fi
    state=$(container_state "$container")
    if [ "$ok" -eq 1 ]; then
        write_status "$service" "$state" "php_controller.action_success" "$request_id"
    else
        write_status "$service" "$state" "php_controller.action_failed" "$request_id"
    fi
    rm -f "$request_file"
    continue
fi
```

Ensure `extension` for install matches curated list inside the shell too (duplicate allowlist case statement calling reject if unknown).

Note: `json_encode` field order from PHP must match regex. PHP `json_encode` with the payload order used in Task 3 places `extension` after `requested_at`. Keep PHP and regex in sync — if PHP key order drifts, tighten validation to use a small `jq`-free pairwise check or `sed` extraction + field allowlist instead of one giant regex. Prefer extraction + allowlist if the combined regex becomes brittle:

```sh
# Fallback approach if regex fights key order:
request_id=...; service=...; action=...; extension=...
# validate each field with grep -Eq; reject if action not in set
```

Use the fallback extraction approach if implementing proves regex order fragile. Document the chosen validation in a one-line comment in the script.

- [ ] **Step 4: Restart/recreate php-controller container so new script is live** (operator step)

```bash
docker compose up -d --force-recreate php-controller
# or equivalent service name from compose
```

Verify queue: write a test modules request file under runtime and confirm `.modules.json` appears (only if a PHP container is running).

- [ ] **Step 5: Commit**

```bash
git add scripts/php-ext-install.sh scripts/php-controller.sh
git commit -m "$(cat <<'EOF'
feat: php-controller modules probe and curated extension install

EOF
)"
```

---

### Task 6: Frontend i18n + router + list Details button

**Files:**
- Modify: `server/manager/frontend/src/i18n/en.js`
- Modify: `server/manager/frontend/src/i18n/vi.js`
- Modify: `server/manager/frontend/src/router/index.js`
- Modify: `server/manager/frontend/src/views/PhpVersionsView.vue`
- Create: `server/manager/frontend/src/views/PhpVersionDetailView.vue` (stub OK if Task 7 fills UI)

**Interfaces:**
- Route name `php-version-detail`, path `/php-versions/:service`
- i18n keys listed below

- [ ] **Step 1: Add i18n keys (en + vi)**

English additions under php_controller:

```js
'php_controller.details': 'Details',
'php_controller.details_title': 'PHP {version}',
'php_controller.details_subtitle': 'Manage extensions and php.ini for this version.',
'php_controller.back': 'Back to PHP versions',
'php_controller.tab_extensions': 'Extensions',
'php_controller.tab_ini': 'php.ini',
'php_controller.extensions_banner': 'Extensions installed at runtime are lost if this container is recreated.',
'php_controller.extensions_need_running': 'Start the container to list or install extensions.',
'php_controller.ext_status_loaded': 'Loaded',
'php_controller.ext_status_disabled_in_ini': 'Disabled in php.ini',
'php_controller.ext_status_available_to_install': 'Not installed',
'php_controller.ext_status_unsupported_on_version': 'Unsupported',
'php_controller.ext_install': 'Install',
'php_controller.ext_enable': 'Enable',
'php_controller.ext_disable': 'Disable',
'php_controller.ini_save': 'Save php.ini',
'php_controller.ini_saved': 'php.ini saved.',
'php_controller.ini_restart_confirm': 'Restart PHP-FPM now so changes take effect?',
'php_controller.extension_install_requested': 'Install of {extension} requested.',
'php_controller.extension_enabled': 'Extension enabled in php.ini.',
'php_controller.extension_disabled': 'Extension disabled in php.ini.',
'php_controller.ini_invalid': 'Invalid php.ini content.',
'php_controller.ini_too_large': 'php.ini exceeds the size limit.',
'php_controller.ini_unreadable': 'Unable to read php.ini.',
'php_controller.ini_write_failed': 'Unable to write php.ini.',
'php_controller.invalid_extension': 'That PHP extension is not allowed.',
'php_controller.unsupported_extension': 'That extension is not supported on this PHP version.',
'php_controller.container_not_running': 'The PHP container must be running.',
'php_controller.busy': 'Another PHP container action is already in progress.',
'php_controller.refresh': 'Refresh',
```

Vietnamese: matching translations (natural short UI copy).

- [ ] **Step 2: Register route before the catch-all**

```js
import PhpVersionDetailView from '../views/PhpVersionDetailView.vue'
// ...
{
  path: '/php-versions/:service',
  name: 'php-version-detail',
  component: PhpVersionDetailView,
  meta: { titleKey: 'nav.php_versions' },
},
```

Place **after** `/php-versions` list route and **before** `/:pathMatch(.*)*`.

- [ ] **Step 3: Add Details button on list**

In `PhpVersionsView.vue` action cell, add (use `RouterLink` or `useRouter`):

```vue
<button type="button" @click="$router.push({ name: 'php-version-detail', params: { service } })">
  {{ $t('php_controller.details') }}
</button>
```

- [ ] **Step 4: Create stub detail view** (loading + back link only) so route does not 404 — Task 7 replaces body.

```vue
<script setup>
import { useRoute, useRouter } from 'vue-router'
const route = useRoute()
const router = useRouter()
</script>
<template>
  <section class="panel">
    <button type="button" @click="router.push({ name: 'php-versions' })">
      {{ $t('php_controller.back') }}
    </button>
    <p>{{ route.params.service }}</p>
  </section>
</template>
```

- [ ] **Step 5: Commit**

```bash
git add server/manager/frontend/src/i18n/en.js \
  server/manager/frontend/src/i18n/vi.js \
  server/manager/frontend/src/router/index.js \
  server/manager/frontend/src/views/PhpVersionsView.vue \
  server/manager/frontend/src/views/PhpVersionDetailView.vue
git commit -m "$(cat <<'EOF'
feat: wire PHP version details route, i18n, and list link

EOF
)"
```

---

### Task 7: PhpVersionDetailView full UI

**Files:**
- Modify: `server/manager/frontend/src/views/PhpVersionDetailView.vue`

**Interfaces:**
- Consumes: `GET /api/php-controllers/:service/details`, lifecycle via existing `phpAction` or direct `apiSend`, ini PUT, extension POST endpoints
- Pattern: mirror `NginxView.vue` (`load`, `pending`, toasts)

- [ ] **Step 1: Implement full detail view**

Structure:

```vue
<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const {
  showToast,
  translateApiError,
  stateClass,
  phpAction,
  phpActionEnabled,
  phpServiceState,
  isPending,
  loadBootstrap,
} = useManager()

const service = computed(() => String(route.params.service || ''))
const loading = ref(true)
const pending = ref('')
const tab = ref('extensions') // 'extensions' | 'ini'
const details = ref(null)
const iniDraft = ref('')

async function load() {
  loading.value = true
  try {
    const result = await apiGet(`/api/php-controllers/${service.value}/details`)
    details.value = result.php_details
    iniDraft.value = result.php_details.ini.content || ''
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    loading.value = false
  }
}

async function saveIni() {
  pending.value = 'ini'
  try {
    const result = await apiSend('PUT', `/api/php-controllers/${service.value}/ini`, {
      content: iniDraft.value,
    })
    showToast('success', t(result.message_key || 'php_controller.ini_saved'))
    details.value = result.php_details
    if (window.confirm(t('php_controller.ini_restart_confirm'))) {
      await phpAction(service.value, 'restart')
    }
    await load()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function extAction(name, action) {
  // action: install | enable | disable
  pending.value = `${action}:${name}`
  try {
    const path = `/api/php-controllers/${service.value}/extensions/${name}/${action}`
    const result = await apiSend('POST', path, {})
    showToast('success', t(result.message_key || 'php_controller.action_success', result.message_parameters || {}))
    if (action === 'install') {
      await new Promise((r) => setTimeout(r, 2000))
    }
    await load()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

watch(service, load)
onMounted(async () => {
  await loadBootstrap()
  await load()
})
</script>
```

Template requirements:

1. Back button → list
2. Header with label, container code, state badge, Start/Stop/Restart/Create using same enablement as list (`phpActionEnabled` / `phpAction`)
3. Refresh button calling `load`
4. Tabs Extensions / php.ini
5. Extensions tab: banner about recreate; if state !== running show hint; table of `details.extensions` with status label + buttons:
   - `available_to_install` → Install
   - `disabled_in_ini` → Enable
   - `loaded` → Disable
   - `unsupported_on_version` → no action
6. Also show raw loaded modules list (optional compact `<code>` chips or comma list from `details.modules.modules`)
7. php.ini tab: `<textarea>` bound to `iniDraft`, Save button
8. Disable actions while `pending` or status `busy`

Reuse existing CSS classes (`panel`, `controller-actions`, `state-badge`, `primary`) — no new design system.

- [ ] **Step 2: Manual UI smoke (with stack running)**

1. Open Manager → PHP versions → Details on `php-8.2`
2. Confirm tabs render; modules appear after refresh when container running
3. Edit `memory_limit` in ini → Save → decline Restart → confirm file on disk changed under `configs/php8/php.ini`
4. Save again → accept Restart → state goes busy then running
5. Install a small curated ext if feasible (`bcmath` if not loaded) → wait → appears Loaded
6. Switch locale to vi → spot-check new strings

- [ ] **Step 3: Commit**

```bash
git add server/manager/frontend/src/views/PhpVersionDetailView.vue
git commit -m "$(cat <<'EOF'
feat: PHP version details UI for extensions and php.ini

EOF
)"
```

---

### Task 8: README note + final verification

**Files:**
- Modify: `README.md` (short subsection near PHP / Manager docs)

- [ ] **Step 1: Document limitation**

Add a short bullet near Manager / PHP container docs:

```markdown
### PHP extensions from Manager

The PHP version Details page can enable/disable `extension=` lines in the mounted
`configs/php*/php.ini` and install a curated set of extensions into a *running*
container via `php-controller`. Runtime installs do **not** survive container
recreate; bake permanent extensions into a custom image/Dockerfile instead.
```

- [ ] **Step 2: Full verification checklist**

```bash
php server/manager/backend/tests/run_unit_checks.php
php -l server/manager/backend/Models/PhpIniEditor.php
php -l server/manager/backend/Models/PhpExtensionCatalog.php
php -l server/manager/backend/Models/PhpDetails.php
php -l server/manager/backend/Models/PhpRuntime.php
# Rebuild/restart manager frontend if required by project workflow
# docker compose restart php-controller manager   # as applicable
```

Manual: Details flow from Task 7 smoke list.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "$(cat <<'EOF'
docs: note ephemeral runtime PHP extension installs in Manager

EOF
)"
```

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Details route + list button | 6, 7 |
| Extensions tab + curated install + enable/disable | 2, 4, 5, 7 |
| php.ini editor + save offer Restart | 1, 4, 7 |
| No docker.sock on Manager | 3–5 (controller only) |
| Modules sidecar | 3, 5 |
| Curated list v1 | 2 |
| Ini path map + 256 KiB | 1 |
| i18n en+vi | 6 |
| Ephemeral warning + README | 7, 8 |
| No delete / free-form shell | Global constraints |

## Plan self-review notes

- Request JSON key order for `install-ext` must stay aligned with `php-controller.sh` validation; Task 5 prefers field extraction if regex is brittle.
- Confirm `Request` body accessor against existing controllers before coding Task 4.
- `phpAction` from `useManager` already posts lifecycle actions — reuse it for Restart after ini save.
