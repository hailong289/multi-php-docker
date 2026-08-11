# Hosts-only domains without requiring env.json

**Date:** 2026-08-11  
**Status:** Approved for planning

## Problem

Adding a hosts-only domain is meant to write entries into the OS hosts file via `runtime/hosts.extra.json`. Today that path still hard-depends on `env.json`:

1. **API** — `DomainController` calls `EnvConfig::all()`, which throws `error.env_missing` when `env.json` is absent, so hosts-only create/list responses fail even though only `hosts.extra.json` should matter.
2. **Hosts scripts** — `scripts/hosts/add_hostname.sh` and `add_hostname.ps1` exit/throw if `env.json` is missing, so extras never reach the OS hosts file when there is no virtual-server config yet.

## Goal

Hosts-only add / edit / delete / write must work with **zero** dependence on `env.json` existence. Virtual servers on Home continue to use `env.json` unchanged.

## Non-goals

- Changing how Home creates Nginx/PHP servers in `env.json`.
- Separating UI into a new page (Domains page stays; it may show server domains when env exists).
- New protocol / elevation model for hosts writes.

## Design

### Architecture

Two independent domain sources:

| Source | Storage | Written by | Consumed by hosts write |
|--------|---------|------------|-------------------------|
| Server domains | `env.json` (`SERVER_NAME*`) | Home / DomainController::update | Optional if file exists |
| Hosts-only | `runtime/hosts.extra.json` | Domains API extras endpoints | Always |

Merged set = optional env domains ∪ extras. Missing env ⇒ merged set = extras only.

### API (`DomainController` + `HostsSync`)

- **`store` / `updateExtra` / `destroyExtra` / `index`:** never require a readable `env.json`.
- Introduce a safe read (e.g. `EnvConfig::allOrEmpty()` or try/catch around missing file) returning `[]` when the file is missing/unreadable **for hosts-only paths only**. `save()` and Home server CRUD still require a real env file.
- Duplicate checks:
  - Always against `hosts.extra.json`.
  - Against server `DOMAIN_NAME` only when env was successfully loaded.
- `listedDomains` / `desiredDomains` / `manualHint`: when servers = `[]`, still include extras so sync payloads stay correct.

### Hosts scripts

- Remove hard-fail `env.json not found` from `add_hostname.sh` and `add_hostname.ps1`.
- Domain collection:
  - If `env.json` exists → read `SERVER_NAME*` `DOMAIN_NAME` values (same as today).
  - Always merge `runtime/hosts.extra.json` when present.
  - Empty env + non-empty extras (or both empty) is valid; write managed hosts block accordingly (including clearing managed domains when the desired set is empty).
- Bash `read_domains`: do not run `jq` on a missing `env.json`; only extras path must run when extras exist.
- Keep watch / elevation / status file behaviour unchanged.

### UI

No structural change. Domains modal remains “domain name only”; copy already states hosts-only. Optional i18n tweak later if messaging still mentions env as required for hosts-only (out of scope unless misleading).

## Error handling

| Case | Behaviour |
|------|-----------|
| No `env.json`, add hosts-only domain | Save extras + request sync; succeed |
| No `env.json`, run add_hostname | Apply extras (or none); do not error on missing env |
| Env exists, domain duplicates a server | 422 `validation.duplicate_domain` |
| Env exists, domain duplicates an extra | 422 `validation.duplicate_domain` |
| Home add server without env | Unchanged: still `error.env_missing` |

## Manual test plan

1. Ensure `env.json` is absent (or rename it temporarily).
2. `POST /api/domains` with a new hostname → `runtime/hosts.extra.json` contains it; no env error.
3. Run `add_hostname.ps1` / `add_hostname.sh` → OS hosts managed block includes `127.0.0.1 <domain>`.
4. Restore `env.json` with at least one server → full sync still lists server domains + extras.
5. Home create server without env still fails as today.

## Implementation order

1. Hosts scripts: optional env + extras-only write.
2. Backend: safe empty servers for hosts-only DomainController / listedDomains paths.
3. Smoke the manual test plan above.
