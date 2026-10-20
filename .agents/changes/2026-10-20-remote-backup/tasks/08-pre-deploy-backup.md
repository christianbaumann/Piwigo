---
id: 08
dependencies:
- 06
---

# Task 08: Backup before every deploy

`pwg-deploy` takes a `predeploy` snapshot before it changes anything on the remote and refuses to
deploy when that snapshot fails.

## References

* `design.md#purpose-disaster-recovery-plus-a-snapshot-before-every-deploy`
* `tools/deploy/pwgdeploy/cli.py` (`main()`, `_preflight()`, `_upload()`)
* `tools/deploy/pwgdeploy/errors.py` (exit codes 1 to 10)
* `.claude/rules/deployment.md`

## Work

* [x] Write failing CLI tests, then call `backup.run(..., kind="predeploy")` in `pwg-deploy` after the preflight checks and before the first upload
* [x] A new `BackupError` with the next free exit code; a failed backup stops the deploy before any remote change
* [x] `--no-backup` skips it and prints that it was skipped; refused together with nothing else; `--dry-run`, `--audit`, `--list-files` and `--seed-tags-only` never take a backup
* [x] A credential file without a `backup` section: the deploy refuses unless `--no-backup` is given, naming the section
* [x] The first deploy onto a blank remote (nothing installed yet) skips the backup and says so
* [x] Update `tools/deploy/README.md` (exit code table, flag) and `.claude/rules/deployment.md` (the pre-deploy backup, the temporary-agent risk); remove the "sandbox" warning there and in the README in favour of the successor decision of task 01

## Verification

* [x] With a failing backup, the deploy exits with the new code and the remote is unchanged (fake transport: no `put`, no `delete`)
  **Note:** Verified via `tests/test_cli.py::test_a_failed_backup_stops_the_deploy_before_any_remote_change` (exit 11; the only put is the temporary script, which is gone afterwards).
* [x] With `--no-backup`, the deploy runs as today and the report says the backup was skipped
  **Note:** Verified via `test_no_backup_deploys_as_before_and_says_it_skipped`.
* [ ] (manual testing required) A real deploy leaves a `*-predeploy` snapshot in the backup folder (hand check). Ledger row added in `docs/agents/TESTING.md`, pending.
* [x] `cd tools/deploy && uv run pytest` passes
  **Note:** 823 tests (measured 2026-10-20); only the known `test_seed` emoji failure (typetags submodule absent in the worktree).

## Deviations

* **Exit code:** `BackupError` (11) reused, no new code. Its meaning ("a backup run could not be taken or stored") fits. Any other `DeployError` raised inside the predeploy run (e.g. an HTTP failure of the dump, 7) is wrapped into `BackupError`, so 11 always means "the backup stopped this deploy"; 12 and 13 keep their own codes.
* **Blank remote** is decided from the preflight's `RemoteState.installed` (`install.php` answer), no extra request. `_preflight()` now returns that state (None on a dry run).
* **`--no-backup` is refused** (parser error, `NO_BACKUP_CONFLICTS`) beside `--dry-run`, `--audit`, `--list-files`, `--seed-tags-only`, like `SEED_ONLY_CONFLICTS`; it is allowed with `--no-bootstrap` (the upload still happens). A missing `backup` section is a `ConfigError` (exit 3) raised before any connection, except on a dry run.
* A dry run prints `backup  skipped (--dry-run ...)`; the run is logged to `backup.log` (success and failure) like `pwg-backup`.
* `cli.main()` got a `git_commit` parameter (passed to `backup.run`); `--help` description no longer says "sandbox".
* tests/test_cli.py `Run` appends `--no-backup` by default (except in the four modes that refuse it), so the existing cases stay about upload and bootstrap.

## Verification review

**Note:** Approved 2026-10-20. Reviewed `cli.main()`: only GET probes (preflight) run before `_predeploy_backup()`; bootstrap, config upload and manifest write come after. 12/13 subclass BackupError, so the wrapping branch leaves them alone; KeyboardInterrupt is logged, re-raised, and backup.run removes the script in its `finally`. Blank remote is decided from install.php (a manifest over a blank remote exits 9 first; `--adopt-remote-state` on a blank remote skips the backup). Docs: no "sandbox" claim left in README/deployment.md/CLAUDE.md (decision 0021 named only as superseded; TESTING.md hits are historical), decisions 0088/0089 links resolve, deployment.md 304 lines. No fixes needed; no commit on the worktree.
