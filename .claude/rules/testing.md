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

## This repo's commands

```bash
# Unit
ddev exec plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml
# Integration (DDEV up)
ddev exec plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml
# Both
ddev exec plugins/typetags/vendor/bin/phpunit --configuration plugins/typetags/phpunit.xml
# E2E
ddev exec bash -c 'cd plugins/typetags && npx playwright test'
# Syntax check at container PHP version
ddev exec php -l <file>
# Hook self-test
bash tools/test-hooks.sh
```

A fresh clone needs `ddev exec composer install -d plugins/typetags` and
`ddev exec bash -c 'cd plugins/typetags && npm install'` first. Dependency and run output
(`vendor/`, `node_modules/`, `test-results/`, `playwright-report/`, the pinned browser
cache) is git-ignored by the submodule's own `.gitignore`.
