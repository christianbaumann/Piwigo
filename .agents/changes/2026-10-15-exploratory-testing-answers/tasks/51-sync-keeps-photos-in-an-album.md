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

* [ ] Such a photo is linked to the parent of the highest removed album; when that is the root, to an album `Ohne Album` created on demand (private, admins only). Real sync only; the simulation reports it without linking
* [ ] The sync result names the number of photos so relinked (German string via `local/language/de_DE.lang.php` if core has none, with its `GermanOverrideKeyTest` row)
* [ ] Decision file 0085; fork-local bullet

## Verification

* [ ] Integration: photo stored elsewhere, only link in the removed folder's album: after the real sync it is in the parent album (sub-folder case) and in `Ohne Album` (top-level case); simulation changes nothing and reports it; a photo with another album keeps only that one
* [ ] The [ERR] case `testAPhotoStoredElsewhereSurvivesAndIsNotCounted` updated in its own cycle with the reason
* [ ] provenance suites pass
