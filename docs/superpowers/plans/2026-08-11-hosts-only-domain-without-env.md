# Hosts-only Domains Without env.json Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow hosts-only domain add/edit/delete and OS hosts writes to succeed when `env.json` is missing; only `runtime/hosts.extra.json` is required for that path.

**Architecture:** Keep two domain sources. Hosts-only CRUD never requires `EnvConfig::all()`. Add `EnvConfig::allOrEmpty()` for optional reads. Hosts scripts stop hard-failing on missing `env.json` and still merge extras into the OS hosts managed block.

**Tech Stack:** PHP 8 Manager backend, Bash/PowerShell hosts helpers, existing `run_unit_checks.php` smoke assertions.

**Spec:** `docs/superpowers/specs/2026-08-11-hosts-only-domain-without-env-design.md`

## Global Constraints

- Home / server CRUD still requires a real `env.json` (`EnvConfig::all()` / `save()` unchanged in behaviour when file missing).
- Duplicate checks against server domains only when env was loaded successfully.
- No UI page redesign; optional copy fixes only if clearly wrong.
- Do not invent a second hosts protocol; reuse `hosts.sync` + elevation flow.

---

## File map

| File | Responsibility |
|------|----------------|
| `server/manager/backend/Models/EnvConfig.php` | Add `allOrEmpty(): array` |
| `server/manager/backend/Controllers/DomainController.php` | Use `allOrEmpty()` on hosts-only paths |
| `scripts/hosts/add_hostname.sh` | Optional env read; never exit for missing env |
| `scripts/hosts/add_hostname.ps1` | Remove `Invoke-Apply` throw when env missing |
| `server/manager/backend/tests/run_unit_checks.php` | Assert `allOrEmpty` + HostsSync extras-only desired domains |

---

### Task 1: `EnvConfig::allOrEmpty()`

**Files:**
- Modify: `server/manager/backend/Models/EnvConfig.php`
- Modify: `server/manager/backend/tests/run_unit_checks.php`

**Interfaces:**
- Consumes: existing `all(): array` (throws on missing/unreadable/invalid)
- Produces: `public function allOrEmpty(): array` — returns `[]` when file missing or unreadable; for invalid JSON / non-object still throws like `all()` when the file exists and is readable (so corrupt env is not silently ignored). **Exception:** missing file → `[]` only. If the file exists but is unreadable → `[]`. If the file exists, is readable, and is invalid JSON or not an object → throw the same `HttpException` as `all()`.

- [ ] **Step 1: Append failing assertions to unit checks**

At the end of `server/manager/backend/tests/run_unit_checks.php` (before any final success echo if present; otherwise at EOF), add:

```php
use Manager\Models\EnvConfig;
use Manager\Models\HostsSync;
use Manager\Http\HttpException;

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
```

- [ ] **Step 2: Run checks — expect fail (method missing)**

Run:

```powershell
php server/manager/backend/tests/run_unit_checks.php
```

Expected: fatal/error mentioning `allOrEmpty` undefined, or FAIL.

- [ ] **Step 3: Implement `allOrEmpty`**

In `EnvConfig.php`, after `all()`:

```php
/**
 * Soft read for hosts-only flows: missing/unreadable env → empty servers.
 * Corrupt/non-object content still raises like all().
 *
 * @return array<string, array<string, mixed>>
 */
public function allOrEmpty(): array
{
    if (!is_file($this->path) || !is_readable($this->path)) {
        return [];
    }

    return $this->all();
}
```

- [ ] **Step 4: Re-run unit checks**

```powershell
php server/manager/backend/tests/run_unit_checks.php
```

Expected: all `OK:` lines including `allOrEmpty missing file` and `allOrEmpty loads existing env`; exit 0.

- [ ] **Step 5: Commit**

```powershell
git add server/manager/backend/Models/EnvConfig.php server/manager/backend/tests/run_unit_checks.php
git commit -m "feat(manager): add EnvConfig::allOrEmpty for hosts-only reads"
```

---

### Task 2: DomainController hosts-only paths ignore missing env

**Files:**
- Modify: `server/manager/backend/Controllers/DomainController.php`
- Modify: `server/manager/backend/tests/run_unit_checks.php` (HostsSync desiredDomains with empty servers + extras)

**Interfaces:**
- Consumes: `EnvConfig::allOrEmpty(): array`
- Produces: `store` / `updateExtra` / `destroyExtra` / `index` succeed without `env.json`; still call `all()` only nowhere on these paths.

- [ ] **Step 1: Add HostsSync extras-only assertion**

Append to `run_unit_checks.php`:

```php
$hostsTmp = sys_get_temp_dir() . '/hosts-sync-' . bin2hex(random_bytes(4));
mkdir($hostsTmp, 0775, true);
$hosts = new HostsSync($hostsTmp);
$hosts->saveExtras(['solo.test']);
$desired = $hosts->desiredDomains([]);
assert_true($desired === ['solo.test'], 'desiredDomains extras only');
$listed = $hosts->listedDomains([], null);
assert_true(count($listed) === 1 && ($listed[0]['source'] ?? '') === 'hosts', 'listedDomains hosts-only');
```

Confirm `HostsSync` constructor accepts runtime path (it does via `$this->runtimePath`). If the constructor signature differs, open `HostsSync.php` and use the existing constructor args exactly.

- [ ] **Step 2: Run checks — expect PASS for HostsSync (already supports extras-only)**

```powershell
php server/manager/backend/tests/run_unit_checks.php
```

Expected: PASS for desired/listed extras-only. If constructor fails, fix the test to match the real constructor before editing DomainController.

- [ ] **Step 3: Wire DomainController to `allOrEmpty`**

Replace every `$env->all()` used only to list servers for hosts-only CRUD / listing with `$env->allOrEmpty()` in:

- `index()` — `$servers = $env->allOrEmpty();`
- `store()` — both the duplicate foreach and the `$servers = ...` after save
- `updateExtra()` — `$servers = $env->allOrEmpty();`
- `destroyExtra()` — `$servers = $env->allOrEmpty();`

Leave `update()` (server domain rename via `SERVER_NAME*`) on `$env->all()` so missing env still 500s for that route.

Example for `store()`:

```php
$env = new EnvConfig();
$servers = $env->allOrEmpty();
foreach ($servers as $server) {
    if (strcasecmp((string) ($server['DOMAIN_NAME'] ?? ''), $domain) === 0) {
        throw new HttpException('validation.failed', 422, [
            'domain_name' => ['key' => 'validation.duplicate_domain'],
        ]);
    }
}
// ... save extras ...
return Response::json([
    // ...
    'domains' => $hosts->listedDomains($servers, $hosts->status()),
    'manual' => $hosts->manualHint($hosts->desiredDomains($servers)),
    // ...
], 201);
```

- [ ] **Step 4: Re-run unit checks**

```powershell
php server/manager/backend/tests/run_unit_checks.php
```

Expected: exit 0.

- [ ] **Step 5: Commit**

```powershell
git add server/manager/backend/Controllers/DomainController.php server/manager/backend/tests/run_unit_checks.php
git commit -m "fix(manager): hosts-only domain API works without env.json"
```

---

### Task 3: Make `add_hostname.sh` work without env.json

**Files:**
- Modify: `scripts/hosts/add_hostname.sh`

**Interfaces:**
- Consumes: optional `$REPO_ROOT/env.json`, always `$RUNTIME_DIR/hosts.extra.json`
- Produces: same managed hosts write; exit 0 path when only extras exist

- [ ] **Step 1: Remove hard-fail block**

Delete:

```bash
if [ ! -f "$JSON_FILE" ]; then
  echo "ERROR: $JSON_FILE not found"
  exit 1
fi
```

- [ ] **Step 2: Make `read_domains` tolerate missing env**

Replace `read_domains` with:

```bash
read_domains() {
  {
    if [ -f "$JSON_FILE" ]; then
      jq -r 'to_entries[] | select(.key | test("^SERVER_NAME")) | .value.DOMAIN_NAME // empty' "$JSON_FILE"
    fi
    if [ -f "$EXTRA_FILE" ]; then
      jq -r '.[]?' "$EXTRA_FILE"
    fi
  } | tr '[:upper:]' '[:lower:]' | sed '/^$/d' | sort -u
}
```

Keep `jq` install check (still needed for extras parsing). If both files are missing, `read_domains` yields empty — that is allowed.

- [ ] **Step 3: Dry-run domain listing without writing hosts**

From repo root (Git Bash / WSL / macOS), with `env.json` renamed/absent and a temp extras file if needed:

```bash
# Only verify read_domains logic by sourcing carefully OR run script --help path.
# Safer smoke: create runtime/hosts.extra.json with ["hosts-only.test"] and run:
#   bash -c 'source is not needed'
# Instead run a one-liner copied from read_domains, OR run add_hostname.sh against a throwaway
# HOSTS_FILE by temporarily patching — prefer:
mkdir -p runtime
printf '["hosts-only.test"]\n' > runtime/hosts.extra.json
# Inspect domains the script would apply without root by extracting the function:
bash -c '
JSON_FILE=env.json
EXTRA_FILE=runtime/hosts.extra.json
read_domains() {
  {
    if [ -f "$JSON_FILE" ]; then
      jq -r "to_entries[] | select(.key | test(\"^SERVER_NAME\")) | .value.DOMAIN_NAME // empty" "$JSON_FILE"
    fi
    if [ -f "$EXTRA_FILE" ]; then
      jq -r ".[]?" "$EXTRA_FILE"
    fi
  } | tr "[:upper:]" "[:lower:]" | sed "/^$/d" | sort -u
}
read_domains
'
```

Expected output includes `hosts-only.test` and does not error when `env.json` is absent.

- [ ] **Step 4: Commit**

```powershell
git add scripts/hosts/add_hostname.sh
git commit -m "fix(hosts): allow add_hostname.sh without env.json"
```

---

### Task 4: Make `add_hostname.ps1` work without env.json

**Files:**
- Modify: `scripts/hosts/add_hostname.ps1`

**Interfaces:**
- Consumes: existing `Get-DesiredDomains` (already optional env)
- Produces: `Invoke-Apply` no longer throws when `$EnvJson` missing

- [ ] **Step 1: Remove the hard-fail in `Invoke-Apply`**

Delete:

```powershell
if (-not (Test-Path -LiteralPath $EnvJson)) {
    throw "env.json not found at $EnvJson"
}
```

Leave the rest of `Invoke-Apply` unchanged (`Get-DesiredDomains` already skips missing env and reads extras).

- [ ] **Step 2: Smoke `Get-DesiredDomains` in PowerShell**

```powershell
# From repo root — inline smoke of the same logic after edit:
$EnvJson = Join-Path (Get-Location) 'env.json'
$ExtraFile = Join-Path (Get-Location) 'runtime\hosts.extra.json'
New-Item -ItemType Directory -Force -Path (Split-Path $ExtraFile) | Out-Null
Set-Content -LiteralPath $ExtraFile -Value '["hosts-only.test"]' -Encoding utf8
# Dot-source is awkward; instead run:
powershell -NoProfile -Command "& { `$ErrorActionPreference='Stop'; . '.\scripts\hosts\add_hostname.ps1' }" 2>&1 | Select-Object -First 5
```

Do **not** require actual hosts elevation for this gate. Prefer extracting verification by temporarily calling only `Get-DesiredDomains` if you add a `-ListOnly` switch — **YAGNI: skip new switches**. Instead run:

```powershell
# Rename env only for the check if present:
if (Test-Path env.json) { Rename-Item env.json env.json.bak-hosts-test }
try {
  # Script will attempt hosts write; if Access Denied, that is OK for this gate —
  # the important signal is it must NOT throw "env.json not found".
  powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\hosts\add_hostname.ps1 -Once 2>&1 |
    Tee-Object -Variable hostsOut
  $joined = ($hostsOut | Out-String)
  if ($joined -match 'env\.json not found') { throw 'still requires env.json' }
  Write-Host 'OK: no env.json hard-fail'
} finally {
  if (Test-Path env.json.bak-hosts-test) { Rename-Item env.json.bak-hosts-test env.json }
}
```

Expected: no `env.json not found`; may print Applying domains / Access denied / success depending on privileges.

- [ ] **Step 3: Commit**

```powershell
git add scripts/hosts/add_hostname.ps1
git commit -m "fix(hosts): allow add_hostname.ps1 without env.json"
```

---

### Task 5: Manual end-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Backend unit checks green**

```powershell
php server/manager/backend/tests/run_unit_checks.php
```

Expected: exit 0.

- [ ] **Step 2: Document verification notes in commit message body if live Manager stack is unavailable**

If Docker Manager is running, optionally:

1. Rename/hide `env.json`.
2. POST hosts-only domain via Manager Domains UI or API.
3. Confirm `runtime/hosts.extra.json` lists the domain.
4. Run hosts helper; confirm managed block contains `127.0.0.1 <domain>` (needs admin once).
5. Restore `env.json`.

- [ ] **Step 3: Final commit only if leftover doc/comment tweaks remain; otherwise skip empty commit**

---

## Self-review (plan vs spec)

| Spec requirement | Task |
|------------------|------|
| API hosts-only without env | Task 2 (`allOrEmpty`) |
| `allOrEmpty` / safe empty servers | Task 1 |
| Scripts no hard-fail | Tasks 3–4 |
| Merge env+extras when both exist | Unchanged logic in scripts/`desiredDomains` |
| Home still requires env | Task 2 leaves `update()` on `all()` |
| Manual test | Task 5 |
