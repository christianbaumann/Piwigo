---
id: 04
dependencies:
- 01
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-04
---

# Task 04: Record region changes

persons announces every added or deleted region through a new event, and photoinfo records it as a
change-log entry; persons itself gains no provenance dependency.

## References

* `design.md#photoinfo-writes-the-rows-persons-announces-region-changes`
* `design.md#values-of-tag-and-region-entries`
* `plugins/persons/include/ws_functions.inc.php` (`ws_persons_addRegion()`, `ws_persons_deleteRegion()`)
* `plugins/persons/include/index.inc.php` (`persons_apply_change()`)
* `plugins/photoinfo/main.inc.php`, `plugins/photoinfo/include/events_tags.inc.php`

## Work

* [x] Fire `trigger_notify('persons_regions_changed', $image_id, $added, $removed)` from the add and delete region paths only, not from `pwg.persons.rescan`, reindexing or photoedit's region adjustment
* [x] Each region carries name, box and `drawn_by`
* [x] photoinfo listens and records one `region` entry per region, value `{"name","box","drawn_by"}`
* [x] Return the entry id in the addRegion / deleteRegion answers when photoinfo recorded one (`history_id`, through a `trigger_change` or the answer filter persons already uses)
* [x] Add the event to `tools/triggers_list.php`-style docs if plugin events are listed there; otherwise document it in persons' header comment
* [x] Integration tests: add, delete, rescan `[NEG]`; persons suite passes with photoinfo inactive

## Verification

* [x] Naming a new box writes one `region` entry with name, box and drawer **Note:** `RegionRecordingTest::testAddingARegionWritesOneEntry`; the entry holds the stored (clipped) box, `testTheEntryHoldsTheStoredBoxNotTheRequestedOne`.
* [x] Deleting a box writes one entry with the old value **Note:** `RegionRecordingTest::testDeletingARegionWritesOneEntryWithTheOldValue` and `testDeletingAnotherAccountsRegionKeepsItsDrawer`.
* [x] `pwg.persons.rescan` writes nothing **Note:** `RegionRecordingTest::testARescanWritesNothing`; a photoedit crop also writes none (`testAPhotoeditCropWritesNoRegionEntry`).
* [x] persons works unchanged with photoinfo deactivated **Note:** `RegionRecordingTest::testPersonsWorksWithPhotoinfoDeactivated`.
* [x] persons and photoinfo integration suites pass **Note:** Implementer on the rebased state: photoinfo integration `OK (272 tests, 1636 assertions)`, persons integration `OK (224 tests, 1 skipped)`, photoinfo unit `OK (313 tests)`. Final verifier: `StoredRegionFallbackTest` OK, history and region classes `OK (39 tests)` twice. Persons unit fails only `CleanCheckoutTest`'s two cases in a worktree (git cannot resolve the worktree `.git` in the container; green on the main checkout).

## Implementation notes

- persons fires `persons_regions_changed` (notification) and `persons_regions_answer` (filter, carries `history_id` into the answer) from `ws_persons_addRegion` / `ws_persons_deleteRegion` only; documented in `persons_announce_regions_changed()`. photoinfo records in `plugins/photoinfo/include/history_regions.inc.php`. persons has no dependency on photoinfo or provenance.
- A region's `drawn_by` on add is the acting user; on delete the stored drawer (null when nobody). The add entry holds the box as stored, read back by `persons_stored_region()`; without an index row it falls back to the request's values (`persons_stored_region_from_row()`).
- Source: `ADMIN_PHOTO` when the Referer contains `admin.php` (the persons admin tagging screen), otherwise `PICTURE_PAGE`, through `photoinfo_referer_contains()`, shared with `photoinfo_core_edit_source()`.
- Not recorded, open design question: person rename and delete (`persons_rename_person()`, `persons_delete_person()`) rewrite or remove regions on all of a person's photos without an entry.
- `HistoryRecordingTest`, `HistoryPhotoEditTest` and `TagHistoryTest` now invalidate the user cache after attaching their photo and make one ws call per account first; that removed the intermittent „Invalid image_id“.
