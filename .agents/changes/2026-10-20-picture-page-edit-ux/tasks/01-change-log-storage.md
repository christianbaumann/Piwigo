---
id: 01
dependencies: []
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-01
---

# Task 01: Change log storage for picture-page edits

provenance's history table can hold date, info, tag, region and photo-edit entries with their sources,
undo entries can point at what they undo, and non-admins can read the history of photos they see.

## References

* `design.md#change-log-extend-provenances-history`
* `design.md#reading-the-change-list-over-the-api`
* `design.md#undone-entries`
* `design.md#values-of-tag-and-region-entries`
* `plugins/provenance/include/history.inc.php` (`provenance_history_row()`, `provenance_record_changes()`, `provenance_fetch_history()`)
* `plugins/provenance/include/functions.inc.php` (`provenance_history_sources()`, field/source label keys)
* `plugins/provenance/maintain.class.php` (`install()`, `widen_source_enum()`)
* `plugins/provenance/main.inc.php` (`pwg.provenance.getHistory`)
* `.claude/rules/plugin-test-suites.md`, `.claude/rules/testing.md`

## Work

* [x] Add field constants `date`, `info`, `tag`, `region`, `photo` and one source per edit path (picture page, admin photo screen, Batch Manager, API, undo); widen the source enum through `maintain.class.php`
* [x] Add nullable column `undoes_id` (with index) to the history table in `install()`/`update()`; `provenance_record_changes()` accepts and writes it; `provenance_fetch_history()` returns it and whether an entry is undone
* [x] Add pure helpers that encode/decode the JSON values of tag (`{"id","name"}`) and region (`{"name","box","drawn_by"}`) entries
* [x] Drop `admin_only` from `pwg.provenance.getHistory`; refuse guests; allow a non-admin only an album or photo it can see (same check as the read-only page)
* [x] Unit tests for the new row shaping and JSON helpers; integration tests for the schema update on an existing install, `undoes_id` round trip, and getHistory per role
* [x] Update provenance's `RoleMatrixTest` rows for getHistory
* [x] Record decision „the change log extends provenance's history“ in `docs/agents/decisions/` (next free number at commit time)

## Verification

* [x] An install updated from the previous plugin version has the `undoes_id` column and keeps its existing rows **Note:** `HistoryLogTest::testTheUpdateAddsUndoesIdAndKeepsExistingRows` drives core's autoupdate from 1.0.0; green in the verifier's full integration run on the rebased state.
* [x] An entry with `undoes_id` is written and read back; the target reads as undone **Note:** `HistoryLogTest::testAnUndoEntryMarksItsTargetUndone` and unit `HistoryRowTest`; green.
* [x] A normal account reads the history of a photo it can see and is refused for a photo in a private album without grant; a guest is refused **Note:** `HistoryLogTest::testGetHistoryPerRole`, `testAPrivateAlbumNeedsAGrant`, `RoleMatrixTest` row `provenance.history`; green.
* [x] Existing provenance entries and the read-only page render as before **Note:** `ReadOnlyPageTest` and `HistoryTest`, unchanged and green.
* [x] provenance unit and integration suites pass (commands in `.claude/rules/plugin-test-suites.md`) **Note:** Verifier, rebased state: provenance unit `OK (327 tests, 964 assertions)`; integration `OK, Tests: 448, Assertions: 2831, Skipped: 3`.

## Implementation notes

Design deviations (implementation details, accepted by the verifier):

- Plugin version bumped to 1.1.0, so core's autoupdate runs `update()` on existing installs.
- `pwg.provenance.getHistory` answers an unseen object with `WS_ERR_INVALID_PARAM` „Unknown object“, the same for a missing and a private object. The ws layer has no 404.
- A non-admin reading getHistory gets a generic text instead of exiftool's `file_error` message, as on the read-only page.
- `provenance_may_read_history()` repeats the visibility SQL of `provenance_page_album()` / `provenance_page_photo()`, since `events_page.inc.php` is index.php-only. Non-blocking drift risk.

Names for later tasks: sources `PROVENANCE_HISTORY_SOURCE_{PICTURE_PAGE,ADMIN_PHOTO,BATCH_MANAGER,API,UNDO}`; fields `PROVENANCE_HISTORY_FIELD_{DATE,INFO,TAG,REGION,PHOTO}`; `undoes_id` key in a change array; `provenance_fetch_history()` rows carry `undoes_id` and `undone`; `provenance_undone_ids()`; `provenance_history_{encode,decode}_{tag,region}()`; `provenance_may_read_history()`.

Decision 0091. Merged by fast-forward as `Extend provenance history for picture-page edits (s-e4c1)`.
