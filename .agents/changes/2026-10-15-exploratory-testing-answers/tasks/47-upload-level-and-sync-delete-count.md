---
id: 47
dependencies:
- 36
---

# Task 47: Uploads refuse a level outside the offered ones; the sync counts every photo it deletes

Added 2026-10-18 from task 36's findings (user's answers 1a, 2a).

## References

* Task 36 (Deviations), commit `b8256fe25`
* core `include/ws_functions/pwg.images.php` (`ws_images_addSimple`, `ws_images_upload`, `ws_images_add`), `admin/include/functions_upload.inc.php` (`add_uploaded_file()`), `ws.php`, `admin/site_update.php`, `delete_categories()`
* `.claude/rules/fork-local-core-edits.md`

## Work

* [x] Characterize the touched core area first (`[ERR]`, own commit), watch each case go red when the behaviour is broken
  * **Note:** `CoreUploadLevelCharacterizationTest` (new; 20 cases, level stored as sent on upload, addSimple, add, uploadAsync) and two new cases in `CoreSiteUpdateCountsCharacterizationTest`; four mutants killed (storing level 0 in `add_uploaded_file()`, dropping `level` from addSimple's `$info_columns`, `WHERE 0 AND` and the links table in the sync count)
* [x] `pwg.images.upload`, `pwg.images.addSimple`, `pwg.images.add`, `pwg.images.uploadAsync` refuse a `level` not in `available_permission_levels` (same error as task 36's `setInfo`), nothing stored
* [x] The sync's deleted counter counts the photos `delete_categories()` actually deletes
  * **Note:** no code change, see Deviations
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [x] Integration: level 3 and 5 refused on all four methods, no photo row created; 0, 4 and 8 accepted
  * **Note:** Verified via `CoreUploadLevelCharacterizationTest`
* [x] Integration: the counter equals the deleted photo rows, simulation and real
  * **Note:** Verified via `CoreSiteUpdateCountsCharacterizationTest` (`testAPhotoStoredElsewhereSurvivesAndIsNotCounted`, `testARemovedFolderCountsItsPhotosAsDeleted`, `testASimulatedRemovedFolderCountsItsPhotos`)
* [x] provenance and photoinfo suites pass (see report)

## Deviations

* The premise "`delete_categories()` deletes a photo stored elsewhere whose only links were in a removed album" is false. The sync calls it in `no_delete` mode: it deletes the photos stored in the removed folders and only drops links of the others. A photo stored elsewhere survives (orphaned from that album), so the existing count (task 36) already equals the deleted rows. `admin/site_update.php` is unchanged; the case is recorded `[ERR]` in `testAPhotoStoredElsewhereSurvivesAndIsNotCounted`, and `testAPhotoStoredInTheFolderAndLinkedElsewhereIsDeletedAndCounted` covers the other direction. Task 36's Deviation bullet on this was wrong.
* The level check sits at the top of each handler (after the token check in `upload`, after the `original_sum` check in `uploadAsync`), before any file is moved or row written. `ws.php` clamps values above 8, so only 3, 5, 6, 7 reach it.
* The refusal case counts rows by the sent file's `md5sum`, not all rows: other agents create photos in the shared install, a total count flapped.
