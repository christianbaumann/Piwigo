---
id: 06
dependencies:
- 02
- 03
- 04
- 05
---

# Task 06: `pwg-backup` end to end

`uv run pwg-backup deploy.local.json` takes an incremental snapshot of the remote's DB, photos and
config into the backup folder, logs it, and leaves nothing behind on the host.

## References

* `design.md#overview`
* `design.md#contents`
* `design.md#consistency-between-db-and-files`
* `design.md#code-structure`
* `tools/deploy/pwgdeploy/albums_cli.py` (shape of a second entry point)
* `tools/deploy/pwgdeploy/cli.py` (`ftps_transport()`, `http_client()`, `STATE_DIR`, `_audit()`)
* `tools/deploy/pwgdeploy/audit.py`
* `tools/deploy/pyproject.toml` (`[project.scripts]`)

## Work

* [x] `tools/deploy/pwgdeploy/backup.py`: `run(config, kind, now, transport, client)`: render the agent with a fresh token and a short expiry, upload it under a random name, `dump`, fetch the dump into the snapshot's `db/`, `list`, `snapshot.diff()`, `snapshot.build()` with `transport.get` as `fetch`, fetch `local/config/config.inc.php` and `database.inc.php`, copy the deploy manifest, record `git_commit` and timestamps, `cleanup` in a `finally`, then `retention.prune()`
* [x] Validate every path in the agent's `list` answer before `snapshot.build()`: relative, under `upload/`, `galleries/` or `_data/photoedit/originals/`, no `..` segment (found while verifying task 03: `build()` trusts the path and a `..` would escape `files/`)
* [x] Drift (pure function, unit-tested): `db_paths` with no listed file, and listed files under `upload/` or `galleries/` with no `db_paths` entry (derivative and `_original` sidecars excluded), recorded in `snapshot.json` `drift` (the agent returns `db_paths` exactly as `images.path` stores them, usually `./upload/...`: normalise before comparing; the agent's `list` skips symlinked files, so count those as drift rather than lose them silently); the report gives the count
* [x] `tools/deploy/pwgdeploy/backup_cli.py`, script `pwg-backup` in `pyproject.toml`: `--kind`, one report line per step, exit codes from typed errors
* [x] Wire `pwg-backup --check` to `check.check_main()` (task 10 built the check but had no CLI to hook into); record the remote's table prefix as `prefix` in `snapshot.json` meta (task 10 reads it, default `piwigo_`); store the dump at `<snapshot>/db/dump.sql.gz` (`check.DUMP_PATH`)
* [x] Append one line per run (start, end, kind, fetched/linked counts, bytes, result) to `<backup dir>/backup.log`
* [x] `pwg-deploy --audit` reports any remote `*.php` in the web root that is not in the manifest as a possible leftover agent
* [x] Unit tests for `backup.run()` with fake transport and fake client: the order of calls, `cleanup` also after a failed fetch, no snapshot renamed after a failure
* [x] README section *Backups* (usage, what a snapshot holds, that the folder is unencrypted and holds hashes and e-mails); hand-check entry in `docs/agents/TESTING.md` for a first real run against the remote

## Verification

* [-] (manual testing required against the remote; DDEV half passed) Two runs in a row against the remote: the second fetches only changed files, and an unchanged photo shares its inode across both snapshots
  **Note:** Verified via tests/integration/test_backup_ddev.py::test_two_runs_a_kill_and_a_clean_host (real agent.php in DDEV, local-filesystem transport; second run does not download the unchanged photo, `st_ino` equal across both snapshots). The run against the real remote stays manual.
* [-] (manual testing required against the remote; DDEV half passed) After each run the agent's URL answers 404 and `--audit` reports no leftover agent
  **Note:** The local half is verified via test_backup_ddev.py (script URL answers 404, no `*.php` or `dump-*` left in the sandbox, the audit finds no leftover and finds a planted `zz-planted.php`) and tests/test_cli.py::test_audit_names_a_php_file_in_the_web_root_as_a_possible_leftover_script. The real host is the TESTING.md ledger entry of 2026-10-20.
* [-] (manual testing required against the remote; DDEV half passed) A run killed during the file fetch leaves a `.partial` folder, the next run completes it, and the host holds no agent or dump
  **Note:** The local half is verified via test_backup_ddev.py (KeyboardInterrupt during the file fetch leaves one `.partial`, the next run completes it and fetches only the missing file) and tests/test_backup.py::test_a_killed_run_is_completed_by_the_next_one_without_refetching.
* [-] (manual testing required against the remote; DDEV half passed) `snapshot.json` holds the deployed commit, both timestamps, the dump method and the counts
  **Note:** The local half is verified via test_backup_ddev.py (commit, three timestamps, dump method, counts equal to `db`'s, prefix) and tests/test_backup.py::test_snapshot_json_holds_commit_timestamps_method_counts_prefix_and_drift.
* [x] `cd tools/deploy && uv run pytest` passes
  **Note:** 812 tests collected (measured 2026-10-20, after verify added 7); the only failure is the known test_seed emoji test (typetags submodule absent in the worktree). `PWG_DDEV_WEBROOT=<main checkout> uv run pytest -m integration -k backup_ddev` passes; the full `-m integration` run has one unrelated failure, test_albums_ddev (unpack error at line 66, not touched by this task).

## Deviations

* **Dump directory (task 01 probe not run):** default is the design's fallback, a random-named directory below the web root with a deny-all `.htaccess` (`backup.dump_dir()`, `DUMP_DIR_PREFIX`). One function to switch once the probe shows a writable directory outside the web root.
* **The agent is removed before the file download**, not at the end: dump and list need it, the downloads do not, and a long first run would otherwise outlast the script's expiry (`SCRIPT_LIFETIME_SECONDS` = 1 h) and make `cleanup` answer 403. `finally` still cleans up after any failure; a failing HTTP `cleanup` falls back to FTP deletes and raises `AgentLeftoverError` (exit 13) if the script remains.
* **`snapshot.build()` got two optional parameters** (task 03's module): `extras` (local files moved into the snapshot before the rename: dump, the two config files, the manifest copy) and `meta` may be a callable (so `finished` is written after the downloads).
* **New exit codes** 11 `BackupError`, 12 `UnsafeRemotePathError`, 13 `AgentLeftoverError`; existing codes unchanged.
* **A resumed `.partial` is renamed to the new run's name** (that is how `snapshot.build()` already worked), so a completed resume carries the timestamp of the completing run.
* **`git_commit`** is `git rev-parse HEAD` of the checkout at backup time; the deploy manifest records no commit, so it is the best available "deployed commit".
* **Config files and manifest copy** are fetched/copied on every run into the new snapshot (no size/mtime known for them), so they are not hardlinked and not in `snapshot.json` `files` (the restore check does not hash them).
* `--kind` defaults to `manual`. A file whose size differs from the listing (changed during the run) aborts the run with `BackupError`; the next run retries.
* Integration test uses a local-filesystem transport over a DDEV sandbox (waits for Mutagen/opcache); it needs `PWG_DDEV_WEBROOT` and a symlinked, git-ignored `deploy.ddev.json` in the worktree.
* `tests/test_readme.py` now also requires every `pwg-backup` flag to be documented and every documented flag to be accepted by some parser.

## Verification review (2026-10-20)

Approved. Found and fixed: the dump `path` from the script was passed to `transport.get` unvalidated; `backup.validate_dump_path()` now demands exactly `<dump dir>/dump.sql.gz` (exit 12). Added tests: bad dump path never fetched, FTP fallback removes `.htaccess`/`client.cnf`/marker (mutation-checked), token absent from snapshot.json and reports, failed run prunes nothing. `test_albums_ddev::test_a_tree_is_created_once...` is a pre-existing flake (report name is second-resolution; the dry-run and the real run write two `albums-*.json` when they straddle a second, so `(report_file,) = ...` raises ValueError); passes 3 of 4 runs, untouched by this task.
