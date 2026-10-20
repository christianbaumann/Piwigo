---
id: 03
dependencies: []
---

# Task 03: The snapshot store

Given the last snapshot and a remote file list, the tool decides what to fetch and what to
hardlink, and builds a complete snapshot folder that a killed run can resume.

## References

* `design.md#snapshot-layout`
* `design.md#incremental-storage-hardlinked-snapshot-folders`
* `design.md#resumable-first-run`
* `design.md#snapshot-py`
* `tools/deploy/pwgdeploy/manifest.py` (atomic JSON write via a temporary file)
* `.claude/rules/test-design.md` (technique tags, anti-vacuity)

## Work

* [x] Write failing unit tests for `snapshot.diff()`: no previous snapshot (everything fetched), unchanged file (linked), changed size, changed mtime, new file, file gone from the remote (in `gone`, not in the new snapshot); tag each case `[ECP]`/`[BVA]`
* [x] Implement `RemoteFile`, `Entry`, `Plan` and `diff()` in `tools/deploy/pwgdeploy/snapshot.py`, pure
* [x] `build()`: write into `<name>.partial/files/`, hardlink each `link` entry from the previous complete snapshot (`os.link`), call `fetch(path, local)` for each `fetch` entry, record size, mtime and sha1 of every file in `snapshot.json`, rename to `<name>` only at the end
* [x] Resume: a `build()` that finds `.partial` folders continues the newest one and skips files already there whose size matches; older `.partial` folders are removed
* [x] `latest(root)`: the newest complete snapshot and its `files`, ignoring `.partial`
* [x] Refuse a backup root on another file system than an existing snapshot (hardlinks would fail halfway)
* [x] Unit tests on `tmp_path` for `build()`, resume and `latest()`, with a fake `fetch`

## Verification

* [x] A file unchanged between two builds has the same inode in both snapshots (`st_ino`, `st_nlink == 2`)
  **Note:** Verified via tests/test_snapshot.py::test_unchanged_file_shares_an_inode
* [x] A changed file gets a new inode, and the older snapshot still holds the old bytes
  **Note:** Verified via tests/test_snapshot.py::test_changed_file_gets_new_inode_and_old_bytes_stay
* [x] A `fetch` that raises halfway leaves a `.partial` folder; the next `build()` fetches only what is missing and ends with a complete snapshot
  **Note:** Verified via tests/test_snapshot.py::test_killed_build_resumes_and_fetches_only_the_missing
* [x] Deleting the older snapshot leaves the newer one complete and readable
  **Note:** Verified via tests/test_snapshot.py::test_deleting_the_older_snapshot_leaves_the_newer_readable
* [x] `latest()` never returns a `.partial` folder
  **Note:** Verified via tests/test_snapshot.py::test_latest_ignores_partial_and_empty_root
* [x] `cd tools/deploy && uv run pytest` passes **Note:** Verified on master after merging tasks 01-05 and 10 (2d94ceac4): full suite green, 738 tests
  **Note:** test_snapshot.py all green; the full run has 3 failures unrelated to this task: two README/rules test-count claims (628 vs 658, main agent updates once after merge) and test_seed emoji limit (typetags submodule absent in the worktree)
