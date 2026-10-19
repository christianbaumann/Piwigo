---
id: 06
dependencies:
- 01
---

# Task 06: `--contents` creates the folder's sub-folders, not the folder itself

With `--contents`, the given folder is not created as an album; each of its sub-folders becomes a top album under the target (the root, or `--parent`). Added after the design on the user's request (2026-10-19): the folder `Fotos SW` is to be the remote root for its 19 sub-folders.

## References

* `design.md#an-existing-album-is-skipped-with-everything-below-it`
* `design.md#target-root-or---parent-id`
* `tools/deploy/pwgdeploy/albums.py` (`plan()`), `tools/deploy/pwgdeploy/albums_cli.py`
* `tools/deploy/tests/test_readme.py` (every `pwg-albums` flag must be documented)

## Work

* [x] Add `--contents` to the `albums_cli.py` parser; with it, each sub-folder of `<folder>` is planned as a top folder under the target, matched against the target's children with the existing skip rule
* [x] Report paths stay relative to `<folder>` without its own name as prefix, or keep the existing form — whichever needs less code; say which **Note:** kept the existing form: paths are relative to the sub-folder, no root prefix (falls out of `plan()` with no extra code)
* [x] Unit tests: sub-folders planned at the target, the folder itself not planned, an existing sub-folder skipped with its subtree, combined with `--parent` **Note:** three tests in `tests/test_albums.py`
* [x] Extend the integration test: `--contents` under a uniquely named test album via `--parent`, checked through `getList`, deleted in teardown **Note:** `test_contents_creates_the_subfolders_directly_under_the_parent`
* [x] Document `--contents` in the README section (backticked, the guard checks it)

## Verification

* [x] Against DDEV, `--contents --parent <test album>` creates the sub-folders directly under the test album and no album named after `<folder>` **Note:** Verified via the integration test (getList: only Urlaub, Hochzeit, Müller under the parent, no album named after the folder)
* [x] A second run skips all of them **Note:** Verified via the same integration test (`0 created, 3 skipped`)
* [x] `uv run pytest` and `uv run pytest -m integration` pass; the README test count matches **Note:** Verified via both runs
