---
id: 04
dependencies:
- 02
---

# Task 04: Drag-to-pan

The viewer drags a photo that is bigger than the area to pan it. Region drawing in the persons editor keeps its drag.

## References

* `design.md#panning`
* `design.md#click-navigation-off-when-zoomed-in`
* `themes/modus/js/photo.zoom.js` (from task 02)
* `plugins/persons/template/editor.js:368` (mousedown starts a region draft)
* `plugins/persons/tests/e2e/editor.spec.js`, `plugins/persons/tests/e2e/support/PicturePage.js` (`dragBox()`, `enterTaggingMode()`)

## Work

* [x] Write the failing specs first, with the drag action in `PicturePage.js`
  **Note:** `plugins/persons/tests/e2e/picture-pan.spec.js`, with `dragVisiblePhoto()` and `photoCursor()` in `PicturePage.js`. Watched red before the change: 3 of 4 failed (cursor `auto` not `grab`, no scroll, scroll off by 209 px); the `[NEG]` fit case passes by design.
* [x] In `photo.zoom.js`: a pointer drag on the photo scrolls `#theImage` when the photo overflows it
  **Note:** Mouse drag only (`mousedown` on `#theMainImage`, left button, while `zoomedIn`). Touch needs nothing: `#theImage` is `overflow:auto`, so a finger already scrolls it natively. "Overflows" is `zoomedIn` (scale > fit), the same flag the click guard and `max-height` use. After review: pans by deltas, so a zoom step mid-drag carries on from where the step left the photo; a move with no button held ends the drag (a release a context menu or another window took). Witnessed by `a zoom step during a drag does not throw the photo back` and `a mouse move with no button held ends the drag`, both watched red first.
* [x] Find a stable signal that the persons editor is active (a class or attribute the editor emits on purpose). Drag-to-pan is off while it is set
  **Note:** `persons-tagging` on `#persons-stage`, set by `editor.js` `enter()`/`exit()`, already the anchor of `editor.css` and of `PicturePage.taggingStage`. Named once as `EDITOR_DRAWING` in `photo.zoom.js`. A second guard holds as well: in tagging mode the overlay takes the pointer (`pointer-events: auto`), so the `mousedown` target is not `#theMainImage`.
* [x] A drag that moved the photo does not count as a click (no navigation, no click-through)
  **Note:** The existing capture-phase click guard now also holds while `panning` (press to the click its release fires). `zoomedIn` alone was not enough: pressing `0` mid-drag fits the photo, and the release then navigated. Witnessed by `a drag released after 0 fitted the photo does not navigate`, watched red first.
* [x] Show a grab cursor only while drag-to-pan is possible
  **Note:** `zoomPannable` (toggled in `apply()`, cleared by the size-menu override) → `cursor: grab`; `zoomPanning` during a drag → `grabbing`. In `photo.zoom.css`, so every skin gets it. In tagging mode the overlay's crosshair covers it.

## Verification

* [x] `[HAPPY]` At 100 % on the large fixture, a drag changes the scroll position of `#theImage` by the drag distance
  **Note:** Verified via `picture-pan.spec.js` → `a drag at 100 % scrolls the area by the drag distance` (both axes, ±1 px; asserts the `grab` cursor before and after, which killed the mutant that leaves `zoomPanning` on).
* [x] `[NEG]` In fit mode, when nothing overflows, a drag does not scroll anything
  **Note:** Verified via `in fit the photo offers no pan`: asserts nothing overflows and the cursor is not `grab`. A scroll-unchanged assertion was dropped in review - with nothing to scroll it could not fail. Killed the mutant `toggleClass('zoomPannable', true)`.
* [x] `[ST]` In tagging mode at 100 %, a drag draws a region draft and does not scroll. After leaving tagging mode, a drag pans again
  **Note:** Verified via `in tagging mode a drag draws a region, after it a drag pans again`. Killed the mutant that drops both guards (target and class). Dropping only the class guard survives: the target guard alone covers it while the overlay takes the pointer - recorded, not a weak test. The spec re-centres `#theImage` after entering tagging mode because Playwright scrolls the toggle below the photo into view before clicking it.
* [x] `[NEG]` A drag release does not navigate to another photo
  **Note:** Verified via `a drag released over the next-photo zone does not navigate` (`neighbours` seed). The first draft released mid-photo and survived the mutant that removes the click guard - the theme's click zones are fractions of the whole photo, not of the visible part. Now drags at the right end of the scrolled photo; killed that mutant.
* [x] The whole persons E2E suite passes, including `editor.spec.js`
  **Note:** `ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; cd plugins/persons && npx playwright test'` → 94 passed (3.6 min), 2026-10-05, after the review fixes.

## Status

Approved 2026-10-05: all work and verification items done, no manual steps. Independent review findings (stuck drag after a lost mouseup, zoom step or `0` mid-drag, a vacuous fit assertion, no check that `zoomPanning` clears) fixed with a test watched red first for each.
