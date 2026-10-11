---
id: 01
dependencies: []
---

# Task 01: Deploy refuses the `REPLACE_ME` placeholder

A credential file that still carries `deploy.example.json`'s placeholder fails validation in milliseconds, so the placeholder can never again become the live webmaster password.

## References

* `design.md#guard-against-the-replace_me-placeholder`
* `design.md#stale-backlog-line`
* `tools/deploy/pwgdeploy/config.py` (`_required_string()`)
* `tools/deploy/deploy.example.json`
* `tools/deploy/tests/test_config.py`
* `.claude/rules/deployment.md` (*The credential file*)
* `docs/backlog.md` (*ftp deploy*, *misc* dev-environment line)

## Work

* [x] Write failing tests in `tests/test_config.py`: `REPLACE_ME` in any credential field (ftp, mysql, admin password at least) is refused naming the field; a real value passes
* [x] Add one named placeholder constant in `pwgdeploy/config.py` and refuse it in `_required_string()` (or wherever each required string is checked); the test reads the constant
* [x] Add a test that `deploy.example.json` still uses that constant as its placeholder, so the guard and the example cannot drift apart
* [x] Rewrite the `REPLACE_ME` paragraph in `.claude/rules/deployment.md`: the trap is now guarded
* [x] In `docs/backlog.md`, remove the `REPLACE_ME` item and the out-of-date "typetags submodule is three commits ahead" line
* [ ] Owner (manual): set a real `admin.password` in `tools/deploy/deploy.local.json` (manual testing required)

## Verification

* [x] Validation of a config whose `admin.password` is `REPLACE_ME` fails with a message naming `admin.password`
  **Note:** Verified via `test_the_placeholder_is_refused_naming_the_field[admin-password]` and `test_every_rejection_names_the_offending_field[admin.password-16]`
* [-] `uv run pwg-deploy --dry-run deploy.local.json` refuses until the owner has set the password, then passes
  **Note:** Refusal verified 2026-10-11: exit 3, `ConfigError: admin.password still holds the placeholder 'REPLACE_ME'`; `--dry-run deploy.ddev.json` (no placeholder) passes. The "then passes" half needs the owner's real password (manual testing required)
* [x] Removing the guard turns the new tests red
  **Note:** Verified 2026-10-11: guard condition replaced by `if False:` turned 7 tests red; restored, green
* [x] `cd tools/deploy && uv run pytest` passes
  **Note:** 579 passed, 2026-10-11; dated count updated in `README.md` and `deployment.md`
