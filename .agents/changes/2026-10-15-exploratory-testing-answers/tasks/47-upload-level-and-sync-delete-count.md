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

* [-] Characterize the touched core area first (`[ERR]`, own commit), watch each case go red when the behaviour is broken
  * **Note:** paused before writing any test; nothing changed yet
* [ ] `pwg.images.upload`, `pwg.images.addSimple`, `pwg.images.add` refuse a `level` not in `available_permission_levels` (same error as task 36's `setInfo`), nothing stored
* [ ] The sync's deleted counter counts the photos `delete_categories()` actually deletes, including a photo stored elsewhere whose only links were in a removed album; simulation and real
* [ ] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [ ] Integration: level 3 and 5 refused on all three methods, no photo row created / row unchanged; 0 and 8 accepted
* [ ] Integration: the counter equals the deleted photo rows, simulation and real
* [ ] provenance and photoinfo suites pass

## WIP (paused 2026-10-18)

* Read only so far; no file changed (tracked or untracked), no flip run, no throwaway folder or scratchpad copy created.
* Findings for the next step:
  * `level` handlers: `ws_images_add` (~1468, passes `$params['level']` to `add_uploaded_file()`), `ws_images_addSimple` (~1575; stores via `$info_columns` incl. `level`, `single_update`), `ws_images_upload` (~1975), also `ws_images_uploadAsync` (~2211, same flaw; decide whether to include).
  * `ws.php` registers `level` with `maxValue` = 8, so only 3, 5, 6, 7 reach a handler check (as in task 36). Add `isset($params['level']) and !in_array(..., $conf['available_permission_levels'])` -> `PwgError(WS_ERR_INVALID_PARAM, 'Invalid level')` at the top of each handler, before any file is stored (re-read pwg.images.php before editing; task 40 may touch it).
  * Sync counter: `admin/site_update.php` (task 36 counts `storage_category_id IN get_subcat_ids($to_delete)`); the gap is a photo stored elsewhere whose only links were in the removed album(s); `delete_categories()` deletes it. Fix idea: count the photos `delete_categories()` would delete (images linked to the removed albums and to no other album) in addition to / instead of the storage count, simulation and real.
  * Fixtures: `CoreUploadCharacterizationTest`, `CoreUploadChunkCharacterizationTest` (provenance) for upload, `CoreSiteUpdateCountsCharacterizationTest` for the sync (helpers `syncedSubFolderWithOnePhoto()`, `sync()`, `summary()`).
* Next: write [ERR] characterization (level 3/5 stored today on add/addSimple/upload; deleted-count misses the elsewhere-stored photo), commit alone, then implement, add the fork-local-core-edits.md line, run provenance unit+integration and photoinfo integration under the flock.
