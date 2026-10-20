---
id: 09
dependencies:
- 06
---

# Task 09: Restore a snapshot to the remote

`pwg-backup --restore <snapshot> --yes` puts the snapshot's database, files and config back on the
remote, after taking a fresh snapshot of the state it replaces.

## References

* `design.md#restore`
* `design.md#agent-protocol` (`import`)
* `docs/agents/decisions/0027-manifest-and-remote-must-agree-on-installation.md`
* `tools/deploy/pwgdeploy/manifest.py`

## Work

* [x] Refuse without `--yes`; refuse a `.partial` or unknown snapshot name
* [x] Take a `prerestore` snapshot first; a failure stops the restore
* [x] Upload every file of the snapshot whose size or mtime differs from the remote listing; delete remote files under the backed-up folders that the snapshot does not hold (listed in the report, counted before acting)
* [x] Upload `db/dump.sql.gz` into the agent's dump dir and call `import`; then `cleanup`
* [x] Upload the snapshot's two `local/config/*.inc.php` files and write its deploy manifest back to `tools/deploy/.state/`
* [x] `--dry-run` with `--restore` lists what would be uploaded, deleted and imported, and changes nothing
* [x] Unit tests for the restore plan (pure: snapshot vs remote listing) and for the order of calls with fakes
* [x] README how-to; hand-check entry in `docs/agents/TESTING.md`

## Verification

* [ ] (manual testing required) Restoring yesterday's snapshot over a remote where one photo was deleted and one album added brings back the photo and removes the album (hand check against the remote, recorded)
  **Note:** Ledger row in `docs/agents/TESTING.md`. Covered against DDEV by `tests/integration/test_restore_ddev.py` (photo back, stray file removed, DB counts restored).
* [x] The `prerestore` snapshot holds the state from just before the restore
  **Note:** Verified via `tests/test_restore.py::test_the_prerestore_snapshot_holds_the_state_just_before_the_restore` and the DDEV test.
* [ ] (manual testing required) After the restore, `pwg-deploy --dry-run` reports no state mismatch (decision 0027)
  **Note:** The unit and DDEV tests assert the snapshot's manifest is written to `.state/`; the preflight against the real remote is in the ledger.
* [x] The agent's URL answers 404 afterwards
  **Note:** Verified via `tests/integration/test_restore_ddev.py` (real HTTP against DDEV); real host in the ledger.
* [x] `cd tools/deploy && uv run pytest` passes
  **Note:** 884 tests after verification; only the known worktree failure `test_seed.py::test_the_emoji_limit_is_the_one_typetags_enforces` fails.

## Deviations

* **Module `pwgdeploy/restore.py`** (pure `plan()`, `run()`, `restore_main()`); `backup_cli.py` only gained `--restore`, `--yes`, `--dry-run` and the delegation. `RestoreError` subclassed `BackupError` (exit 11, no new code). **Superseded by follow-up 2026-10-20:** `RestoreError` is a `DeployError` with exit 15, and `restore_main()` wraps every other `DeployError` of the run into it except 12 and 13.
* **Two agents, not one:** one lists the host, a second receives the dump and imports. Uploads of a whole gallery can outlast one agent's one-hour expiry. Cleanup helper `backup._remove_agent` is reused.
* **The dump directory is made by the client** (`makedirs`) with a deny-all `.htaccess` and the `.created-by-agent` marker uploaded before the dump, so the agent's `cleanup` removes the directory too.
* **mtime:** `Transport` has no MFMT, so restored files get a new mtime on the host; the next backup downloads them again and a second restore re-uploads them. Documented in README and deployment.md, not worked around.
* **Dry run contacts nothing** (no listing, no agent): it compares the snapshot with the newest *other* snapshot and says so; with none it states only the file count. Logs nothing.
* The snapshot is verified locally (files present at recorded size, dump and config present) before the host is contacted; a damaged one is refused.
* A failure after the `prerestore` snapshot reports `failed ... the state before is snapshot <name>` and re-raises. Deletions are listed up to 50 paths, then counted.
* `FakeBackupHost` gained the `import` action and `cleanup_removes_dir_files`. A restore log line reuses the backup log fields (`fetched` = uploaded, `linked` = unchanged).
* Integration test: Sandbox B's `database.inc.php` names a scratch database; the restore uploads A's `database.inc.php` into B last (only B's sandbox file changes). It needs the git-ignored `deploy.ddev.json` symlinked into the worktree.
* Mutant check (one, by hand): skipping the deletion loop is killed by the DDEV test and three unit tests.


## Verification review

* Verified via review, `tests/test_restore.py` and the DDEV integration run (`test_restore_ddev.py`, `test_backup_ddev.py`: 2 passed). Approved.
* Fixed: a snapshot naming no files is refused (it would have deleted the remote's whole gallery); the failure mail of a restore says `restore` instead of `manual`; `--kind` with `--restore` is a usage error; TESTING.md no longer calls the remote a sandbox.
* Judged sufficient without a further guard: deletions only under the three folders, validated and printed before any upload; the printed plan plus `--yes` is the confirmation.
