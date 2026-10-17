---
id: 39
dependencies:
- 19
---

# Task 39: Deleting an album with "keep photos" leaves no photo in no album

Added 2026-10-17 from task 19's finding (status.md question 3, default answer a).

## References

* `design.md#a-photos-last-album-cannot-be-removed`
* Task 19 (Deviations: `pwg.categories.delete` with `photo_deletion_mode=no_delete` not covered)

## Work

* [x] Refuse the delete while it would leave a photo in no album, naming the photos, as task 19 does (user's choice 2026-10-17, default answer a); record it as a decision
* [x] Implement in `galleryrules` on every path that deletes an album keeping its photos (album properties page, album list, `pwg.categories.delete`)
  * Both screens call `pwg.categories.delete`; `ws_galleryrules_categories_delete()` in `include/events_last_album.inc.php`, pure `galleryrules_left_by_album_delete()` in `include/last_album.inc.php`. Decision 0083.

## Verification

* [x] Integration: deleting an album that is a photo's only album with "keep photos" leaves the photo in an album (or is refused, per the decision); a photo with another album is unaffected
  **Note:** Verified via `AlbumDeleteKeepsPhotosTest` (9 cases: only album, plural, other album, empty, sub-album, parent+child, delete_orphans, force_delete, wrong token; 4 red before the code) and unit `AlbumDeleteLeftTest` (7). Unit flips: storage skip negated -> 2 red, `array_diff` dropped -> 4 red, `!== null` guard removed -> survives (equivalent: `(int)null` is 0, never a deleted id).
* [x] galleryrules suites pass
  **Note:** Verified 2026-10-17 under the suite lock: unit OK (49), integration OK (82) default and `--order-by=reverse`; provenance integration OK (387, 3 skipped). No E2E: the screens show nothing of the refusal (see Deviations).

## Deviations

- The refusal is not visible in the browser: `cat_modify.js` (album properties delete) and `albums.js` (Alben > Verwaltung) only `console.log` a failed call. Not edited (cat_modify.js is task 35's); the user sees the dialog close with the album still there. Needs a UI change showing the 409 message.
- provenance's `CoreDeleteCategoriesCharacterizationTest`: `testNoDeleteLeavesThePhotoBehindWithNoAlbum` (renamed `testNoDeleteLeavesThePhotoRowAndItsOtherLink`) and `testDeletingAParentAlbumDeletesItsChildren` gave their photo a second album, since the refusal now blocks the orphaning they recorded.
