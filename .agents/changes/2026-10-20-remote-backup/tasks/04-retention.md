---
id: 04
dependencies: []
---

# Task 04: Retention

Old snapshots are deleted by the agreed rule, the tool never deletes the newest complete snapshot
or a folder it did not create.

## References

* `design.md#retention`
* `design.md#snapshot-layout` (folder names carry the timestamp and the kind)

## Work

* [x] Write failing unit tests for `retention.select_for_deletion(snapshots, now)`: predeploy kept 90 days, daily 14 days, then one per ISO week for 6 months, then one per month; the newest complete snapshot always kept; `[BVA]` at 14/90 days and 6 months, `[ECP]` per kind, `[DT]` for kind x age
* [x] Implement it in `tools/deploy/pwgdeploy/retention.py`, pure; the snapshot name format is parsed in one place (shared with task 03 by importing a constant or a parser, not a second copy)
* [x] `prune(root, now)`: delete only folders whose name the parser accepts, never `.partial`, never anything else in the root (`backup.log` included); returns the deleted names
* [x] Unit tests on `tmp_path` for `prune()`, including a foreign folder and a file that must survive

## Verification

* [x] For a fixed list of 400 daily and 30 predeploy snapshots over two years, the kept set matches the rule case by case
  **Note:** Verified via `test_two_years_of_dailies_and_thirty_predeploys_match_the_rule_case_by_case` (730 dailies, 30 predeploys)
* [x] A root with one snapshot older than every limit keeps it
  **Note:** Verified via `test_prune_keeps_a_lone_snapshot_older_than_every_limit` and `test_a_lone_snapshot_older_than_every_limit_is_kept`
* [x] A folder named `photos/` and the file `backup.log` in the root survive `prune()`
  **Note:** Verified via `test_prune_leaves_partial_foreign_folders_and_files`
* [x] `cd tools/deploy && uv run pytest` passes
  **Note:** only `test_readme.py` (documented count) and `test_seed.py::test_the_emoji_limit...` (submodule absent in worktree) fail; both expected, see report

**Verified (independent review):** rule checked against design.md Retention; `pytest tests/test_retention.py` 33 passed; 6 hand-made mutants (window `<=`, 14-day pool `>`, 183-day `<=`, newest `max`, bucket newest, predeploy window) all killed. Approved.

## Design clarifications

* `manual` is retained like `daily` (14 days), `prerestore` like `predeploy` (90 days): the conservative reading, since a prerestore snapshot is the restore point before an overwrite.
* "After that, one per week for 6 months, then one per month": every snapshot older than 14 days, of any kind, joins the thinning pool, even when still inside its own window (a predeploy at 20 days is kept by its window and may also be its week's representative). The pool keeps the newest snapshot per ISO week up to 183 days old (`WEEKLY_DAYS`, 6 months), the newest per calendar month beyond. A bucket's newest is kept in addition to windowed snapshots, so a week can hold more than one.
* Ages compare inclusively (`age <= limit` is kept). The newest snapshot by time is always kept. `prune()` considers directories whose name `snapshot.parse_name` accepts; `.partial`, files, foreign folders and `backup.log` are never touched.
