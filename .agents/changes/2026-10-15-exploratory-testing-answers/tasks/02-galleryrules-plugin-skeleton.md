---
id: 02
dependencies: []
---

# Task 02: `plugins/galleryrules` exists with its suites and accounts

An active, empty fork-local plugin `plugins/galleryrules` with unit, integration and E2E suites, so the behaviour-change tasks have a home.

## References

* `design.md#core-behaviour-changes-go-through-a-plugin`
* `.claude/rules/testing.md` (*Test accounts*)
* `.claude/rules/plugin-test-suites.md`
* `plugins/photoinfo/` as the model (`maintain.class.php`, `tests/Support/`, `playwright.config.js`)

## Work

* [x] `plugins/galleryrules/main.inc.php` with header block, `maintain.class.php`
* [x] `composer.json` (PHPUnit) and `package.json` (Playwright, same pinned version as persons), `phpunit.xml` with `unit` and `integration` suites, failing on warnings and risky tests
* [x] `tests/Support/TestUsers.php`, `create-test-users.php` (exits unless `PHP_SAPI` is `cli`) creating `galleryrules_webmaster`, `_admin`, `_normal`, `_generic`, writing `local/config/galleryrules-test.env`; `galleryrules_throwaway_install` marker; `FixtureBuilder` that refuses without the marker
* [x] `!` re-include in root `.gitignore`; plugin `.gitignore` for `vendor/`, `node_modules/`, test output
* [x] Add `galleryrules` to `PLUGINS_TO_ACTIVATE` in `tools/deploy/pwgdeploy/bootstrap.py` with its test
* [x] Section in `.claude/rules/plugin-test-suites.md` with the commands; CLAUDE.md overview line

## Verification

* [x] The plugin activates in Administration > Plugins
  **Note:** Verified via `pwg.plugins.performAction` (activate -> `piwigo_plugins` row `galleryrules active 1.0.0`), `PluginActivationTest` (deactivate/activate cycle) and E2E `plugin-active.spec.js` (box `plugin-active`, switch checked on `admin.php?page=plugins`; goes red with the plugin deactivated)
* [x] A smoke test per suite passes; each suite command is written down and ran
  **Note:** Verified via the four commands in `.claude/rules/plugin-test-suites.md`: unit OK (3 tests), integration OK (2 tests, under `/tmp/pwg-suite.lock`), E2E 5 passed. Unit header tests went red on a mutated name/version; HTTP test red with the CLI guard removed
* [x] Requesting `plugins/galleryrules/tests/Support/create-test-users.php` over HTTP changes no account
  **Note:** Verified via `CreateTestUsersOverHttpTest` (403, the four password hashes and the env file unchanged); with the guard removed it fails (200, passwords rotated)
* [x] `git status` shows the plugin as tracked; `uv run pytest` in `tools/deploy` passes
  **Note:** Verified via `git status --short -uall plugins/galleryrules` (sources listed, `vendor/`/`node_modules/` ignored) and `uv run pytest` (598 passed)
