---
id: 33
dependencies: []
---

# Task 33: Saving the Notiz leaves the photo form clean

Leaving the photo properties page after a Notiz save raises no unsaved-changes warning.

## References

* `design.md#saving-the-notiz-leaves-the-photo-form-clean`
* `plugins/provenance/include/functions.inc.php:779`, `events_admin.inc.php:180`

## Work

* [x] Place the Notiz block outside `<form id="pictureModify">`

## Verification

* [x] E2E: Notiz save, navigate away, no `beforeunload` dialog
  **Note:** Verified via `photo-provenance.spec.js` "saving the note leaves no unsaved-changes warning on leaving"; watched it fail first (dialog raised) before the change. Re-proved 2026-10-17 by flipping the anchor back to `<div class="savebar-footer">`: `expect(dialogs()).toBe(0)` received 1.
* [x] Editing a core field still warns
  **Note:** Verified via `photo-provenance.spec.js` "editing a core field still warns on leaving" (description field, one dialog).
* [x] provenance suites pass
  **Note:** Verified via unit (307 tests OK), E2E (65 passed). Integration: 379 of 380 pass; the one failure, `CoreUploadChunkIsolationTest::testCancelRemovesTheSentChunks`, belongs to task 27's in-progress upload characterization and does not touch the photo screen.

## Deviations

* The anchor is now `{include file='include/album_selector.inc.tpl'}` (the line right after `</form>`) instead of the save bar; the block is wrapped in `#provenance-photo-wrap` with CSS (margin, 100px bottom clearance for the fixed save bar, left aligned). Outside the form the block sits below the two-column area rather than among the fields; at first the fixed save bar covered the Save button.
* `PhotoTemplateAnchorTest::testAnchorFollowsThePhotoFormFields` replaced by `testAnchorFollowsTheClosingOfThePhotoForm`.
