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

* [ ] Add the `setAlbumInfo` twin of the quotes-and-backslashes test; run it; if it is red, keep it skipped with the same reason as the photo test, if green, record why
* [ ] Count rows already stored with backslashes in the provenance columns (photos and albums) on the local install; record the result here
* [ ] Un-skip the tests, then strip core's slashes from every request string in both handlers before validation and escaping
* [ ] If the count found rows, decide with the owner whether to repair them; no migration otherwise
* [ ] Remove the backlog item

## Verification

* [ ] `a"b\c'd` saved through `pwg.provenance.setPhotoInfo` and `setAlbumInfo` reads back unchanged from the database and from the written file
* [ ] Removing the `stripslashes()` turns both tests red
* [ ] provenance unit and integration suites pass (commands in `.claude/rules/plugin-test-suites.md`)
