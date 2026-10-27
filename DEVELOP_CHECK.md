# develop-check: the ivpl/xprmt tests against upstream develop's code

## Why this exists
`ivpl/xprmt` is upstream `develop` (f666d6dc) plus 40+ commits: a restructured entry point (`public/`, `bootstrap/`),
the PHPUnit harness, the per-advisory test PRs, and fork-only fixes. A green suite there therefore proves *the fork*,
not `develop`. `develop` is released once its advisories are fixed, so the question that matters is whether the same
tests pass against develop's own `application/`. This branch is that experiment, kept reproducible.

## What the branch contains
Parent: `ivpl/xprmt` at 2d4491f2 (all twelve test PRs #89-#100 merged, inventory regenerated, pint applied; the client
comma-email branch is not included). On top of it:
- `application/` replaced by `upstream/develop` (f666d6dc) wholesale: `git checkout upstream/develop -- application`.
  39 files differ from ivpl/xprmt (everything listed under "fork-only" in
  `_notes/ivpl-xprmt-vs-upstream-develop-2026-10-27.md`).
- `application/libraries/gateways/PaypalLib.php` and `.../guest/controllers/gateways/Stripe.php` taken from commit
  8e4f2c6f: develop plus the testing-only HTTP replay seam, nothing else. Without the seam the ~70 PayPal/Stripe tests
  cannot run at all (they would hit the live APIs), which says nothing about develop's logic.
- `tests/Support/develop-check.sh` and this file.
Not committed (git-ignored): `ipconfig.php` (copied from the main checkout) and `vendor/` (a real copy).

## Reproduce
```bash
# from the ivpl/xprmt checkout; builds ../ivpl-develop-check and runs the whole suite in the ivpldock container
tests/Support/develop-check.sh --with-seam
# only a subset
tests/Support/develop-check.sh --with-seam -- tests/Feature/Payments
# pure develop, no seam
tests/Support/develop-check.sh
```
Pitfalls found the hard way:
1. `vendor/` must be a real copy. A symlink to the main checkout's vendor makes Composer resolve the original paths,
   so the request subprocesses (`tests/Integration/bin/request.php`) execute the *fork's* application code. The first
   run did exactly that and its 42 failures were meaningless.
2. The test database is shared and every test truncates it: never run two suites at once (this checkout and the main
   one) and never run mutation scripts at the same time.
3. Do not `git checkout -- <file>` in a mutation runner on a tree with uncommitted edits.

## Result (2026-10-27), upstream/develop f666d6dc
Full suite, no seam: 1774 tests, 18 errors, 96 failures. With the seam, the PayPal/Stripe group drops to 5.

| Group | Failing | Cause | Advisory |
|---|---|---|---|
| PayPal/Stripe tests without seam | ~70 | testing seam absent | none (m2c5, w6fv, rf9w pass once the seam is present) |
| After seam: missing URL segment / Stripe client built in the constructor | 5 | ArgumentCountError / TypeError / InvalidArgumentException instead of 404 (open upstream PR #1829) | r382 (partial) |
| Mailer CC/BCC | 11 | restriction not in develop (open upstream PR #1817) | qw48 (partial) |
| `Users/Ajax::save_user_client` | 3 | `Class "Mdl_Users" not found` at Users/Ajax.php:129: the guard introduced for 5rqm calls a model that is never loaded, so the endpoint fatals for every request carrying a user id | 5rqm (defect in develop) |
| Everything else | rest | fork-only hardening: settings validation, invoice/quote item ownership, aging report, archive glob, JSON CSRF token, delete flash/redirect, null-argument handling, `parse_template(null)`, coverage inventory | none |

All other advisory tests (hmqm, h4xh, 9jmq, wcqc, wj32, wv2c and the remaining ones) pass on develop's unmodified code.
Conclusion: 50 of 53 advisories hold on develop; qw48, r382 and 5rqm do not yet.
