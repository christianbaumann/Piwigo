---
id: 07
dependencies:
- 02
- 03
- 06
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-07
---

# Task 07: Undo for date, info and tag entries

A user undoes their own date, info or tag change from the change list, an administrator anyone's;
undo is refused when the value changed since.

## References

* `design.md#undo-runs-on-the-server`
* `design.md#who-may-undo`
* `design.md#conflicts-undo-rows-no-redo`
* `design.md#undone-entries`
* `plugins/photoinfo/main.inc.php` (`photoinfo_add_methods()`)
* `plugins/photoinfo/include/ws_functions.inc.php`, `events_tags.inc.php` (the write paths undo reuses)

## Work

* [x] New method `pwg.photoinfo.undo(history_id, pwg_token)`: guest refused, token checked, photo visibility checked
* [x] Pure rule functions: may undo (own entry, or `is_admin()`); is current (current value equals the entry's new value); is undoable (not an undo entry, not undone, not a `photo` entry)
* [x] Apply the inverse through the same functions as the original write (file write, lock); write an undo entry with `undoes_id` and source undo
* [x] Refusal messages: „Wurde inzwischen von X geändert“, not allowed, already undone
* [x] Rückgängig links in the change list (task 06) for entries the viewer may undo; refresh after undo
* [x] Unit tests for the three rules as a decision table `[DT]`; integration tests per field, conflict `[NEG]`, rights per role; photoinfo `RoleMatrixTest` row `undo`
* [x] Record decision „undo rights: own changes for everyone, all changes for administrators“

## Verification

* [x] A normal account undoes its own tag add; the tag is gone from the photo and its file, the list shows the undo entry **Note:** `UndoTest::testOwnTagAddIsUndone`: the tag is gone from the database and the file's keywords, and the undo entry has `undoes_id`, source `undo` and the account.
* [x] A normal account sees no Rückgängig on another account's entry and the method refuses it **Note:** `UndoTest::testRights` (generic and normal on another's entry: 403, nothing changed) and `testThePageOffersUndoToTheRightViewers`.
* [x] An administrator undoes another account's info change **Note:** `UndoTest::testRights`, admin and webmaster on another account's entry.
* [x] Undo after a later change of the same field is refused with the user's name **Note:** `UndoTest::testAnInfoChangedSinceIsRefusedWithTheAccountsName`, `testATagChangedSinceIsRefused`; „jemand anderem“ for a change outside the log, `testAValueChangedOutsideTheLogIsRefused`.
* [x] photoinfo suites pass, including the method guard of `RoleMatrixTest` **Note:** Verifier on the rebased state: photoinfo unit `OK (350 tests, 627 assertions)`, integration `OK (314 tests, 1892 assertions)` including the matrix guard, E2E 73 passed; provenance `HistoryLogTest|RoleMatrixTest` `OK (72 tests)`.

## Implementation notes

- `pwg.photoinfo.undo(history_id, pwg_token)`, post only: guest 401, token 403, unknown entry, non-photo entry or invisible photo 404; refusals in the order not allowed (403), already undone (409), not undoable, changed since (409). The photo comes from the entry, so an entry cannot act on another photo. `photoinfo_ws_guard()` was split out of `photoinfo_ws_image()`; the existing methods' checks are unchanged.
- Rules in `plugins/photoinfo/include/undo.inc.php`. `history.js` repeats the offer rule for the buttons; the server decides every click.
- The inverse runs through the original write paths (date via `photoinfo_dating_from_edtf()`, info, tags via `photoinfo_write_tags()` under the persons lock). With a read-only file the database changes, the undo entry is written and the answer has `written=false`; that path has no test.
- A tag deleted since its entry is refused as changed (409); untested.
- Date „current“ compares the stored `display (edtf)` text, not the EDTF alone; equivalent while the display derives from the dating.
- Region entries are refused for now; task 08 adds `region` to `photoinfo_history_undo_fields()` and one branch in `photoinfo_undo_apply()`.
- For task 13: after an undo from the list the Datum, Info and tag rows keep the old value until reload; `document` gets a `photoinfo:undone` event with the ws result. `history.js` ignores `written=false`, so a failed file write after an undo is not shown anywhere yet.
- Decision 0092 (undo rights; no lock between check and write accepted).
