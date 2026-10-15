---
id: 05
dependencies: []
---

# Task 05: The remote shows no PHP errors

A deployed gallery never shows a stack trace; local keeps showing them.

## References

* `design.md#the-remote-shows-no-php-errors`
* `.claude/rules/deployment.md`
* `tools/deploy/pwgdeploy/bootstrap.py`

## Work

* [x] Write `$conf['show_php_errors_on_frontend'] = false;` into the generated remote config beside `guest_access`
* [x] Test in `tools/deploy/tests/`
* [x] Decision file in `docs/agents/decisions/` (`0069-the-remote-shows-no-php-errors.md`)

## Verification

* [x] The generated config holds the line (test)
  **Note:** Verified via `test_bootstrap.py::test_generated_config_hides_php_errors_from_visitors`: red before the change (line missing), green after; red again with the value flipped to `true` (file restored, `git diff` checked)
* [x] `uv run pytest` in `tools/deploy` passes
  **Note:** Verified via `cd tools/deploy && uv run pytest`: 597 passed (count in README and deployment.md updated from 596, guarded by `test_readme.py`)
* [x] Local config unchanged
  **Note:** Verified via `md5 local/config/config.inc.php` before and after (8180f2a4…, mtime 2026-10-11); file is git-ignored and was not edited
* [x] Relevant suites pass, each named with the command that ran (`.claude/rules/plugin-test-suites.md`)
  **Note:** Verified via `cd tools/deploy && uv run pytest` (597 passed), the only affected suite; no plugin suite is affected (no PHP, template or plugin file changed)
