---
id: 19
dependencies:
- 02
---

# Task 19: A photo's last album cannot be removed

No path leaves a photo in no album.

## References

* `design.md#a-photos-last-album-cannot-be-removed`

## Work

* [x] In `galleryrules`, refuse removing a photo's last album: Batch Manager "Von Album abtrennen", properties page, API; moving stays possible
  * Pure rules in `include/last_album.inc.php` (albums left after core's dissociate / move, the ids in
    setInfo's `categories` string read as core reads it, photos left in none). Hooks in
    `include/events_last_album.inc.php`, no core edit:
    * Batch Manager: `loc_begin_element_set_global` (after core's token check) refuses the whole
      selection's dissociate when any photo would be left in no album: error naming the photos,
      `$_POST['submit']` dropped.
    * Properties page: `picture_modify_before_update` puts the photo's current albums back into
      `$_POST['associate']` and adds the error.
    * API: `pwg.images.setInfo` (categories in `replace` mode) and `pwg.images.setCategory`
      (`dissociate`) re-registered around their current callbacks at `ws_add_methods` neutral + 20,
      i.e. outside photoinfo's setInfo wrapper (neutral + 10), so a refused call reaches no other
      handler. Refusal: HTTP 409, nothing of the call saved. A call with a wrong token is left to core (403).
  * German: "Nichts entfernt: Das Foto #5 wäre danach in keinem Album mehr. Verschieben Sie es
    stattdessen in ein anderes Album." (plural form for several photos).
  * A photo's storage album counts as core treats it: dissociate and move never break that link, so
    they never refuse it; setInfo replace does delete it and is refused.

## Verification

* [x] Integration per path: refused, photo still in its album
  **Note:** Verified via `LastAlbumKeptTest` (16 cases: Batch Manager single and mixed selection, properties page, setInfo empty / invalid-token-only / storage album, setCategory single and plural message, wrong token still core's 403) and E2E `last-album.spec.js` (properties page in the browser: remove the album's x, save, error shown, album still linked). Unit `LastAlbumTest` (16 cases). Each path's handler disabled in turn (flip with EXIT-trap restore, checksums host = container, under the suite lock): batch off -> 2 red, properties off -> 1 red (+ the E2E red), setInfo off -> 3 red, setCategory off -> 2 red, token check dropped -> 1 red; nothing else moved. Unit flips (no-album-before guard, storage `===`, storage `in_array`, `\d+` -> `\d*`) each killed by their tests.
* [x] Move to another album works
  **Note:** Verified via `testBatchMoveWorks`, `testPropertiesMoveToAnotherAlbumWorks`, `testSetInfoReplacingWithAnotherAlbumWorks`, `testSetCategoryMoveWorks`, plus dissociate with another album left (Batch Manager and setCategory) and setInfo append.
* [x] galleryrules suites pass
  **Note:** Verified 2026-10-16 under `flock /tmp/pwg-suite.lock`: unit OK (38 tests); integration OK (51 tests) forward and `--order-by=reverse`; Playwright 10 passed. Neighbours touching the same methods: provenance integration `--filter "CoreAssociation|InheritTest|CorePhotoTextCharacterization|BatchManagerPageTest"` OK (48), photoinfo integration `--filter "SetInfoTest|CoreEditTest|FreitextAssignTest|TagWriteTest"` OK (73).

## Deviations from the design

- Properties page: only the album change is refused; the page's other fields (title, tags, ...) are
  still saved, with core's "Photo informations updated" next to the error. Refusing the whole save
  would drop what the user typed, since the page re-renders from the database.
- Batch Manager per-photo mode ("Einzeln") saves through `pwg.images.setInfo`, so it is refused by
  the API wrapper; its page shows only core's generic error badge, not the message, and nothing of
  that photo's save is kept.
- Not covered (outside the three named paths): deleting an album with "keep photos"
  (`pwg.categories.delete` `photo_deletion_mode=no_delete`, the album screen's delete) still leaves
  its photos in no album.
