# Manager Remote Access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Opt-in remote Server Manager behind Nginx HTTPS with username/password session auth, while keeping default localhost `127.0.0.1:8080` CSRF-only behavior unchanged.

**Architecture:** `MANAGER_REMOTE=1` enables auth middleware and an Nginx reverse-proxy vhost for `MANAGER_DOMAIN` → `http://manager:8080`. Credentials come from env (`MANAGER_USERNAME` / `MANAGER_PASSWORD`). Fail closed if remote is on without credentials. Local mode ignores login.

**Tech Stack:** PHP Manager backend (session + CSRF), Vue 3 frontend, Docker Compose, Nginx templates + `scripts/nginx/auto-add-template.sh`.

**Spec:** `docs/superpowers/specs/2026-08-06-manager-remote-access-design.md`

## Global Constraints

- Default remains localhost-only: compose port publish stays `127.0.0.1:8080:8080`; never switch default to `0.0.0.0`.
- Remote is opt-in via `MANAGER_REMOTE=1`; local (`0` / unset) must not require login.
- Remote without both username and password must fail closed (no usable remote panel).
- Keep existing CSRF for mutating APIs; auth is an additional gate when remote=1.
- Do **not** `git commit` unless the user explicitly asks (skip “Commit” steps by default).
- Do not add OAuth, multi-user RBAC, or change php-controller IPC model.
- Password v1 is plain env string compared with `hash_equals`; bcrypt hash is out of scope for this plan.
- Follow existing Manager patterns under `server/manager/backend/` and frontend under `server/manager/frontend/`.

## File map

| File | Responsibility |
| --- | --- |
| `server/manager/backend/Support/RemoteAuth.php` | Read env flags; credential check; session auth state; rate-limit helpers |
| `server/manager/backend/Support/Config.php` | Thin accessors for remote env vars (optional wrappers) |
| `server/manager/backend/Http/Kernel.php` | CSRF + remote auth gate; public route allowlist |
| `server/manager/backend/Controllers/SessionController.php` | Session payload: csrf + `remote` + `authenticated` + `domain` |
| `server/manager/backend/Controllers/AuthController.php` | `login` / `logout` |
| `server/manager/backend/routes.php` | Register auth routes |
| `server/manager/backend/tests/run_unit_checks.php` | Unit checks for RemoteAuth helpers |
| `server/manager/frontend/src/views/LoginView.vue` | Login form UI |
| `server/manager/frontend/src/router/index.js` | `/login` + navigation guard |
| `server/manager/frontend/src/api.js` | Surface 401 for callers |
| `server/manager/frontend/src/composables/useManager.js` | Bootstrap respects remote/auth; logout helper |
| `server/manager/frontend/src/App.vue` | Mode badge; hide chrome until auth when remote |
| `server/manager/frontend/src/i18n/en.js`, `vi.js` | Login + badge copy |
| `nginx/examples/manager_proxy_example.txt` | Nginx reverse-proxy template for Manager |
| `scripts/nginx/auto-add-template.sh` | Emit/remove `manager.template` based on remote env |
| `docker-compose.yml` | Pass `MANAGER_*` into `manager` + `nginx` |
| `.env.example` | Document remote env vars |
| `README.md`, `README.vi.md` | Local vs remote usage + security warnings |

---

### Task 1: RemoteAuth support + unit checks

**Files:**
- Create: `server/manager/backend/Support/RemoteAuth.php`
- Modify: `server/manager/backend/Support/Config.php`
- Modify: `server/manager/backend/tests/run_unit_checks.php`

**Interfaces:**
- Produces:
  - `Config::managerRemote(): bool`
  - `Config::managerUsername(): string`
  - `Config::managerPassword(): string`
  - `Config::managerDomain(): string`
  - `RemoteAuth::isRemote(): bool`
  - `RemoteAuth::credentialsConfigured(): bool`
  - `RemoteAuth::isLocked(): bool` — remote on but missing credentials
  - `RemoteAuth::isAuthenticated(): bool`
  - `RemoteAuth::attemptLogin(string $username, string $password, string $clientIp): bool`
  - `RemoteAuth::logout(): void`
  - `RemoteAuth::requireAuthenticated(): void` — throws `HttpException` 401 or 503 if locked
  - Session keys: `$_SESSION['manager_authenticated'] = true` after success

- [ ] **Step 1: Extend Config accessors**

Add to `server/manager/backend/Support/Config.php`:

```php
public static function managerRemote(): bool
{
    $raw = strtolower(trim((string) (getenv('MANAGER_REMOTE') ?: '0')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

public static function managerUsername(): string
{
    return (string) (getenv('MANAGER_USERNAME') ?: '');
}

public static function managerPassword(): string
{
    return (string) (getenv('MANAGER_PASSWORD') ?: '');
}

public static function managerDomain(): string
{
    return trim((string) (getenv('MANAGER_DOMAIN') ?: ''));
}
```

- [ ] **Step 2: Write failing unit checks for RemoteAuth**

Append to `server/manager/backend/tests/run_unit_checks.php` (after existing asserts). Put env overrides in the test process only:

```php
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

// Reset for other tests that might run later in same process
putenv('MANAGER_REMOTE=0');
```

- [ ] **Step 3: Run checks — expect FAIL (class missing)**

Run:

```bash
php server/manager/backend/tests/run_unit_checks.php
```

Expected: fatal/error about `RemoteAuth` not found (or FAIL).

- [ ] **Step 4: Implement RemoteAuth**

Create `server/manager/backend/Support/RemoteAuth.php`:

```php
<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Http\HttpException;

final class RemoteAuth
{
    private const SESSION_AUTH = 'manager_authenticated';
    private const RATE_FILE_PREFIX = 'manager_login_rate_';

    public static function isRemote(): bool
    {
        return Config::managerRemote();
    }

    public static function credentialsConfigured(): bool
    {
        return Config::managerUsername() !== '' && Config::managerPassword() !== '';
    }

    public static function isLocked(): bool
    {
        return self::isRemote() && !self::credentialsConfigured();
    }

    public static function isAuthenticated(): bool
    {
        if (!self::isRemote()) {
            return true;
        }
        return !empty($_SESSION[self::SESSION_AUTH]);
    }

    public static function requireAuthenticated(): void
    {
        if (!self::isRemote()) {
            return;
        }
        if (self::isLocked()) {
            throw new HttpException('error.manager_remote_locked', 503);
        }
        if (!self::isAuthenticated()) {
            throw new HttpException('error.unauthorized', 401);
        }
    }

    public static function attemptLogin(string $username, string $password, string $clientIp): bool
    {
        if (!self::isRemote()) {
            return false;
        }
        if (self::isLocked()) {
            throw new HttpException('error.manager_remote_locked', 503);
        }
        if (self::isRateLimited($clientIp)) {
            throw new HttpException('error.login_rate_limited', 429);
        }

        $ok = hash_equals(Config::managerUsername(), $username)
            && hash_equals(Config::managerPassword(), $password);

        if (!$ok) {
            self::recordFailure($clientIp);
            return false;
        }

        self::clearFailures($clientIp);
        $_SESSION[self::SESSION_AUTH] = true;
        // Rotate CSRF after privilege change
        unset($_SESSION['csrf_token']);
        Csrf::token();
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_AUTH]);
        unset($_SESSION['csrf_token']);
    }

    private static function ratePath(string $clientIp): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $clientIp) ?: 'unknown';
        return rtrim(Config::runtimePath(), '/') . '/' . self::RATE_FILE_PREFIX . $safe . '.json';
    }

    private static function isRateLimited(string $clientIp): bool
    {
        $path = self::ratePath($clientIp);
        if (!is_file($path)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return false;
        }
        $failures = (int) ($data['failures'] ?? 0);
        $windowStart = (int) ($data['window_start'] ?? 0);
        if (time() - $windowStart > 300) {
            return false;
        }
        return $failures >= 10;
    }

    private static function recordFailure(string $clientIp): void
    {
        $path = self::ratePath($clientIp);
        $data = ['failures' => 0, 'window_start' => time()];
        if (is_file($path)) {
            $parsed = json_decode((string) file_get_contents($path), true);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }
        if (time() - (int) ($data['window_start'] ?? 0) > 300) {
            $data = ['failures' => 0, 'window_start' => time()];
        }
        $data['failures'] = (int) ($data['failures'] ?? 0) + 1;
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }

    private static function clearFailures(string $clientIp): void
    {
        $path = self::ratePath($clientIp);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
```

Also harden session cookies in `server/manager/backend/bootstrap.php` **before** `session_start()`:

```php
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();
```

- [ ] **Step 5: Re-run unit checks**

```bash
php server/manager/backend/tests/run_unit_checks.php
```

Expected: all `OK:` lines, exit 0.

- [ ] **Step 6: Commit (only if user asks)**

```bash
git add server/manager/backend/Support/Config.php \
  server/manager/backend/Support/RemoteAuth.php \
  server/manager/backend/bootstrap.php \
  server/manager/backend/tests/run_unit_checks.php
git commit -m "$(cat <<'EOF'
feat: add Manager remote auth helpers.

EOF
)"
```

---

### Task 2: Auth API routes + Kernel gate

**Files:**
- Create: `server/manager/backend/Controllers/AuthController.php`
- Modify: `server/manager/backend/Controllers/SessionController.php`
- Modify: `server/manager/backend/routes.php`
- Modify: `server/manager/backend/Http/Kernel.php`
- Modify: `server/manager/backend/Application.php` (only if route prefix / middleware wiring needs a public-path list; inspect before editing)

**Interfaces:**
- Consumes: `RemoteAuth::*`, `Csrf::*`
- Produces:
  - `GET /api/session` → `{ csrf_token, remote, authenticated, locked, domain }`
  - `POST /api/login` → `{ ok: true, csrf_token, authenticated: true }` or 401
  - `POST /api/logout` → `{ ok: true }`
  - Kernel public paths when remote: `/session`, `/login` (logout requires auth or still CSRF + optional auth)

- [ ] **Step 1: Expand SessionController**

Replace `show()` body:

```php
return Response::json([
    'csrf_token' => Csrf::token(),
    'remote' => RemoteAuth::isRemote(),
    'authenticated' => RemoteAuth::isAuthenticated(),
    'locked' => RemoteAuth::isLocked(),
    'domain' => Config::managerDomain(),
]);
```

Add `use` for `RemoteAuth` and `Config`.

- [ ] **Step 2: Create AuthController**

```php
<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Support\Csrf;
use Manager\Support\RemoteAuth;

final class AuthController extends Controller
{
    public function login(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $ip = (string) ($request->server('REMOTE_ADDR') ?: 'unknown');

        if (!RemoteAuth::attemptLogin($username, $password, $ip)) {
            return Response::json(['error' => ['key' => 'error.invalid_credentials']], 401);
        }

        return Response::json([
            'ok' => true,
            'authenticated' => true,
            'csrf_token' => Csrf::token(),
        ]);
    }

    public function logout(Request $request, array $params = []): Response
    {
        RemoteAuth::logout();
        return Response::json([
            'ok' => true,
            'authenticated' => false,
            'csrf_token' => Csrf::token(),
        ]);
    }
}
```

Adapt `Request::json()` / `Response::json` / error shape to match existing `Controller` + `HttpException` patterns in this codebase (read `Controller.php` and `HttpException` handler before finalizing).

- [ ] **Step 3: Register routes**

In `routes.php` add:

```php
use Manager\Controllers\AuthController;

// near session route:
['POST', '/login', [AuthController::class, 'login']],
['POST', '/logout', [AuthController::class, 'logout']],
```

- [ ] **Step 4: Update Kernel auth gate**

Replace `Kernel::handle` logic with:

1. Always run CSRF for mutating methods **except** when you intentionally allow login before CSRF (prefer: still require CSRF for `/login` — frontend loads `/api/session` first).
2. After CSRF, if path is not public and remote mode: `RemoteAuth::requireAuthenticated()`.
3. Public API paths (path after `/api` strip, matching route names): `/session`, `/login`.
4. `/logout` requires CSRF; allow when authenticated or always clear session (either is fine; prefer authenticated OR remote off).

Exact sketch (adjust to how `Request` exposes path):

```php
public function handle(Request $request, callable $next): Response
{
    $path = $request->path(); // verify real method name in Request.php

    if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        Csrf::validate($request->header('x-csrf-token'));
    }

    $public = ['/session', '/login'];
    if (!in_array($path, $public, true)) {
        RemoteAuth::requireAuthenticated();
    }

    return $next($request);
}
```

Verify path format against router (likely without `/api` prefix — confirm in `Application.php`).

- [ ] **Step 5: Manual smoke via PHP built-in or compose (local remote simulation)**

With env:

```bash
MANAGER_REMOTE=1 MANAGER_USERNAME=admin MANAGER_PASSWORD=secret \
  php -S 127.0.0.1:8080 -t server/manager/public server/manager/router.php
```

Then:

```bash
# get csrf + cookie
curl -c /tmp/m.cj -b /tmp/m.cj -s http://127.0.0.1:8080/api/session
# login
curl -c /tmp/m.cj -b /tmp/m.cj -s -X POST http://127.0.0.1:8080/api/login \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: <csrf>" \
  -d '{"username":"admin","password":"secret"}'
# protected without auth should 401 first
```

Expected: session reports `remote:true`; unauthenticated `/api/bootstrap` → 401; after login → 200.

- [ ] **Step 6: Commit (only if user asks)**

---

### Task 3: Frontend login gate + i18n

**Files:**
- Create: `server/manager/frontend/src/views/LoginView.vue`
- Modify: `server/manager/frontend/src/router/index.js`
- Modify: `server/manager/frontend/src/api.js`
- Modify: `server/manager/frontend/src/composables/useManager.js`
- Modify: `server/manager/frontend/src/App.vue`
- Modify: `server/manager/frontend/src/i18n/en.js`
- Modify: `server/manager/frontend/src/i18n/vi.js`

**Interfaces:**
- Consumes: `GET /api/session`, `POST /api/login`, `POST /api/logout`
- Produces: router meta `public: true` for login; auth state from session/bootstrap

- [ ] **Step 1: Add i18n keys**

EN (`en.js`):

```js
'header.local_only': 'Local access only · 127.0.0.1:8080',
'header.remote': 'Remote · {domain}',
'header.remote_unnamed': 'Remote access',
'login.title': 'Sign in',
'login.username': 'Username',
'login.password': 'Password',
'login.submit': 'Sign in',
'login.error': 'Invalid username or password.',
'login.locked': 'Remote Manager is locked: set MANAGER_USERNAME and MANAGER_PASSWORD.',
'login.logout': 'Sign out',
```

VI (`vi.js`): matching Vietnamese strings.

- [ ] **Step 2: LoginView.vue**

Simple form calling `apiGet('/api/session')` then `apiSend('POST', '/api/login', { username, password })`, `setCsrfToken` from responses, redirect to `/` on success. Show locked message if `session.locked`.

- [ ] **Step 3: Router guard**

Add route `/login` → `LoginView`, `meta: { public: true, titleKey: 'login.title' }`.

In `router.beforeEach`, if `remote && !authenticated && !to.meta.public` → `/login`. Store remote/auth on a tiny module or fetch session once.

Practical approach:

```js
// authState.js
export const authState = reactive({ ready: false, remote: false, authenticated: true, locked: false, domain: '' })
```

Load in App or guard via `/api/session`.

- [ ] **Step 4: useManager + api.js**

- On `loadBootstrap`, if 401 → set unauthenticated and router push `/login` (when remote).
- Expose `logout()` → `POST /api/logout` then clear bootstrap flag.
- `api.js`: leave throw-on-error; callers handle `error.status === 401`.

- [ ] **Step 5: App.vue badge + logout**

Replace fixed `header.local_only` with:

```js
authState.remote
  ? (authState.domain ? t('header.remote', { domain: authState.domain }) : t('header.remote_unnamed'))
  : t('header.local_only')
```

Show logout button only when `authState.remote && authState.authenticated`.

When remote and not authenticated, render `<RouterView />` only (no nav chrome) — LoginView handles UI.

- [ ] **Step 6: Build frontend**

```bash
cd server/manager/frontend && npm install && npm run build
```

Expected: build succeeds; assets update under `server/manager/public/`.

- [ ] **Step 7: Browser smoke**

Local without remote env: UI works, no login wall.  
With remote env: must login; badge shows remote; logout returns to login.

- [ ] **Step 8: Commit (only if user asks)**

---

### Task 4: Nginx Manager proxy + Compose env wiring

**Files:**
- Create: `nginx/examples/manager_proxy_example.txt`
- Modify: `scripts/nginx/auto-add-template.sh`
- Modify: `docker-compose.yml`
- Create: `.env.example`

**Interfaces:**
- Consumes: `MANAGER_REMOTE`, `MANAGER_DOMAIN`, `MANAGER_USERNAME`, `MANAGER_PASSWORD` in nginx + manager containers
- Produces: `/etc/nginx/templates/manager.template` when remote is fully configured; remove when disabled

- [ ] **Step 1: Add proxy template**

`nginx/examples/manager_proxy_example.txt`:

```nginx
server {
    listen 80;
    server_name ${MANAGER_DOMAIN};

    client_max_body_size 32M;

    access_log /var/log/nginx/manager_access.log;
    error_log /var/log/nginx/manager_error.log;

    location / {
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_pass http://manager:8080;
    }
}
```

Note in README that HTTPS is expected via external TLS terminator, mounted certs, or extending this template to `listen 443 ssl` with operator-supplied cert paths (document common Let’s Encrypt mount pattern; do not invent ACME automation in v1).

- [ ] **Step 2: Extend auto-add-template.sh**

After app templates loop (end of script), add:

```sh
MANAGER_REMOTE_RAW=$(printf '%s' "${MANAGER_REMOTE:-0}" | tr '[:upper:]' '[:lower:]')
MANAGER_DOMAIN_VAL="${MANAGER_DOMAIN:-}"
MANAGER_USER_VAL="${MANAGER_USERNAME:-}"
MANAGER_PASS_VAL="${MANAGER_PASSWORD:-}"
MANAGER_TEMPLATE="$OUTPUT_DIR/manager.template"
MANAGER_EXAMPLE="/etc/nginx/examples/manager_proxy_example.txt"

case "$MANAGER_REMOTE_RAW" in
  1|true|yes|on)
    if [ -n "$MANAGER_DOMAIN_VAL" ] && [ -n "$MANAGER_USER_VAL" ] && [ -n "$MANAGER_PASS_VAL" ] && [ -f "$MANAGER_EXAMPLE" ]; then
      sed -e "s|\${MANAGER_DOMAIN}|${MANAGER_DOMAIN_VAL}|g" \
        "$MANAGER_EXAMPLE" > "$MANAGER_TEMPLATE"
      echo "Wrote manager proxy template for ${MANAGER_DOMAIN_VAL}"
    else
      rm -f "$MANAGER_TEMPLATE"
      echo "MANAGER_REMOTE enabled but domain/credentials incomplete; skipped manager.template" >&2
    fi
    ;;
  *)
    rm -f "$MANAGER_TEMPLATE"
    ;;
esac
```

Ensure desired cleanup of stale app templates does **not** delete `manager.template` incorrectly (if the script deletes templates not in app list, whitelist `manager.template`).

- [ ] **Step 3: Wire docker-compose.yml**

Under `manager.environment` add:

```yaml
MANAGER_REMOTE: ${MANAGER_REMOTE:-0}
MANAGER_USERNAME: ${MANAGER_USERNAME:-}
MANAGER_PASSWORD: ${MANAGER_PASSWORD:-}
MANAGER_DOMAIN: ${MANAGER_DOMAIN:-}
```

Under `nginx.environment` add the same four variables so `auto-add-template.sh` sees them.

Keep:

```yaml
ports:
  - "127.0.0.1:8080:8080"
```

- [ ] **Step 4: Create `.env.example`**

```dotenv
# Server Manager remote access (opt-in). Default stays localhost-only.
MANAGER_REMOTE=0
MANAGER_USERNAME=
MANAGER_PASSWORD=
MANAGER_DOMAIN=manager.example.com

# Optional override used by php-controller (existing)
# HOST_PROJECT_PATH=
```

Ensure `.gitignore` still ignores `.env` if present (add `.env` ignore if missing; do **not** ignore `.env.example`).

- [ ] **Step 5: Verify template generation**

```bash
MANAGER_REMOTE=1 MANAGER_DOMAIN=manager.test \
MANAGER_USERNAME=admin MANAGER_PASSWORD=secret \
docker compose up -d nginx manager
# then exec and ls templates
docker compose exec nginx ls -la /etc/nginx/templates/manager.template
docker compose exec nginx nginx -t
```

Expected: `manager.template` exists; `nginx -t` ok.

Toggle `MANAGER_REMOTE=0`, recreate nginx, confirm template removed.

- [ ] **Step 6: Commit (only if user asks)**

---

### Task 5: README EN/VI remote section

**Files:**
- Modify: `README.md`
- Modify: `README.vi.md`

- [ ] **Step 1: Add section after Server Manager docs**

English outline:

```markdown
## Remote Server Manager (opt-in)

By default Manager listens on `127.0.0.1:8080` without login (CSRF only).

To use Manager on a primary server behind Nginx HTTPS:

1. Copy `.env.example` to `.env` and set:
   - `MANAGER_REMOTE=1`
   - `MANAGER_USERNAME` / `MANAGER_PASSWORD` (strong password)
   - `MANAGER_DOMAIN` (DNS A/AAAA pointing at the server)
2. Terminate TLS for that domain (certificate on Nginx or upstream proxy) and ensure traffic reaches the stack on port 80/443.
3. `docker compose up -d`
4. Open `https://MANAGER_DOMAIN` and sign in.

Fail-closed: remote without credentials does not publish a Manager vhost.

Security: Manager can control containers and sees Docker status. Prefer firewall IP allowlists. Do not publish host port `0.0.0.0:8080`.
```

Mirror in `README.vi.md`.

- [ ] **Step 2: Fix README socket note if it still claims Manager has no docker.sock**

Align with compose: Manager may mount docker.sock **read-only** for live status; remote mode makes this higher risk — state that clearly.

- [ ] **Step 3: Commit (only if user asks)**

---

## Spec coverage checklist

| Spec requirement | Task |
| --- | --- |
| Opt-in `MANAGER_REMOTE` | 1, 4 |
| Username/password session | 1, 2, 3 |
| Fail closed without credentials | 1, 2, 4 |
| `/api/session` + login/logout | 2, 3 |
| CSRF retained | 2 |
| Secure cookie behind `X-Forwarded-Proto` | 1 |
| Login rate limit | 1 |
| Vue login gate + badge | 3 |
| Nginx proxy template + generation | 4 |
| Compose env + `.env.example` | 4 |
| README EN/VI warnings | 5 |
| Keep `127.0.0.1:8080` default | 4 (explicit) |
| No OAuth / RBAC / php-controller redesign | Global Constraints |

## Placeholder / consistency review

- Path method on `Request` must be verified in Task 2 against real `Request.php` (no invented API left unimplemented).
- Error JSON keys must match existing `HttpException` → frontend i18n keys (add keys if missing).
- `auto-add-template.sh` cleanup must whitelist `manager.template`.
- Commit steps are optional pending explicit user request.
