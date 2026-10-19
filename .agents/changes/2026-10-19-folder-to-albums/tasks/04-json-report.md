---
id: 04
dependencies:
- 01
---

# Task 04: JSON report per run

Each run writes `tools/deploy/.state/albums-<timestamp>.json` with one entry per album and prints the file's path, so a long tree can be read back after the run.

## References

* `design.md#a-json-report-in-state`
* `tools/deploy/pwgdeploy/albums_cli.py` (from task 01)
* `.gitignore:48` (`/tools/deploy/.state/`)

## Work

* [x] Write the report from the run's results: per album the local path, album id if any, status (`created`, `skipped (exists)`, `would create` once task 02 exists, `failed` / `skipped (parent failed)` once task 05 exists) and the server message for a failure
* [x] The terminal keeps the summary line and errors and adds the report's path; per-album lines move to the report
* [x] Unit test: the report's content for a given list of results
* [x] One line in the README section

## Verification

* [x] After a run against DDEV, the printed path exists and lists every album of the tree with its status and id
  **Note:** Verified via tests/integration/test_albums_ddev.py (report path printed, file read back)
* [x] `git status` shows no report file
  **Note:** integration test writes to tmp_path; `git status` shows only source changes
* [x] `uv run pytest` passes
