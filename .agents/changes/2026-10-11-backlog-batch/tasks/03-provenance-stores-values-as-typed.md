---
id: 03
dependencies: []
---

# Task 03: provenance stores request values as typed

A note or field typed as `a"b\c'd` is stored in the database and written into the file exactly like that, for photos and for albums.

## References

* `design.md#provenance-double-escaping`
* `plugins/provenance/include/ws_functions.inc.php` (`ws_provenance_setPhotoInfo()`, `ws_provenance_setAlbumInfo()`)
* `plugins/provenance/tests/Integration/SetPhotoInfoTest.php` (`testQuotesAndBackslashesAreStoredAsTyped`, skipped)
* `plugins/provenance/tests/Integration/SetAlbumInfoTest.php`
* `plugins/photoinfo/include/ws_functions.inc.php:34` (the `stripslashes()` pattern)
* `include/common.inc.php` (core's `addslashes()` over request values)

## Work

* [x] Add the `setAlbumInfo` twin of the quotes-and-backslashes test; run it; if it is red, keep it skipped with the same reason as the photo test, if green, record why
  **Note:** Red on first run: `a"b\c'd` stored as `a\"b\\c\'d`, same bug as the photo method; committed skipped (`35379708b`), un-skipped with the fix
* [x] Count rows already stored with backslashes in the provenance columns (photos and albums) on the local install; record the result here
  **Note:** 2026-10-11: 0 rows in `piwigo_images`, `piwigo_categories` and `piwigo_provenance_history`; the local install holds no provenance values at all. The remote was not checked
* [x] Un-skip the tests, then strip core's slashes from every request string in both handlers before validation and escaping
* [x] If the count found rows, decide with the owner whether to repair them; no migration otherwise
  **Note:** count was 0, no migration
* [x] Remove the backlog item

## Verification

* [x] `a"b\c'd` saved through `pwg.provenance.setPhotoInfo` and `setAlbumInfo` reads back unchanged from the database and from the written file
  **Note:** Verified via `SetPhotoInfoTest`/`SetAlbumInfoTest::testQuotesAndBackslashesAreStoredAsTyped` and new `WriteBackTest::testQuotesAndBackslashesSavedThroughTheScreensReachTheFile` (reads Owner, PhotoNote, ImageDescription back with exiftool)
* [x] Removing the `stripslashes()` turns both tests red
  **Note:** Each call removed separately (Mutagen md5 sync + opcache wait): each mutant killed by its save test and the WriteBackTest case; restored, green
* [x] provenance unit and integration suites pass (commands in `.claude/rules/plugin-test-suites.md`)
  **Note:** 2026-10-11: unit OK (236 tests), integration OK (213 tests, 3 skipped, all pre-existing skips)
