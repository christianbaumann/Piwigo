# 0008 — Phase 3 of the provenance plan ships no E2E spec either

Date: 2026-08-29
Status: settled

Extends [0007](0007-no-e2e-tests-for-provenance-phases-1-and-2.md), which covered Phases 1
and 2 only. That file is not edited: it decided what it decided, and Phase 3 is a new
question because it is the first phase to add a web-service method.

## Decision

Phase 3 (history table and recorder) contributes **zero** Playwright specs, and still does not
create `tests/e2e/`. Phase 4 remains the owner of `auth.setup.js`, `tests/e2e/support/` and the
first spec.

`ddev exec bash -c 'cd plugins/provenance && npx playwright test'` therefore still exits 1 with
`No tests found` — verified 2026-08-29 after Phase 3 landed (`--list` reports
`Total: 0 tests in 0 files`). That remains the correct output, not a broken harness.

## Why

**The phase renders nothing.** After Phase 3 the plugin registers exactly two event handlers:
the folder-name guard, and `ws_add_methods`. No prefilter, no template variable, no admin tab,
no menu entry — grep `add_event_handler` in `plugins/provenance/main.inc.php` is the whole
surface. There is no DOM for a browser to observe.

**`pwg.provenance.getHistory` has no screen in this plan.** It is a read endpoint for an
administrator and for later phases; the plan assigns no UI that consumes it. A spec would have
nothing to click.

**Its two browser-relevant facts already have lower oracles.** That the method is registered at
all, and that a non-admin is refused, are both asserted over real HTTP in
`tests/Integration/HistoryTest.php` (`testNormalUserCannotReadTheHistory`,
`testGuestCannotReadTheHistory`, and every call through the `call()` helper). Driving a browser
to reach `ws.php` would restate an integration assertion — the pyramid violation
`.claude/rules/testing.md` names explicitly.

**The recorder is below even that.** Its shaping half is pure and sits in
`tests/Unit/HistoryRowTest.php`; its SQL half is exercised against the real MariaDB through
`tests/Support/PiwigoRuntime.php`. Neither is reachable from a browser at all.

## Consequences

- The E2E ledger for `plugins/provenance` is unchanged: first spec in Phase 4.
- Phases 4-9 are **not** covered by this decision. Several of them carry manual verification
  steps that are browser-observable (the album modal, the apply progress UI, the Batch Manager
  move prompt) and whose phases list no E2E criterion; that gap is left to those phases, and is
  not settled here.
