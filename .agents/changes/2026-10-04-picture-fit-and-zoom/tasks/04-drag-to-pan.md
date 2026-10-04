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

* [ ] Write the failing specs first, with the drag action in `PicturePage.js`
* [ ] In `photo.zoom.js`: a pointer drag on the photo scrolls `#theImage` when the photo overflows it
* [ ] Find a stable signal that the persons editor is active (a class or attribute the editor emits on purpose). Drag-to-pan is off while it is set
* [ ] A drag that moved the photo does not count as a click (no navigation, no click-through)
* [ ] Show a grab cursor only while drag-to-pan is possible

## Verification

* [ ] `[HAPPY]` At 100 % on the large fixture, a drag changes the scroll position of `#theImage` by the drag distance
* [ ] `[NEG]` In fit mode, when nothing overflows, a drag does not scroll anything
* [ ] `[ST]` In tagging mode at 100 %, a drag draws a region draft and does not scroll. After leaving tagging mode, a drag pans again
* [ ] `[NEG]` A drag release does not navigate to another photo
* [ ] The whole persons E2E suite passes, including `editor.spec.js`
