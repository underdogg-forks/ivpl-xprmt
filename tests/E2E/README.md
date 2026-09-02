# End-to-end tests (Playwright)

Browser tests for the CI3 InvoicePlane app. Adapted from the InvoicePlane v2
Playwright suite; the Filament/multi-tenant pieces were dropped.

## Layout

| file | role |
| --- | --- |
| `../../playwright.config.js` | project root config — `testDir: tests/E2E`, Chromium, HTML + error-summary reporters, optional `php -S` web server |
| `config.js` | env-driven `E2E_BASE_URL` / `E2E_EMAIL` / `E2E_PASSWORD` and the login/logout paths |
| `router.php` | front controller for PHP's built-in server (`php -S … tests/E2E/router.php`) — routes clean and `/index.php/*` URLs, serves real static files |
| `global-setup.js` | logs in once, writes the session to `.auth/admin.json` |
| `auth-helpers.js` | `login()` / `logout()` / `isAuthenticated()` for tests that exercise auth itself |
| `test.js` | `import { test, expect } from './test.js'` — same as `@playwright/test` plus per-test console/pageerror capture |
| `error-summary-reporter.js` | end-of-run consolidated `error-report.md` of every captured browser error |
| `smoke.spec.js` | example specs: login form renders, guest is redirected, admin reaches the dashboard / integrations pages |

## Run it

```bash
npm install                 # pulls @playwright/test (added to package.json devDependencies)
npm run e2e:install         # one-time: download the Chromium binary
npm run e2e                  # or: npm run e2e:ui
```

> `@playwright/test` was added to `package.json` but this repo's `yarn.lock` is
> Yarn Berry format and can only be regenerated with Berry (`yarn install`),
> which was not available where the scaffold was built. Run Berry `yarn install`
> once to sync the lockfile and commit it. The E2E CI job uses `npm install`, so
> it already resolves the dependency.

With nothing configured, the config starts `php -S localhost:8000 -t . tests/E2E/router.php`
itself and drives that. It needs a working `ipconfig.php` pointing at a
**seeded** database — the same schema/seed CI builds:

```bash
for f in $(ls application/modules/setup/sql/*.sql | sort); do mysql … "$DB_DATABASE" < "$f"; done
mysql … "$DB_DATABASE" < tests/Support/schema_fixups.sql
php tests/Support/seed-test-db.php
```

The seed creates `admin@test.local` / `password` — the defaults in `config.js`.

### Point at an already-running instance

```bash
E2E_BASE_URL=http://localhost npm run e2e
```

When `E2E_BASE_URL` is set (or `CI` is set) the config does **not** start its
own server. For the cleanest URLs set `REMOVE_INDEXPHP=true` and `IP_URL` in
that instance's `ipconfig.php` (the CI workflow does this); the router and the
specs also work with the default `/index.php/*` scheme.

## CI

`.github/workflows/e2e-tests.yml` — MariaDB service, schema + seed, `php -S`,
`npx playwright install --with-deps chromium`, `npm run e2e`. Runs on
`prep/v180` pushes and PRs; the HTML report + `error-report.md` are uploaded
as an artifact.
