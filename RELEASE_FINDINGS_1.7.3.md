# Release Findings — InvoicePlane 1.7.3

**Branch:** `ivpl/repairs-devcheck` (commit `394f19a2`)
**Based on:** `ivpl/develop-check` whose `application/` is upstream `develop` f666d6dc (wholesale checkout), tests from ivpl/xprmt
**PHP:** 8.4.27 CLI + Xdebug 3.5.3
**Database:** MariaDB (ivpldock stack), schema `application/modules/setup/sql/000_1.0.0.sql`…`044_1.7.3.sql`
**Date:** 2026-10-28

---

## 1) Deprecation fixes applied (screen cleanliness)

**Commit `394f19a2` — `fix: silence rawurlencode null deprecation in _csrf_query()`**

- `application/helpers/echo_helper.php:110`: `rawurlencode($CI->security->get_csrf_hash())` → `rawurlencode((string) $CI->security->get_csrf_hash())`
- `get_csrf_hash()` returns `null` when CSRF isn't initialized; PHP 8.1+ deprecates passing `null` to `rawurlencode()`.
- This was the "A PHP Error was encountered / Severity: Deprecated Notice / rawurlencode(): Passing null to parameter #1 of type string is deprecated" notice surfaced on `application/modules/dashboard/views/index.php:169` via `_csrf_query()`.
- `php -l application/helpers/echo_helper.php` passes. No syntax errors.

No other `rawurlencode()`/`urlencode()` calls pass a possibly-null value (the only other one, `Sessions.php:166`, already casts `(string)`).

---

## 2) PHPUnit results — full suite

**Command:** `make docker-test` equivalent — `vendor/bin/phpunit --no-coverage` inside `ivpldock-workspace-1`, DB `ivpl_db`, `env -u DB_HOSTNAME -u DB_PORT -u DB_DATABASE -u DB_USERNAME -u DB_PASSWORD`

**Result: 1866 tests, 65 errors, 297 failures, 22 warnings, 2 skipped, 1 incomplete — exit code 2**

Full output saved to: `phpunit-results-repairs-devcheck.txt` (15,827 lines)

No deprecation/notices appeared on screen during the run (the earlier `_csrf_query()` notice is fixed). All failures are assertion/runtime exceptions, not PHP deprecations.

### Failure count by test class (top groups)

| Test class | Failures |
|---|---|
| Core\SettingsControllerTest | 26 |
| Invoices\InvoiceItemAmountSignTest | 21 |
| Invoices\InvoicesAjaxControllerTest | 17 |
| Quotes\QuotesAjaxControllerTest | 13 |
| Payments\StripeFlowTest | 13 |
| Security\GeneratePdfCsrfGateTest | 11 |
| Security\MailerCcBccAuthorizationTest | 10 |
| Quotes\GuestQuotesControllerTest | 10 |
| Quotes\QuoteItemAmountSignTest | 8 |
| Payments\PaypalCapturePreflightTest | 7 |
| Core\UsersControllerTest | 7 |
| Users\UserClientsIdorTest | 6 |
| Products\UnitsControllerTest | 6 |
| Payments\PaypalFlowTest | 6 |
| Models\ReportsModelTest | 6 |
| Core\Security\SecurityRegressionTest | 6 |
| Core\FilterAjaxControllerTest | 6 |

### Failure type breakdown (by error message)

| Error type | Count |
|---|---|
| `Failed asserting that null is not null` | 60 |
| `Failed asserting that two strings are identical` | 42 |
| `Table definition has changed, please retry transaction` (MySQL 1412) | 14 |
| `api_key cannot be the empty string` (Stripe) | 16 (7 shown in top list) |
| `DateMalformedIntervalStringException` | 11 |
| `Failed asserting that null is identical to 1` | 13 |
| `Failed asserting that 0 is identical to 1` | 13 |
| `Failed asserting that true is false` | 11 |
| `Failed asserting that null is identical to 0` | 11 |
| `Attempt to assign property "payments" on null` | 6 |
| `Wrong cron key provided` | 3 |

### Notable categories

**A) `Table definition has changed` (MySQL 1412) — 14 occurrences**
- Triggered in `Mdl_invoice_amounts.php:98` (`SELECT SUM(payment_amount) FROM ip_payments WHERE invoice_id = N`) and other aggregate queries inside transactions.
- MariaDB 1412 means the table definition changed mid-transaction. Likely a schema/txn isolation mismatch between imported develop schema and develop's query patterns. Needs investigation against a clean develop schema import.

**B) Stripe `api_key cannot be the empty string` — 16 occurrences**
- `StripeFlowTest`, `PaypalCapturePreflightTest`, etc. The Stripe gateway library is instantiated with an empty API key in the test environment. Develop's code doesn't guard against an empty key before calling the Stripe SDK. Related to open upstream advisory r382 / PR #1829.

**C) `DateMalformedIntervalStringException` — 11 occurrences**
- `Mdl_invoices.php:486`: `DateInterval` constructed from a malformed string (starts with "PD"). Quote/invoice expiry date computation. Related to qw48/r382-area gateway/date handling.

**D) `null is not null` — 60 occurrences**
- Controllers/models returning `null` where the test expects an array/object. E.g. `EmailTemplatesAjaxGetContentTest` → `Base_Controller::json_encode_ajax(): Argument #1 ($response) must be of type object|array, null given` (`Ajax.php:27`).

**E) `Attempt to assign property "payments" on null` — 6 occurrences**
- `Mdl_invoices.php:473`: `generate_pdf` path where the invoice record is null. PDF generation for missing/null invoice.

---

## 3) Playwright E2E — status

The app **boots successfully** via `php -S` on port 8000 with `IP_URL=http://localhost:8000` (overriding ipconfig.php's `IP_URL=http://ivpldc.test` which caused absolute-URL mismatches). Login works via `curl` (303 to `/dashboard`).

**E2E could not complete:** Playwright's `refreshAdminSession()` (globalSetup) fills `email`/`password` and clicks the login submit button, but the browser stays on `/sessions/login` — navigation never occurs. curl confirms credentials are correct (`admin@test.local` / `password`) and the endpoint returns 303. Likely a CSRF cookie / session-cookie handling difference between curl and a real Chromium context, or `variables_order`/env propagation into the PHP server.

**E2E blocker documented:** needs a debugging session with headed browser + network inspection. The full 50+ spec suite (clients, invoices, quotes, payments, products, projects, settings, users, reports, security, setup) was not executed.

To retry E2E locally:
```bash
# Terminal 1: app server
cd /var/www/projects/spotivel/ivpl-devcheck
env IP_URL=http://localhost:8000 DB_HOSTNAME=127.0.0.1 DB_PORT=3306 DB_DATABASE=ivpl_db DB_USERNAME=root DB_PASSWORD=root CI_ENV=development \
  php -d variables_order=EGPCS -S 0.0.0.0:8000 -t . bootstrap/test-server.php

# Terminal 2: DB reset (once)
DB_HOSTNAME=127.0.0.1 DB_PORT=3306 DB_DATABASE=ivpl_db DB_USERNAME=root DB_PASSWORD=root php tests/Support/reset-test-db.php

# Terminal 3: E2E
DB_HOSTNAME=127.0.0.1 E2E_BASE_URL=http://localhost:8000 E2E_EMAIL=admin@test.local E2E_PASSWORD=password \
  npx playwright test tests/E2E/smoke.spec.js
```

---

## 4) Manual smoke test (what I verified by hand)

| Check | Result |
|---|---|
| `php -l application/helpers/echo_helper.php` | Pass |
| App boots on `http://localhost:8000/sessions/login` | HTTP 200 |
| Login form renders with email/password/CSRF fields | Present |
| `POST /sessions/login` with valid creds | HTTP 303 → `/dashboard` |
| DB schema imported (`000_1.0.0`…`044_1.7.3` + fixups) | All 45 files imported OK |
| `reset-test-db.php` truncate + reseed | Exit 0 |
| PHPUnit bootstrap / DB connection | 1866 tests executed (not all skipped) |

---

## 5) Tracking file — `tests/Support/class-coverage-inventory.md`

Current state (from the file itself):

| Status | Classes |
|---|---|
| covered | 49 |
| partial | 36 |
| missing | 14 |

This file is **generated** by `tests/Support/generate-class-coverage-inventory.php`. It was **not regenerated** after this run because:
- The suite is red (297 failures / 65 errors), so coverage data is not representative.
- Regenerating it now would record coverage against develop's broken application, not the 1.7.3 release candidate.

**Recommendation:** regenerate only after the phpunit suite is green:
```bash
php tests/Support/generate-class-coverage-inventory.php
```

---

## 6) What this branch actually is

`ivpl/repairs-devcheck` is the **develop-check experiment**: `application/` = upstream `develop` f666d6dc, tests = ivpl/xprmt. It is **not** the 1.7.3 release candidate branch. The extensive failures (297) are largely the expected gap where develop's unmodified application doesn't satisfy ivpl/xprmt's tests — this is the experiment's purpose (DEVELOP_CHECK.md documents 50/53 advisories hold on develop; qw48, r382, 5rqm do not).

**Do not ship this `application/` as 1.7.3.** The release candidate must be the actual 1.7.3 branch with its own `application/`, not upstream develop.

---

## 7) Immediate next steps before 1.7.3 release

1. **Confirm the release branch** — switch to the branch containing the actual 1.7.3 `application/` (this branch has upstream develop's code).
2. **Re-run phpunit against the release candidate** — expect a different (hopefully much smaller) failure set.
3. **Fix the MySQL 1412 `Table definition has changed` errors** — schema/txn isolation mismatch in `Mdl_invoice_amounts` and related aggregate queries.
4. **Fix Stripe empty API key** — guard gateway SDK calls when `api_key` is empty (r382 / PR #1829 area).
5. **Fix `DateMalformedIntervalStringException`** — date-interval computation in `Mdl_invoices.php:486`.
6. **Resolve E2E Playwright login hang** — debug Chromium session/CSRF cookie handling against `http://localhost:8000`.
7. **Regenerate `class-coverage-inventory.md`** once the suite is green.
8. **Re-run the full phpunit + E2E suite** until green before tagging 1.7.3.
