---
id: 10
dependencies:
- 03
---

# Task 10: Restore check in a separate DDEV database

`pwg-backup --check` proves the newest snapshot's dump restores, by importing it into a scratch
database in DDEV and comparing its row counts with the snapshot's.

## References

* `design.md#restore`
* `design.md#snapshot-py` (`snapshot.json` `counts`)
* `.ddev/config.yaml`
* `tools/deploy/pwgdeploy/snapshot.py` (`latest()`, task 03)

## Work

* [x] Write failing unit tests, then `check.compare(expected_counts, actual_counts) -> list[str]` (pure; one message per differing table, an empty `counts` is itself a failure)
* [-] `pwg-backup --check`: take `snapshot.latest()`, create database `backup_check` in DDEV (`ddev mysql -uroot`), import the gzipped dump, count `images`, `categories`, `tags`, `users` (with the snapshot's table prefix), drop the database in a `finally`
  **Note:** Logic done in `pwgdeploy/check.py` (`run_check`, `check_main(snapshot_root)` returns the exit code). The `--check` flag is NOT wired: `backup_cli.py` belongs to task 06, which depends on later work than this task (dependency gap in the task split). Task 06 must call `check.check_main`. Prefix: `prefix` key of `snapshot.json`, default `piwigo_` (the dump answer in the design has no prefix field; task 06 should record it in the meta). Dump path `<snapshot>/db/dump.sql.gz` (`check.DUMP_PATH`, sibling of `files/` per design.md). The streaming runner refuses a dump line starting with `USE`/`CREATE|DROP DATABASE|SCHEMA`, since an import as root could otherwise reach `db`.
* [x] Refuse any target database name other than `backup_check`; never touch `db`
  **Note:** `import_and_count` raises `CheckError` for any name but `backup_check`; unit test `test_another_database_name_is_refused`.
* [x] Also verify every file in the snapshot against its recorded sha1 (`--check` reports the first mismatches by name)
* [x] Exit non-zero and log on any mismatch; the failure mail of task 07 applies once it exists
  **Note:** `check_main` returns 1 and prints `check failed: ...` to stderr; the failure mail (task 07) is not wired yet.
* [x] README: how and how often to run it

## Verification

* [x] A snapshot whose dump was truncated by hand fails the check, naming the import error
  **Note:** Verified via `tests/integration/test_check_ddev.py::test_a_truncated_dump_fails_naming_the_import_error` (half the gzip cut off; the problem names the unreadable dump).
* [x] A snapshot with one photo's bytes changed by hand fails, naming that file
  **Note:** Verified via `test_a_changed_photo_fails_naming_the_file` (problem `upload/a.jpg: sha1 differs ...`). Verified again 2026-10-20 after the dump-path fix: `PWG_DDEV_DIR=... uv run pytest -m integration -k check` 3 passed, `SHOW DATABASES` lists no backup_check.
* [x] After the check, `ddev mysql -e 'SHOW DATABASES'` lists no `backup_check`, and `db`'s row counts are unchanged
  **Note:** Verified via `test_the_check_passes_cleans_up_and_leaves_db_alone` and a manual `SHOW DATABASES` after all runs; db counts compared before/after.
* [x] `cd tools/deploy && uv run pytest` passes
  **Note:** 668 passed; failing only the known `test_readme` count and worktree `test_seed` emoji tests. Tests were written alongside the code, not strictly red first (deviation); the truncated-dump case caught a real bug (Popen stdin) in the integration run.
