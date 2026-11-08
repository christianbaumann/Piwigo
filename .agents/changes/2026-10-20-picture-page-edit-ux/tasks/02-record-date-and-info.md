---
id: 02
dependencies:
- 01
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-02
---

# Task 02: Record date and info changes

Every edit of a photo's date or info text writes one change-log entry with old value, new value, user
and source; consecutive info saves of one user within 10 minutes become one entry.

## References

* `design.md#photoinfo-writes-the-rows-persons-announces-region-changes`
* `design.md#what-gets-a-row`
* `design.md#several-info-saves-become-one-entry`
* `plugins/photoinfo/include/ws_functions.inc.php` (`ws_photoinfo_setDate()`, `ws_photoinfo_setInfo()`)
* `plugins/photoinfo/include/events_core_edit.inc.php` (wrapped `pwg.images.setInfo`, admin photo screen, Batch Manager)
* `plugins/photoinfo/include/rescan.inc.php`, `events_upload.inc.php` (must not record)
* `plugins/provenance/include/history.inc.php`

## Work

* [x] Record `date` and `info` entries (`object` photo) from `pwg.photoinfo.setDate` / `setInfo`, the wrapped `pwg.images.setInfo`, the admin photo screen and the Batch Manager, each with its source
* [x] Store the date as its display text and EDTF so undo can restore it exactly
* [x] Merge rule as a pure function: a new `info` entry updates the previous one when same user, same photo, no other entry in between, within 10 minutes (named constant)
* [x] Return the entry id in the ws answers of setDate / setInfo (`history_id`)
* [x] No entry from `pwg.photoinfo.rescan`, uploads, or an unchanged value
* [x] Unit tests for the merge rule (`[BVA]` at 10 minutes, other user, entry in between); integration tests per edit path and the rescan `[NEG]`

## Verification

* [x] Saving a date on the picture page writes one `date` entry naming the account **Note:** `HistoryRecordingTest::testADateSaveWritesOneEntryNamingTheAccount`.
* [x] Two info saves 3 minutes apart by one account give one entry whose old value is the text before the first save **Note:** `HistoryRecordingTest::testTwoInfoSavesWithinTheWindowAreOneEntry` (ages `occured_on` by 180 s, no sleep).
* [x] An info save by another account in between gives two entries **Note:** `HistoryRecordingTest::testAnotherAccountInBetweenPreventsTheMerge` (three entries in all, the other account's save being one).
* [x] A rescan and an unchanged save write nothing **Note:** `testARescanWritesNothing`, `testAnUnchangedDateWritesNothing`, `testAnUnchangedInfoWritesNothing`.
* [x] photoinfo unit and integration suites pass **Note:** Verifier on the rebased state: photoinfo unit `OK (303 tests, 565 assertions)`, integration `OK (246 tests, 1424 assertions)`, E2E info and date specs 27 passed. After the two added test cases: integration `OK (248 tests, 1438 assertions)`.

## Implementation notes

Date value shape, for tasks 06 and 07: plain text `<display> (<edtf>)`, no date stored as NULL. `photoinfo_history_date_parse()` returns `display` and `edtf`; undo restores through `photoinfo_dating_from_edtf()`. The display text is in the saving account's language; the EDTF is canonical.

Code: `plugins/photoinfo/include/history.inc.php` (`photoinfo_history_date_value`, `_date_parse`, `_should_merge`, `photoinfo_record_info`, `_date`, `_edit`). The `$source` argument is the suffix of a provenance constant, e.g. `'PICTURE_PAGE'`.

Merge details: a merge that brings the text back to where the entry began deletes the entry and `history_id` is null; a merge moves `occured_on` to now, so the 10 minutes run from the last save; a merge never goes into an undo entry. Accepted by the verifier. Source `batch_manager` on the wrapped `pwg.images.setInfo` comes from the Referer; a missing or spoofed one only changes the label to `api`. Manual: the Referer of the Batch Manager's unit mode in a real browser.
