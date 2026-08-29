---
description: Test layers, placement rule, suite hygiene, and the canonical command table
paths: ["**"]
---

# Testing

Where a test goes and how suites are run. Assertion quality and case selection live in
`test-design.md`; browser specifics in `e2e-tests.md`; guards and gates in
`backpressure.md` and `precommit-hooks.md`.

## Layers

| Layer | Needs | Budget | What it can witness |
|---|---|---|---|
| Unit | nothing — no DB, no HTTP, no framework bootstrap | < 1s total | one function's behaviour |
| Integration | the stack up; real DB, real HTTP endpoint | seconds | two parts meeting across a real boundary |
| E2E | the stack up + a real browser | slowest | the shipped page as a user sees it |

A unit layer only exists if production code can be loaded without its runtime. Keep pure
logic in files that declare functions and nothing else, so a bootstrap can include them
with no database and no framework. If a "unit" test needs the framework booted, it is not
a unit test — either push the logic down into a pure function or move the test up a layer.

## Placement rule

Put each behaviour at the **lowest layer that can express it**, and do not restate it
higher up. When placement is unclear, ask what must break for the test to fail:

- one function → unit
- two parts meeting across a real boundary → integration
- the rendered page / real browser behaviour → E2E

**Anti-regression cross-check**: break a low-level function and its own unit test must go
red *before* the E2E test does. If the E2E test fails first, coverage has not been pushed
down far enough — push the rule down instead of adding browser coverage.

**Server-rendered source is not the DOM.** A page-source assertion witnesses only what the
server emitted. Anything assembled at runtime by client-side JavaScript is unreachable
there and belongs in E2E. An assertion whose name claims a DOM fact but which greps page
source is worse than no test: it passes on pages where the behaviour is absent.

If a rule is witnessed only by a higher-layer test, that is a gap in the pyramid. Close it
by pushing the rule down, not by strengthening the browser test.

## Test accounts

A suite never logs in as a human's real account. Every role a suite needs gets its own
dedicated account with a generated password, created by a script that is committed and
re-runnable — so a fresh clone is one command away from a working suite, rotating a password
is one command, and nobody has to hand their own credentials to a test run or to an agent.

- **One account per role the tests actually exercise**, not one shared account that is given
  whatever rights the newest test needs. An admin gate is only proven by an authenticated
  non-admin failing to pass it; that needs a second account, so create it.
- **Create as many as the cases require.** A missing role is a `[NEG]` case that silently
  never gets written.
- **The script is idempotent** — running it again resets the passwords rather than erroring
  or creating duplicates — and it **asserts the account came out with the role it asked for**
  instead of trusting the write.
- **Passwords are generated, never typed, and never committed.** They land in a git-ignored
  file that the suite reads through environment variables; the missing-variable message names
  the script that creates them.
- **The accounts are named for what they are** (`<plugin>_webmaster`, `<plugin>_normal`) so a
  glance at the user list says which rows are test scaffolding.
- These accounts are dev-install scaffolding. Like the suites themselves, the script is never
  safe to point at production, and that is stated where the command is documented.

In this repo: `plugins/provenance/tests/Support/TestUsers.php` declares the roles,
`create-test-users.php` creates them, and the credentials land in the git-ignored
`local/config/provenance-test.env`.

## Suite hygiene

- Every suite is runnable by **one command**, and that command is written down.
- Fail on warnings and on risky/assertion-free tests. That setting enforces "a test that
  asserts nothing must not pass" — do not relax it, and do not silence a risky test by
  asserting something trivial.
- Suites pass **twice in a row** and **in reverse order** with no manual repair. Anything
  else is a hidden inter-test dependency or a fixture that does not restore.
- Integration and E2E suites mutate real state. They are never safe against a production
  database, and that is stated in the docs rather than assumed.
- Set explicit timeouts on anything that can hang.
- Never report a lint or test pass that no command produced. Name the command that ran.

## Working discipline

- Each task ships its own tests. Never defer to a separate "add tests" task.
- Bugs: write the reproducing test first, watch it fail for the right reason, then fix.
- Production code and test code are not edited in the same cycle — alternate.
- Run the affected suite after every change; fix and rerun until clean. Documentation-only
  changes are the only exception.
- Automate every testing step that can be automated. What stays manual is recorded with the
  reason (see the hand-check ledger in `test-design.md`).
- A superseded test file is **deleted, not kept alongside** its successor — two suites mean
  two definitions of the truth. Every assertion gets a named successor first, and the
  mapping goes in the commit message.

### Cover the ground before you move it

Before changing, extending, or refactoring existing functionality, first bring that area to
exhaustive coverage of how it behaves **today**. Those tests land and pass on their own,
before a line of the change is written. They are the regression net the edit is made
against; written afterwards, they only record whatever the edit happened to produce.

The order is fixed:

1. **Characterize the area as-is.** Cover it at the lowest layer that can express it
   (*Placement rule* above), to the equivalence classes and boundaries in `test-design.md`.
   Run it green, commit it separately from the change.
2. **Make the change**, itself governed by the normal discipline — test-first for the new
   behaviour, production and test code in alternating cycles.
3. **Re-run the area's suite as the regression check.** Name the command that ran.

Three things this rule does not license:

- **These tests do not prove the code correct.** Their oracle is the implementation, so
  they are characterization tests: tag them `[ERR]` and declare the oracle, per *a test
  whose oracle is the code must say so* in `test-design.md`. Where a requirement does exist
  for some part of the area, write that part against the requirement instead and tag it
  accordingly — a requirement is a better oracle than the code, and only what has no
  requirement behind it is `[ERR]`.
- **They pass on the first run, which normally is the tell** that a test recorded code
  rather than drove it (*watch it fail first*). Here that is the point, so the strength
  check moves: prove each one can fail by breaking the behaviour it claims to watch, per
  *proving a check can actually fail*. A characterization test nobody has watched go red is
  not yet a regression net.
- **"Exhaustive" is bounded by the blast radius**, not by the file or the module. Cover
  what the change can reach. For a large untested area, that bound is the ratchet in
  `backpressure.md` — a demand to cover everything first gets abandoned, and then there is
  no net at all.

When a characterization test goes red after the change, that is a decision, not
automatically a bug: either the change is wrong, or it deliberately replaced recorded
behaviour. Say which, and if the latter, update the test in its own cycle with the reason —
never loosen one to get a run green.

## This repo's commands

```bash
# Unit
ddev exec plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml
# Integration (DDEV up; credentials from the environment)
ddev exec bash -c 'TYPETAGS_TEST_USERNAME=<user> TYPETAGS_TEST_PASSWORD=<pass> \
  plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'
# provenance: create the test accounts once, then source them
ddev exec php plugins/provenance/tests/Support/create-test-users.php
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'
# provenance E2E (DDEV up; the album properties screen is admin-only)
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  cd plugins/provenance && npx playwright test'
# Both
ddev exec bash -c 'TYPETAGS_TEST_USERNAME=<user> TYPETAGS_TEST_PASSWORD=<pass> \
  plugins/typetags/vendor/bin/phpunit --configuration plugins/typetags/phpunit.xml'
# E2E (DDEV up; the assignment UI only renders for a logged-in user)
ddev exec bash -c 'cd plugins/typetags && TYPETAGS_TEST_USERNAME=<user> TYPETAGS_TEST_PASSWORD=<pass> \
  npx playwright test'
# Syntax check at container PHP version
ddev exec php -l <file>
# Hook self-test
bash tools/test-hooks.sh
```

A fresh clone needs `ddev exec composer install -d plugins/typetags` and
`ddev exec bash -c 'cd plugins/typetags && npm install'` first. Dependency and run output
(`vendor/`, `node_modules/`, `test-results/`, `playwright-report/`, the pinned browser
cache, the E2E suite's `tests/e2e/.state/`) is git-ignored by the submodule's own
`.gitignore`.

The E2E suite mutates the database like the integration suite does. It seeds through
`tests/e2e/support/seed.php`, which snapshots the original state to
`tests/e2e/.state/snapshot.json` and restores it in `afterEach`.
