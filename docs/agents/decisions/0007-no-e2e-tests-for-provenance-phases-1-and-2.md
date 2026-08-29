# 0007 — Phases 1 and 2 of the provenance plan ship no E2E tests, and no E2E support harness

Date: 2026-08-29
Status: settled

## Decision

`plugins/provenance` Phases 1 (plugin skeleton, schema, dev environment) and 2 (the pure
composition layer) contribute **zero** Playwright specs and **no** `tests/e2e/` directory.
`playwright.config.js` and `package.json` stay as they are — the runner declaration, matching
decision [0002](0002-e2e-runner-location.md) — but `tests/e2e/auth.setup.js`,
`tests/e2e/support/` and the first spec land in **Phase 4**, the first phase that renders
anything a browser can observe.

Consequence, stated so nobody reads it as breakage: until Phase 4,
`ddev exec bash -c 'cd plugins/provenance && npx playwright test'` exits 1 with
`No tests found`. That is the correct output for a plugin with no browser-observable
behaviour, not a broken harness.

## Why

**Nothing in either phase is witnessable in a browser.** Phase 1 ships a header comment block,
nine columns, a history table, an exiftool config file and a test harness. Phase 2 ships five
pure functions with no database, no HTTP and no shell. Per the placement rule in
[`../../../.claude/rules/testing.md`](../../../.claude/rules/testing.md), each behaviour goes at
the lowest layer that can express it: those functions are unit-level, the schema is
integration-level, and neither may be restated higher up.

**The one candidate was deliberately placed lower, with an oracle.** Phase 1's "the plugin
appears in the admin plugin list and activates without a PHP notice" is the only item that
sounds browser-shaped. It is covered by `tests/Integration/AdminPluginPageTest.php`: the list
is server-rendered from `piwigo_plugins` joined with the parsed header block, and this install
renders PHP diagnostics inline into the response body, so an HTTP fetch is a real oracle for
both halves. Adding a browser on top would restate an integration assertion — the pyramid
violation `testing.md` names explicitly.

**The plan already assigns every E2E spec elsewhere.** All five specs in the plan's
*End-to-End Tests* list (`album-provenance.spec.js`, `picture-provenance.spec.js`) test the
album modal, the apply progress UI and the `#Provenance` row — Phases 4 and 8.

**An auth setup written now would be speculative.** `create-test-users.php` and the git-ignored
`local/config/provenance-test.env` already exist, so the Phase 4 setup file is a short one. Writing
it before a spec needs it means committing untested support code and still getting
`No tests found` — no check gets stronger, and there is a second thing to keep correct.

## What was corrected

The plan's Phase 1, change 4 listed `tests/e2e/support/` among its delivered files and was
ticked. No such directory exists. The plan text now says the E2E harness lands in Phase 4 and
cites this file, per the *keep instructions honest* rule in
[`../../../.claude/rules/backpressure.md`](../../../.claude/rules/backpressure.md).

## Consequences

- Phase 4 owns the first `npx playwright test` that can pass, and owns `auth.setup.js`,
  `tests/e2e/support/` and the page object with it.
- Until then the documented E2E command is expected to exit 1. Do not "fix" it with a smoke
  spec that asserts the gallery loads — that tests Piwigo, not this plugin.
- `plugins/provenance/node_modules/` is installable today (`npm install`, 3 packages,
  2026-08-29), so the runner itself is proven loadable: `npx playwright test --list` parses
  `playwright.config.js` without error and reports 0 tests.
