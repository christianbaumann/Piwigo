---
id: 55
dependencies: []
---

# Task 55: Stapelverarbeitung unit mode shows a failed file write

Added 2026-10-19 (brainstorm Q2 A). Today unit mode shows a green badge when the date, Info or tags were saved but not written into the file. After this task, the photo's badge shows the German reason.

## References

* `design-handbook-gap-fix.md#the-unit-mode-write-warning-task-55`, key decisions *Unit mode shows a failed file write*, *Tests for the unit-mode warning sit at two layers*
* `plugins/photoinfo/include/events_core_edit.inc.php` (`ws_photoinfo_images_setInfo()`, `photoinfo_tag_write_answer()`), `plugins/photoinfo/include/writer.inc.php` (`photoinfo_l10n_reason()`)
* `admin/themes/default/js/batchManagerUnit.js` (success handlers near lines 69 and 124, `showErrorLocalBadge()`)
* photoinfo `tests/Integration/CoreEditTest.php` (`testAFailedWriteShowsInTheSetInfoAnswer`), photoinfo `tests/e2e/support/seed.php` (`--read-only`, `--writable`), persons `tests/e2e/admin-batch-unit-guard.spec.js` (unit-mode page object pattern)
* `.claude/rules/fork-local-core-edits.md`, `.claude/rules/testing.md`, `.claude/rules/e2e-tests.md`

## Work

* [x] Integration test first, red: a failed date or Info write answers `message_l10n` with the German text that the picture page shows; a failed tag write already answers `tags_message_l10n` (asserted in the same test class)
* [x] `ws_photoinfo_images_setInfo()` adds `message_l10n` to the answer when `written` is false
* [x] E2E spec first, red: unit mode with a read-only demo file; changing the date, the Info, or a tag shows the German message in that photo's badge, not the success badge
* [x] `batchManagerUnit.js`: when `stat` is ok but `written === false` or `tags_written === false`, call `showErrorLocalBadge(pictureId, <message_l10n or tags_message_l10n>)`
* [x] New bullet in `fork-local-core-edits.md` in the same commit as the JS change
* [x] Unit layer recorded as not applicable, with the reason, in the commit message

## Verification

* [x] Both new tests failed before the fix for the expected reason, and pass after it
* [x] With a writable file, unit mode still shows the success badge (E2E)
  **Note:** Verified via the [HAPPY] case in `batch-unit-write-warning.spec.js`.
* [x] Global mode still shows "Fotoinfo: ..." at the top (existing `testAFailedBatchWriteIsReportedOnce` passes)
* [x] photoinfo integration and E2E suites pass, and persons `admin-batch-unit-guard.spec.js` passes; commands named in the notes
  **Note:** Verified via photoinfo integration (232 tests OK), photoinfo E2E (63 passed), photoinfo unit (288 OK), persons `admin-batch-unit-guard` (2 passed), all under the suite lock.
