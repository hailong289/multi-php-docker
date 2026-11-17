<!-- Target branch: develop. See CONTRIBUTING.md. -->

## Summary

<!-- What changes and why. -->

Fixes #

## Type

- [ ] Bug fix
- [ ] Feature / improvement
- [ ] Documentation
- [ ] Build / CI / tooling

## Checklist

- [ ] Manager unit checks pass (`docker compose run --rm --no-deps -v ./server/manager/backend:/app/backend --entrypoint php manager backend/tests/run_unit_checks.php`)
- [ ] Rebuilt and tested affected services (`docker compose build <service>`)
- [ ] New UI strings added to both `en.js` and `vi.js`
- [ ] No secrets, `env.json`, `.env`, or `server/source/` files committed

## Screenshots

<!-- For UI changes. -->
