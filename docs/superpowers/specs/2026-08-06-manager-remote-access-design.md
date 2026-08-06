# Design: Server Manager remote access (main server)

**Date:** 2026-08-06  
**Status:** Approved for planning  
**Scope:** Opt-in remote access to Server Manager behind Nginx HTTPS with username/password login.

## Problem

Server Manager is bound to `127.0.0.1:8080` and has **CSRF but no login**. That is fine for a laptop, but not usable as a control panel on a primary VPS/server. Publishing the current Manager without auth would expose container control and (with the current compose mount) a read-only Docker socket surface.

## Goals

- Use Manager from a public or semi-public host via a dedicated domain and HTTPS.
- Require username/password when remote mode is enabled.
- Keep current localhost-only behavior as the **default** (opt-in remote).
- Reuse existing nginx + `env.json` / Compose patterns where practical.

## Non-goals

- OAuth / SSO.
- Multi-user RBAC / per-user permissions.
- Changing php-controller allowlist model or moving container control off file IPC.
- Making Manager a general-purpose production APM/panel for customer traffic (still a **trusted-admin** tool).

## Decisions (locked)

| Topic | Choice |
| --- | --- |
| Exposure | Opt-in remote (`MANAGER_REMOTE=1`) |
| Auth | Username + password (session) |
| TLS / edge | Nginx reverse proxy + HTTPS domain |
| Default | `127.0.0.1:8080`, no login required |

## Architecture

```text
Browser --HTTPS--> Nginx (manager domain)
                      |
                      v  proxy_pass http://manager:8080
                   Manager (PHP built-in server)
                      |
          +-----------+-----------+
          |                       |
     session auth            php-controller IPC
     (remote only)           (+ optional docker.sock:ro for status)
```

- **Local mode (`MANAGER_REMOTE=0`, default):** unchanged publish `127.0.0.1:8080:8080`. CSRF only. No login page required.
- **Remote mode (`MANAGER_REMOTE=1`):** Nginx vhost for `MANAGER_DOMAIN` terminates TLS and proxies to `manager:8080` on `app-network`. All UI and `/api/*` (except login/session bootstrap endpoints listed below) require an authenticated session. Host port may remain loopback for emergency local admin; docs recommend not publishing `0.0.0.0:8080`.

## Configuration

Document and example-env entries:

| Variable | Meaning |
| --- | --- |
| `MANAGER_REMOTE` | `0` (default) or `1` |
| `MANAGER_USERNAME` | Required when remote=1 |
| `MANAGER_PASSWORD` | Required when remote=1 (plain for v1; optional later hash) |
| `MANAGER_DOMAIN` | Hostname for Nginx vhost (e.g. `manager.example.com`) |

Rules:

- If `MANAGER_REMOTE=1` and username/password missing or empty → refuse authenticated routes; do not enable a working remote vhost until credentials are set (fail closed).
- Password stored in env for v1 (operator-managed secrets). Prefer strong random password; document rotation.
- Optional follow-up (out of v1 if time-boxed): `MANAGER_PASSWORD_HASH` (bcrypt) instead of plain password.

Compose / runtime:

- Pass the new env vars into the `manager` (and nginx template generation if needed) services.
- Keep default ports mapping `127.0.0.1:8080:8080`.
- Do **not** switch default bind to `0.0.0.0`.

## Authentication design

### Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/session` | public | CSRF token + `{ authenticated, remote }` |
| POST | `/api/login` | public | username/password → session |
| POST | `/api/logout` | authenticated (or CSRF) | destroy session |
| * | other `/api/*` | authenticated when remote=1 | existing APIs |
| GET | SPA routes | frontend gate when remote | Vue router |

When `MANAGER_REMOTE=0`, `/api/login` may no-op or return “not required”; existing APIs keep CSRF-only behavior.

### Session

- PHP session (already used for CSRF).
- Cookie: `HttpOnly`; `SameSite=Lax` (or `Strict` if same-site only); `Secure` when request is HTTPS or `X-Forwarded-Proto=https`.
- Trust proxy headers only from Nginx on the Docker network (document that Manager must not be published openly to the internet without the proxy).

### Login hardening (v1)

- Constant-time password compare.
- Simple per-IP rate limit on `POST /api/login` (e.g. lockout window after N failures).
- No user enumeration detail in error messages.

### Frontend

- Login view when remote and unauthenticated.
- Redirect unauthenticated API callers / clear session on 401.
- Replace “Local access only · 127.0.0.1:8080” badge with mode-aware copy (local vs remote domain).

## Nginx

- Dedicated Manager template (or generate from env when remote=1), similar to app vhosts but `proxy_pass http://manager:8080` with standard proxy headers (`Host`, `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto`).
- Domain from `MANAGER_DOMAIN`.
- TLS: reuse project cert approach / document mounting certs or Let’s Encrypt; exact ACME automation may be documented rather than fully automated in v1.
- Manager vhost generation must not require a row in `env.json` app servers (or use a clearly separate config path so app domain list stays clean).

## Security notes

- Remote Manager is **admin-equivalent** on the host Docker environment. README and Docker Hub copy must warn: use strong credentials, HTTPS only, restrict by firewall/IP when possible.
- Do not advertise remote mode as “safe for the public internet without additional controls.”
- Prefer keeping Docker socket mount limited; README currently drifts from compose (socket is mounted read-only on Manager for live status)—call this out and avoid widening socket permissions.

## Docs

Update `README.md` / `README.vi.md`:

- Local vs remote modes.
- Env table and fail-closed rules.
- Example enable steps: set env → DNS → TLS → `docker compose up -d` → open `https://MANAGER_DOMAIN`.
- Explicit warning against `0.0.0.0:8080` without auth.

## Testing

- Local mode: Manager on 127.0.0.1 works without login; CSRF still required for mutating APIs.
- Remote mode without credentials: locked / no usable remote panel.
- Remote mode with credentials: login, CSRF+auth for APIs, logout.
- Unauthenticated remote API returns 401.
- Nginx proxy + `X-Forwarded-Proto` yields Secure session cookie behavior.
- Rate limit blocks rapid login attempts.

## Implementation outline (for later plan)

1. Backend: remote flag, login/logout, auth middleware, rate limit, cookie flags.
2. Frontend: login page, session gate, i18n badge/copy.
3. Nginx template + generation / include path for Manager domain.
4. Compose + `.env.example` (or documented env) wiring.
5. README EN/VI (+ optional Docker Hub blurb note).

## Out of scope follow-ups

- Bcrypt-only passwords.
- IP allowlists in Nginx.
- 2FA.
- Removing Docker socket from Manager in favor of status-only via php-controller.
