---
id: 02
dependencies:
- 01
---

# Task 02: `--dry-run` prints the plan and creates nothing

With `--dry-run`, `pwg-albums` logs in, reads the remote tree and prints the plan with `would create` and `skipped (exists)` lines, without creating an album.

## References

* `design.md#--dry-run-reads-the-remote`
* `tools/deploy/pwgdeploy/albums_cli.py`, `tools/deploy/pwgdeploy/albums.py` (from task 01)

## Work

* [x] Add `--dry-run` to the `albums_cli.py` parser; with it, stop after `plan()` and print each action as `would create` or `skipped (exists)`
* [x] Unit test: with `--dry-run`, a fake client receives no `pwg.categories.add`
* [x] Extend the integration test: a dry run over a new tree leaves the remote unchanged
* [x] One line in the README section

## Verification

* [x] A dry run against DDEV prints the plan and `getList` shows no new album afterwards
  **Note:** Verified via integration test (count unchanged after dry run) and ad hoc against DDEV
* [x] A real run afterwards creates exactly the albums the dry run listed as `would create`
  **Note:** Verified via integration test (5 would create, then 5 created) and ad hoc (4/4)
* [x] `uv run pytest` and `uv run pytest -m integration` pass

**Note (integration):** A dry run no longer prints per-album lines; the plan (`would create` / `skipped (exists)`) goes to the JSON report, the terminal shows the summary and the report path.
