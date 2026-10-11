---
id: 01
dependencies: []
---

# Task 01: Guest lockout

Guests reach no gallery page and no API method except login, session status and version, locally and on the remote.

## References

* `design.md#guests-are-locked-out-with-cores-switch`
* `design.md#the-api-refuses-guests-through-one-handler`
* `design.md#static-image-files-stay-reachable-by-url`
* `include/config_default.inc.php:628`, `include/functions_user.inc.php:1484` (`guest_access`)
* `ws.php:13` (`ACCESS_FREE`), `include/ws_core.inc.php:591` (`ws_invoke_allowed`)
* `tools/deploy/pwgdeploy/bootstrap.py` `config_php()`
* `docs/agents/decisions/0031-person-names-are-visible-to-guests-as-ordinary-tags.md`
* `.claude/rules/deployment.md`, `.claude/rules/testing.md`

## Deviation from the design

Core already closes the API with the same switch: `ws_isInvokeAllowed()` (`include/ws_functions.inc.php:13`, registered `include/ws_init.inc.php:14`) refuses a guest every method except `pwg.session.*` and `reflection.*` once `guest_access` is false. Design fact 1 was wrong. The provenance `ws_invoke_allowed` handler was written, found redundant, and removed before commit. Consequence: `pwg.getVersion` is refused to guests too; the deploy is unaffected because its preflight logs in first (`tools/deploy/pwgdeploy/preflight.py:58-59`). Recorded in decision 0055.

## Work

* [x] Add `$conf['guest_access'] = false;` to the remote config that `config_php()` generates; test it in `tools/deploy/tests/`
  **Note:** `test_generated_config_locks_guests_out` (watched red first)
* [x] Set the same line in the local `local/config/config.inc.php` (git-ignored) and document the step where fresh-clone setup is documented
  **Note:** documented in `.claude/rules/piwigo-dev-environment.md`
* [x] ~~Add a `ws_invoke_allowed` handler in `plugins/provenance`~~ not needed, see *Deviation*; `GuestLockoutTest` asserts the API refusal core gives
* [x] Update tests that assume guest access (0031 cases, guest E2E specs) to assert the lockout instead; map each removed assertion to its successor in the commit message
  **Note:** guest page cases removed or switched to the normal account in provenance, persons, photoedit, photoinfo and typetags (submodule); successor `plugins/provenance/tests/Integration/GuestLockoutTest.php`
* [x] Decision file: guest lockout, superseding 0031 (`0055-guests-are-locked-out.md`)
* [x] Decision file: static files under `galleries/`, `upload/`, `_data/i/` stay reachable by URL (accepted gap) (`0056-...`, also names `i.php`)
* [x] Update `CLAUDE.md` / rules where they state guest visibility (e.g. persons names as tags)
  **Note:** `piwigo-architecture.md` (ws guests), `piwigo-dev-environment.md`, handbook pages `index.html`, `03-fototexte.html`, `04-schlagworte.html`, `05-personen.html`, `docs/agents/TESTING.md` (non-coverage row, ledger, mutant table); CLAUDE.md had no guest claim

## Verification

* [x] A guest requesting `index.php` or `picture.php` is redirected to the login page
  **Note:** `GuestLockoutTest::testAGuestIsSentToTheLoginPage` (index, picture, tags); proved red with `guest_access = true`
* [x] A guest calling `pwg.categories.getImages`, `pwg.tags.getList`, `pwg.persons.getList` gets an error, not data
  **Note:** `GuestLockoutTest::testAGuestGetsNoDataFromTheApi`; the core cases go red with `guest_access = true` (persons refuses guests on its own as well)
* [x] A guest can still call `pwg.session.login`, `pwg.session.getStatus` ~~, `pwg.getVersion`~~ (refused now, see *Deviation*)
  **Note:** `GuestLockoutTest::testAGuestGetsTheSessionStatus`, login via `WsClient::login()`; `pwg.getVersion` refusal asserted in `testAGuestGetsNoDataFromTheApi`
* [x] A logged-in normal user calling `pwg.categories.getImages` still gets data
  **Note:** `GuestLockoutTest::testALoggedInNormalUserStillGetsPhotos`
* [x] `uv run pwg-deploy --dry-run` shows the generated config contains `guest_access = false`; `uv run pytest` in `tools/deploy` passes
  **Note:** the dry run does not print the config; rendered `config_php()` from `deploy.local.json` instead, which carries `$conf['guest_access'] = false;`. Dry run itself succeeded; `uv run pytest` exit 0 (595 tests)
* [x] Provenance unit, integration and E2E suites pass, and every other plugin suite that had guest cases passes
  **Note:** all five plugins' unit, integration and E2E suites ran green (2026-10-11); the integration runs found the guest page cases that failed or passed vacuously, fixed as above
