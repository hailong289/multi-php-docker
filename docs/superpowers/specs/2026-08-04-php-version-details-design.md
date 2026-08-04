# PHP Version Details — Extensions & php.ini

**Date:** 2026-08-04  
**Status:** Approved for implementation planning  
**Scope:** Manager UI + API for per-PHP-version details: view/install curated extensions, edit mounted `php.ini`

## Problem

The Manager **PHP versions** page (`/php-versions`) only supports Create / Start / Stop / Restart. Operators need to inspect a version, enable or install PHP extensions, and edit `php.ini` without leaving the Manager or manually editing host files / rebuilding images.

## Goals

- Per-version **Details** page at `/php-versions/:service`
- **Extensions** tab: list loaded modules; install from a curated allowlist; enable/disable known `extension=` lines in `php.ini`
- **php.ini** tab: edit the host-mounted ini file; after save, **offer Restart** (do not auto-restart)
- Keep Docker socket only on `php-controller`; Manager never gains `docker.sock`
- i18n for **en** and **vi**

## Non-goals

- Delete / destroy PHP containers
- Free-form shell or arbitrary package install
- Rebuild/push custom Docker images from the UI
- Uninstalling extensions compiled into the image
- Persist runtime-installed extensions across `recreate` (documented limitation; warn in UI)

## Context (current system)

| Piece | Location / behavior |
|-------|---------------------|
| List UI | `server/manager/frontend/src/views/PhpVersionsView.vue` |
| Lifecycle API | `POST /api/php-controllers/{service}/{start\|stop\|restart\|create}` |
| Worker | `scripts/php-controller.sh` — request JSON allowlist, Docker ops |
| Ini mounts | `configs/php8/php.ini` → PHP 8.2; `configs/php8.1`, `php8.0`, `php7.4` similarly → `/usr/local/etc/php/php.ini` |
| Precedent details page | Nginx: `/nginx` + `GET /api/nginx/management` |

## Design

### UI

1. **List** (`PhpVersionsView`): add a **Details** button per row → navigate to `/php-versions/:service`.
2. **Details** (`PhpVersionDetailView`, new):
   - Header: label, container name, state, lifecycle buttons (same enablement rules as list).
   - Tab **Extensions**:
     - Loaded modules from `php -m` when container is `running`; otherwise show empty/disabled with hint.
     - Curated catalog rows: status `loaded` | `disabled_in_ini` | `available_to_install` | `unsupported_on_version`.
     - Actions: **Install** (curated, not loaded), **Enable** / **Disable** (ini toggle when `.so` present / line known).
     - Banner: runtime installs are lost if the container is recreated.
   - Tab **php.ini**:
     - Textarea (or simple code editor) bound to file content.
     - **Save** → success toast + confirm dialog: “Restart PHP-FPM now?” → optional existing `restart` action.
3. Router + nav: register route; keep “PHP versions” nav pointing at the list.
4. Follow NginxView patterns: own `load()`, pending flags, CSRF via `apiSend`.

### Backend API

All under `/api`, CSRF on mutations.

| Method | Path | Behavior |
|--------|------|----------|
| `GET` | `/php-controllers/{service}/details` | Target metadata, live status, ini path (relative), ini content, modules (if obtainable), curated extension catalog with per-ext status |
| `PUT` | `/php-controllers/{service}/ini` | Body `{ "content": "..." }` — write allowlisted host ini path; max size **256 KiB**; reject null bytes |
| `POST` | `/php-controllers/{service}/extensions/{name}/install` | Queue controller action `install-ext` for curated `name` only; container must be `running` |
| `POST` | `/php-controllers/{service}/extensions/{name}/enable` | Uncomment or add `extension={name}.so` in that service’s ini (no Docker) |
| `POST` | `/php-controllers/{service}/extensions/{name}/disable` | Comment out matching `extension=` line(s) in ini (no Docker) |

Existing lifecycle routes remain unchanged.

**Service allowlist:** `php-8.2`, `php-8.1`, `php-8.0`, `php-7.4` only.

### Host ini path map

Manager resolves via project root mount (`/var/host-project` or equivalent `Config`):

| Service | Relative path |
|---------|----------------|
| `php-8.2` | `configs/php8/php.ini` |
| `php-8.1` | `configs/php8.1/php.ini` |
| `php-8.0` | `configs/php8.0/php.ini` |
| `php-7.4` | `configs/php7.4/php.ini` |

Writes: resolve realpath under project root; never follow paths outside catalog entries.

### Modules probe & install (php-controller)

Extend request validation in both `PhpRuntime::request()` and `php-controller.sh`:

- Lifecycle request shape (unchanged fields): `request_id`, `service`, `action`, `requested_at` with `action` ∈ `start|stop|restart|create`
- Add actions: `modules`, `install-ext`
- For `install-ext` only, JSON also includes `"extension":"<name>"` where `<name>` matches `^[a-z0-9_]+$` and is in the curated catalog. Lifecycle/modules requests must omit `extension`.

**`modules`:** `docker exec <container> php -m` while container is running. Write **sidecar** file only (do not overload lifecycle status JSON):

`php-controller-runtime/status/{service}.modules.json`

Shape: `{ "service", "modules": ["Core", ...], "updated_at", "request_id", "ok": true|false }`.

Manager `GET .../details`: if container `running` and sidecar missing or older than ~30s, queue `modules` and return whatever is available (UI may poll details). Lifecycle `statuses()` continues to read only `{service}.json` and ignores unknown keys if any appear.

**`install-ext`:** Controller runs a **fixed command map** in `scripts/php-ext-install.sh` (invoked only with validated `service` + `extension` tokens; no user shell strings):

| Extension | Install strategy |
|-----------|------------------|
| `redis` | `pecl install` + `docker-php-ext-enable` |
| `imagick` | apt deps as needed + `pecl install` + enable |
| `mongodb` | `pecl install` + enable |
| `xdebug` | `pecl install` + enable |
| `bcmath`, `intl`, `opcache`, `soap`, `exif`, `gmp` | `docker-php-ext-install` and/or `docker-php-ext-enable` |

After attempt: write install log under `php-controller-runtime/status/{service}.last-install.log`, refresh modules sidecar, set lifecycle status `message_key` to success or `php_controller.action_failed`. Enabling via mounted ini after pecl/so install is done by Manager enable endpoint or by appending a known `extension=` line from the install script only for that curated name.

**Version gaps:** Catalog may mark an extension `unsupported_on_version` for a given service (e.g. if pecl recipe fails on 7.4). API returns 400; UI hides Install. Concrete unsupported pairs are finalized during implementation when recipes are verified; default is “supported on all four versions” until proven otherwise.

### Curated catalog (v1)

`redis`, `imagick`, `mongodb`, `xdebug`, `bcmath`, `intl`, `opcache`, `soap`, `exif`, `gmp`

Shared PHP array/constant used by API responses so UI does not hardcode the list alone.

### Enable / disable via ini

- Match lines like `extension=foo.so`, `extension=foo`, optional leading `;` / whitespace.
- Enable: strip leading `;` on matching lines, or append `extension={name}.so` if absent.
- Disable: prefix matching active lines with `;`.
- Do not remove unrelated settings. Preserve file endings when practical.
- Apply only for curated names (same allowlist).

### Error handling

- Unknown service → 404
- Ini write while path missing/unwritable → 500 with clear `message_key`
- Install while not `running` / busy → 409
- Unknown curated name → 400
- Controller install failure → status `error` + `message_key`; UI surface last error log if available (mirror create-error pattern)
- CSRF failures unchanged

### Security

- No Docker socket on Manager
- Controller: still `network_mode` / mounts as today; only expand regex + fixed handlers
- Ini paths catalog-only; size + content sanity checks
- Extension names allowlisted; no shell interpolation of user input beyond validated token

### Testing (implementation plan will detail)

- Unit: ini path map, enable/disable line transforms, allowlist rejection
- Manual: open details for running 8.2; edit ini → save → restart offer; install one curated ext; confirm `php -m`; recreate note still shown

## File touch list (expected)

- `server/manager/frontend`: router, `PhpVersionsView`, new `PhpVersionDetailView`, `useManager` helpers, `en.js` / `vi.js`
- `server/manager/backend`: `routes.php`, `PhpControllerController`, new/extended models (`PhpRuntime`, ini helper, extension catalog)
- `scripts/php-controller.sh` (+ optional `scripts/php-ext-install.sh`)
- Docs: this spec; README short note on ephemeral runtime extensions

## Decisions locked in brainstorming

1. Toggle + runtime curated install (accept loss on recreate)
2. Dedicated details route (Nginx-style)
3. Save ini → offer restart (not auto)
4. Curated list only (no free-form pecl name)
5. Architecture approach A (Manager ini I/O + controller allowlist for modules/install)

