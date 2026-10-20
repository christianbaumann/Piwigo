---
id: 02
dependencies: []
---

# Task 02: The temporary agent script

A rendered `agent.php`, answering the protocol in `design.md`, dumps the database, lists the
backed-up files, imports a dump and removes itself, and refuses every request without the right
token or after expiry.

## References

* `design.md#agent-protocol`
* `design.md#db-channel-a-temporary-php-script-over-ftps-and-https`
* `design.md#where-the-dump-is-written`
* `design.md#change-detection-an-exact-file-list-from-the-script`
* `tools/deploy/pwgdeploy/bootstrap.py` (`ws_call()`, `_php_string()`: how PHP literals are rendered today)
* `tools/deploy/tests/integration/test_albums_ddev.py` (integration test shape, `deploy.ddev.json`)
* `.claude/rules/mutation-testing.md#wait-for-the-file-to-reach-the-runtime-before-running-the-suite` (Mutagen and opcache delays)

## Work

* [x] Write a failing unit test for `agent.render(token, expiry, dump_dir)`: the output holds the token's sha256 and never the token, the expiry and the dump dir as PHP literals, and a value with a quote cannot break out of its literal
* [x] Add `tools/deploy/pwgdeploy/agent.php` (template; Piwigo banner, core code style) and `render()` in `tools/deploy/pwgdeploy/agent.py`; make sure the template ships in the package
* [x] `dump`: `mysqldump --single-transaction` through `exec()` with the credentials of `local/config/database.inc.php` (password not on the command line: a defaults file in the dump dir, removed afterwards), gzip; without `mysqldump` a pure-PHP dumper (mysqli, `SHOW CREATE TABLE`, batched `INSERT`s, `utf8mb4`); answer with `path`, `size`, `method` and the `counts` of `images`, `categories`, `tags`, `users`
* [x] `list`: recursive listing of `upload/`, `galleries/`, `_data/photoedit/originals/` with `filesize` and `filemtime`, paths relative to the web root, plus `db_paths` (every `images.path`)
* [x] `import`: run a gzipped dump at a given path inside the dump dir only (any other path is refused); answer with the statement count
* [x] `cleanup`: remove the dump, the dump dir if the script created it (with its deny-all `.htaccess`), then the script itself
* [x] Token check by `hash_equals()` on sha256, expiry check, POST only; refusals answer 403 with `{"error": ...}`, failures 500, never a stack trace (decision 0069)
* [x] `AgentClient` in `agent.py` over the existing `HttpClient`: `dump()`, `list_files()` -> `list[RemoteFile]`, `import_dump(path)`, `cleanup()`; a non-200 answer raises a typed error carrying the server's message
* [x] Integration tests (`-m integration`) against DDEV: render into a random name in the checkout root, wait until the container sees the file, call each action, assert, clean up in a `finally`; force the PHP fallback once (a `mysqldump` path that does not exist) and compare its counts with the `mysqldump` path's

## Verification

* [x] Without a token, with a wrong token and after expiry, every action answers 403 and changes nothing
  **Note:** Verified via test_every_refusal_answers_403_and_changes_nothing (no token, wrong token, expired, GET; all four actions)
* [x] `dump` through `mysqldump` and through the PHP fallback both produce a gzipped dump that `mysql` imports into a scratch database with the same row counts as `db`
  **Note:** Verified via test_dump_by_mysqldump_and_by_php_import_with_the_same_row_counts (scratch DB; counts equal the dump answer, taken from `db`)
* [x] `list` names a file added to `upload/` with its exact size and mtime
  **Note:** Verified via test_list_names_a_new_file_with_exact_size_and_mtime_and_the_db_paths
* [x] `import` refuses a path outside the dump dir
  **Note:** Verified via test_import_refuses_a_path_outside_the_dump_dir
* [x] After `cleanup` the script's URL answers 404 and the dump dir is gone
  **Note:** Verified via test_cleanup_removes_the_dump_the_dir_and_the_script
* [x] The dump dir is not served by Apache (`http://apache`) when it is under the web root: 403
  **Note:** Verified via the same dump test (curl http://apache/... -> 403)
* [x] `cd tools/deploy && uv run pytest` and `uv run pytest -m integration` pass
  **Note:** Verified via unit: 639 pass (the one failure, test_seed emoji limit, needs the typetags submodule absent in the worktree and fails on the base too); integration: 11 pass with PWG_DDEV_WEBROOT=<main checkout>

## Deviations

* `render()` takes an extra keyword `mysqldump=` (the binary path) so the PHP fallback can be forced.
* `RemoteFile` lives in a stub `pwgdeploy/snapshot.py` (task 03 owns that module).
* `http.py` `_server_message()` also reads the agent's `{"error": ...}` so `UrllibClient`'s error carries it; unknown action answers 400.
* Integration tests write into random sandbox dirs below `PWG_DDEV_WEBROOT` with their own `database.inc.php` (scratch DB), never `db`.
* README/deployment.md test count raised (guarded by test_readme).
* Verify review hardening: `display_errors` off; a pre-existing dump dir also gets the deny-all `.htaccess`; the defaults file is written under `umask(077)` and removed in a `finally`, its values escaped for option-file syntax; a failing `mysqldump` (non-zero exit or empty output) falls back to the PHP dumper; `list` skips symlinked files; `import` answers `statement N failed: <db message>`; partial PHP dump removed on failure.
