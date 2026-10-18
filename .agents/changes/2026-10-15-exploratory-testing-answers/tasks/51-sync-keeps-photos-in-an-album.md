---
id: 51
dependencies:
- 47
---

# Task 51: The sync leaves no photo without an album

Added 2026-10-18 (user's answer 1a). The filesystem sync removes the album of a vanished folder with `delete_categories()` in `no_delete` mode: a photo stored outside that folder whose only links were there survives, linked to no album, invisible to non-admins. Design: "A photo's last album cannot be removed"; decision 0083 refuses the same for an album delete.

## References

* Task 47 (Deviations), task 39, decision 0083
* `admin/site_update.php` (`delete_categories($to_delete)`), `admin/include/functions.php` (`delete_categories()`, `associate_images_to_categories()`)
* provenance `CoreSiteUpdateCountsCharacterizationTest` (`testAPhotoStoredElsewhereSurvivesAndIsNotCounted`)
* `.claude/rules/fork-local-core-edits.md`

## Work

* [x] Such a photo is linked to the parent of the highest removed album; when that is the root, to an album `Ohne Album` created on demand (private, admins only). Real sync only; the simulation reports it without linking
  * **Note:** `relink_photos_of_albums_to_be_removed()` in `admin/include/functions.php`
* [x] The sync result names the number of photos so relinked (German string via `local/language/de_DE.lang.php` if core has none, with its `GermanOverrideKeyTest` row)
  * **Note:** keys `Without album` and `photos linked to a remaining album`
* [x] Decision file 0085; fork-local bullet

## Verification

* [x] Integration: photo stored elsewhere, only link in the removed folder's album: after the real sync it is in the parent album (sub-folder case) and in `Ohne Album` (top-level case); simulation changes nothing and reports it; a photo with another album keeps only that one
  * **Note:** Verified via `CoreSiteUpdateCountsCharacterizationTest` (3 new cases); mutants killed: simulate guard, `NOT EXISTS`, root target
* [x] The [ERR] case `testAPhotoStoredElsewhereSurvivesAndIsNotCounted` updated in its own cycle with the reason
  * **Note:** now [REQ], asserts the link to the parent
* [x] provenance suites pass
  * **Note:** unit 313 OK; integration 435 tests OK, 3 skipped

## Deviations

* The top-level case cannot be reached by a real sync (a sync limited to an album never removes a top-level folder; a full sync was off limits). The test sets a sub-folder's `id_uppercat` to NULL; target from `id_uppercat`, highest album from `uppercats`.
