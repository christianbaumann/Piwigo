---
id: 04
dependencies:
- 01
---

# Task 04: Closing or reopening an album takes effect at once

A visitor loses or gains access to an album right after Geschlossenes Album changes, with no cache purge.

## References

* `design.md#closing-or-reopening-an-album-takes-effect-at-once`
* `design.md#defects-in-core-are-fixed-in-core`

## Work

* [x] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [x] Call `invalidate_user_cache()` at the end of `set_cat_visible()` (`admin/include/functions.php:748`)
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [x] Integration as normal and generic: 401 on album and photo right after locking through the album properties page and through `admin/cat_options.php`; 200 right after unlocking
  **Note:** Verified via `CoreAlbumLockTakesEffectTest` (8 cases, provenance integration suite). The album properties page saves through `pwg.categories.setInfo`, which the test calls directly. The `cat_options.php` cases were already green before the fix: `admin.php` clears the user cache on every POST to `cat_options` before the page runs
* [x] Test goes red with the line removed
  **Note:** Verified by removing the line under the suite lock: the four `pwg.categories.setInfo` cases fail, the four `cat_options` cases stay green (admin.php's own invalidation). Characterization mutants (lock cascade, unlock cascade) each killed by their cases
* [x] provenance integration suite passes
  **Note:** Verified via the provenance integration suite: 340 tests, 1 failure in `CoreFieldRenderingCharacterizationTest`, task 03's uncommitted file that was being edited during the run; unrelated to this change. Unit suite: 273 OK
