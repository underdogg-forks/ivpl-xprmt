---
name: mutation-check
description: >-
  Proves that tests can actually fail. Breaks production code one small way at a
  time (flipped comparison, swapped &&/||, removed negation, changed constant,
  forced if-branch, deleted statement), runs the tests, and reports which
  mutants the tests killed and which survived. Use it on any test you did not
  watch fail, on security and money logic, on code changed in a PR, and whenever
  someone says "the tests are green" about code that matters. A green test
  that survives a mutation is hollow or incomplete.
---

# mutation-check

## Why this exists

A green suite only says "nothing the tests look at is broken". It says nothing about what the
tests do not look at. In this repository that gap was real:

- `assertResponseRedirectsToRoute` returned early whenever the `Location` header was empty, which
  is always the case under CLI, so every redirect-destination assertion could never fail.
- Tests such as `it_renders_the_dashboard` asserted only "no PHP errors".
- A duplicate-invoice-number test passed because the database unique index fired, not because the
  validation did, until the test asserted the validation message.

Reading a test cannot tell you whether it is hollow. Breaking the code can. If a test passes while
the code it names is broken, it is not a test of that code.

## When to run it

- After writing or editing a test, before calling it done (the "watch it fail" step of TDD).
- On every security rule, authorisation check, CSRF guard, money calculation and state transition.
- On a colleague's tests before relying on them ("covered" is a claim until a mutant dies).
- On the lines a PR changes: `--diff=origin/prep/v180`.

Do not run it on views, language files or generated code, and do not spend it on getters.

## Quick start

```bash
# 1. DB up, and nothing else running phpunit (runs share one test database)
bash tests/Support/sandbox-mariadb.sh

# 2. See what would be mutated
php .claude/skills/mutation-check/mutate.php --file=application/modules/quotes/controllers/Ajax.php --list

# 3. Mutate and test (narrow --tests: each mutant is one test run)
php .claude/skills/mutation-check/mutate.php \
  --file=application/modules/quotes/controllers/Ajax.php \
  --tests=tests/Feature/Quotes/QuotesAjaxControllerTest.php \
  --diff=origin/prep/v180 --max=30
```

Options: `--lines=120-180`, `--diff=REF`, `--max=N` (seeded sample, default 40, `0` = all),
`--timeout=SEC`, `--cmd=...` (full test command, `{tests}` is substituted), `--json=PATH`,
`--allow-dirty`. Exit codes: `0` all killed, `1` survivors, `2` usage, `3` baseline not green,
`4` infrastructure problem (database down, another phpunit running).

The script refuses to start if the baseline is red or the database is unreachable, so a dead
database can never masquerade as "all mutants killed". It restores the file after every mutant
(shutdown handler, SIGINT/SIGTERM handler, final hash check). If it ever reports that the file was
not restored: `git checkout -- <file>`. **Never commit a mutated file.**

## Operators

| Operator | Mutation | Typical gap it exposes |
|---|---|---|
| `operator` | `===`/`!==`, `==`/`!=`, `>=`→`<`, `<=`→`>`, `>`→`<=`, `<`→`>=`, `&&`↔`\|\|`, `+`↔`-`, `*`→`/` | boundary values never tested (off-by-one on dates, amounts, counts) |
| `negation-removed` | drops a `!` | guard exercised in only one direction |
| `boolean` | `true`↔`false` | flags and defaults never asserted |
| `number` | `n`→`n+1` (`0`→`1`) | limits, statuses and HTTP codes not asserted |
| `condition-forced` | `if (cond)` → `if (true)` / `if (false)` | a branch no test reaches, or reaches without checking the outcome |
| `statement-deleted` | removes a single-line `$this->...();` | side effects (logging, session destroy, CSRF check, save) not asserted |

Mutations are token based, so strings and comments are never touched. Files whose mutants fail
`php -l` are reported `INVALID` and ignored in the score. `defined('BASEPATH')` guard lines are
skipped.

## Reading the result

`KILLED` is good. `TIMEOUT` counts as killed. For each `SURVIVED` line decide which of two things
it is, and write the reason down:

1. **Missing or weak assertion.** The test runs the code but does not check the outcome. Typical
   signs: the test asserts only a status code, "no PHP errors", `assertNotNull`, or a redirect
   without state. Fix the test, not the mutant: assert the persisted state, the message, the
   exact value, or add the case the branch needs.
2. **Equivalent mutant.** The mutation does not change behaviour (a log message, a cache warm-up,
   `$i < 10` vs `$i <= 9`, a value overwritten later, a defensive branch that cannot be reached).
   State why it is equivalent. If you cannot, treat it as case 1.

Never delete a surviving mutant's line, loosen the mutation, or mark it equivalent without a reason.
Never "fix" a survivor by making the test mirror the implementation (re-computing the expected
value with the production code); assert a value you worked out by hand.

After strengthening a test, re-run the same command and confirm the mutant now dies. Also confirm
the baseline is still green.

## TDD loop with it

1. Write the failing test (red), implement (green).
2. Run `mutate.php` on the new or changed lines.
3. Every survivor is a missing test. Add it, watch it fail against the mutant, keep it.
4. Repeat until only reasoned equivalents remain.

## Manual mutation (when the script does not fit)

For one spot the script cannot express (a string, a method call, a SQL clause):

```bash
cp path/to/File.php /tmp/File.bak
# edit one thing in File.php
<run the narrow test command>        # expect RED
cp /tmp/File.bak path/to/File.php
git diff --quiet -- path/to/File.php && echo restored
```

One mutation at a time, always restore, always check `git diff` is empty before committing.
If a run has to be stopped, find the PID with `ps` and kill that one; `pkill -f` can take your own shell down with it.

## Rules for this repository

- One phpunit process at a time; the script aborts if another one is running.
- Do not export `DB_*` (see CLAUDE.md); the default command unsets them.
- Feature tests spawn a subprocess per request, so a Feature mutant costs seconds. Narrow
  `--tests` to the one test file or `--filter`, and use `--max`/`--diff`.
- Report results as `file: N mutants, K killed, S survived (reasons)`; do not claim a file is
  "covered" while survivors without a reason remain.
- Survivors in security code (authorisation, CSRF, path validation, output escaping) block the
  change; survivors in presentation code do not.

## Limits

One mutation at a time, no coverage-guided ordering, no equivalence detection, token-level
operators only (no return-value or array mutations). It finds hollow assertions; it does not find
missing features. Pair it with `config-parity-guard` for endpoint-level gaps.
