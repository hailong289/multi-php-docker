[English](CONTRIBUTING.md) | [Tiếng Việt](CONTRIBUTING.vi.md)

# Contributing

Thanks for helping improve multi-php-docker. Bug reports, feature ideas, documentation fixes, and pull requests are all welcome.

## Reporting a bug

Open a [bug report](https://github.com/hailong289/multi-php-docker/issues/new?template=bug_report.yml) instead of messaging privately, so the problem is tracked and others can find it. The form asks for:

- Steps to reproduce, expected and actual behavior
- OS, `docker --version`, `docker compose version`
- Relevant logs (`docker compose logs <service>`, Manager UI errors, files under `runtime/` or `php-controller-runtime/status/`)

Search [existing issues](https://github.com/hailong289/multi-php-docker/issues) first. Never paste passwords, tokens, or private project paths.

## Suggesting a feature

Open a [feature request](https://github.com/hailong289/multi-php-docker/issues/new?template=feature_request.yml) describing the problem you want solved, not only the solution. For large changes, wait for agreement on the issue before writing code.

## Branches

`master` holds released code. All work goes into `develop` first, and `develop` is merged into `master` on release.

```bash
git checkout develop
git pull origin develop
git checkout -b feature/short-description
```

| Prefix | Use for | Example |
| --- | --- | --- |
| `feature/` | New feature or improvement | `feature/manage-port` |
| `bugfix/` | Bug fix | `bugfix/fix-path-project` |
| `docs/` | Documentation only | `docs/contributing` |
| `chore/` | Build, CI, or tooling | `chore/pin-runner` |

## Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/): `type(scope): summary`, imperative mood, no trailing period.

```text
feat(manager): make service host ports configurable
fix(nginx): keep previous config when reload fails
docs: move contributing guide to CONTRIBUTING.md
```

Types: `feat`, `fix`, `perf`, `refactor`, `docs`, `chore`, `ci`, `test`. Common scopes: `manager`, `php`, `nginx`, `tabs`, `hosts`, `ci`. Explain *why* in the body when it is not obvious.

## Before opening a PR

```bash
# Manager unit checks
docker compose run --rm --no-deps -v ./server/manager/backend:/app/backend \
  --entrypoint php manager backend/tests/run_unit_checks.php
# Rebuild what you changed (manager, php-8.x, ...)
docker compose build <service> && docker compose up -d <service>
```

- New UI strings go in both `en.js` and `vi.js`.
- Never commit `env.json`, `.env`, `runtime/`, certificates, or `server/source/`.

## Pull requests

Push your branch, open a PR into **`develop`**, fill in the template, and link the issue (`Fixes #123`). One topic per PR; add screenshots for UI changes.
