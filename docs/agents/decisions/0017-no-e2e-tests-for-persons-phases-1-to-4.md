# 0017 — Phases 1 to 4 of the persons plan ship no E2E tests, and no E2E support harness

Date: 2026-08-30
Status: settled

## Decision

`plugins/persons` Phases 1 (plugin skeleton, schema, coordinate math), 2 (reading regions out of
a file), 3 (writing them back) and 4 (the web-service methods) contribute **zero** Playwright
specs and **no** `tests/e2e/` directory. `playwright.config.js` and `package.json` stay as they
are — the runner declaration, matching decision [0002](0002-e2e-runner-location.md) — but
`tests/e2e/auth.setup.js`, `tests/e2e/support/` and the first spec land in **Phase 5**, the
first phase that renders anything a browser can observe.

Consequence, stated so nobody reads it as breakage: until Phase 5,
`ddev exec bash -c 'cd plugins/persons && npx playwright test'` exits 1 with `No tests found`.
That is the correct output for a plugin with no browser-observable behaviour, not a broken
harness.

## Why

**Nothing in the first four phases is witnessable in a browser.** Phase 1 ships a header comment
block, two index tables and a file of pure functions. Phase 2 and 3 shell out to exiftool. Phase 4
registers web-service methods. Per the placement rule in
[`../../../.claude/rules/testing.md`](../../../.claude/rules/testing.md), each behaviour goes at
the lowest layer that can express it: the coordinate math is unit-level, the file I/O and the
API are integration-level, and neither may be restated higher up. `main.inc.php` registers no
template prefilter and no admin page before Phase 5, so there is no rendered surface to drive.

**The three candidates were deliberately placed lower, with real oracles.** Phase 1's manual
boxes — "the plugin appears in Admin > Plugins and activates without error", "deactivate then
activate again — no error", "uninstall drops both tables" — are the only items that sound
browser-shaped. All three are now integration tests:

- `tests/Integration/PluginActivationTest.php` drives install / activate / deactivate /
  re-activate / uninstall through `pwg.plugins.performAction`, the same method the admin screen
  calls, so what is asserted is what clicking Activate actually does.
- `tests/Integration/AdminPluginPageTest.php` fetches the plugin list over HTTP. The list is
  server-rendered from `piwigo_plugins` joined with the header block parsed out of
  `main.inc.php`, so the fetch is a real oracle for a well-formed header block — a malformed one
  yields a plugin that installs perfectly and shows up nameless, which no unit test can see.
  It also runs a differential PHP-diagnostic scan across four pages with the plugin active
  against the same pages with it deactivated.

Adding a browser on top would restate an integration assertion — the pyramid violation
`testing.md` names explicitly.

**The plan already assigns every E2E spec elsewhere.** All twelve specs in the plan's
*End-to-End Tests* list (`tag-person.spec.js`, `overlay.spec.js`, `admin-persons.spec.js`,
`browse.spec.js`) test the overlay, the drag-to-draw editor, the admin screen and the browse
link — Phases 5 to 8.

**An auth setup written now would be speculative.** `create-test-users.php` and the git-ignored
`local/config/persons-test.env` already exist, so the Phase 5 setup file is a short one. Writing
it before a spec needs it means committing untested support code and still getting
`No tests found` — no check gets stronger, and there is a second thing to keep correct.

## Consequences

- Phase 5 owns the first `npx playwright test` that can pass, and owns `auth.setup.js`,
  `tests/e2e/support/` and the first page object with it.
- Until then the documented E2E command is expected to exit 1. Do not "fix" it with a smoke
  spec that asserts the gallery loads — that tests Piwigo, not this plugin.
- `plugins/persons/node_modules/` is installable today (`npm install`, 3 packages, 2026-08-30),
  so the runner itself is proven loadable: `npx playwright test --list` parses
  `playwright.config.js` without error and reports `Total: 0 tests in 0 files`.

## Related

- [0007](0007-no-e2e-tests-for-provenance-phases-1-and-2.md) and
  [0008](0008-no-e2e-tests-for-provenance-phase-3.md) — the same decision for `plugins/provenance`,
  which this follows.
