---
id: 04
dependencies: []
---

# Task 04: persons boxes sit on the face for EXIF Orientation 6 and 8

On a JPEG whose `images.rotation` is 1 or 3, face boxes are drawn on the face and new boxes are stored where they were drawn.

## References

* `design.md#rotation-direction-in-persons`
* `docs/agents/decisions/0035-images-rotation-counts-counter-clockwise.md`
* `plugins/persons/tests/Unit/DisplayRotationTest.php` (skipped)
* `plugins/persons/include/render.inc.php` (`persons_display_box()`)
* `plugins/persons/template/editor.js` (rotation read at line 56, `toStored()`, turns at line 254)
* `plugins/persons/include/functions.inc.php` (`persons_rotate_region()`, `persons_rotation_delta()`)
* `plugins/persons/include/index.inc.php` (`persons_correct_for_rotation()`, `rotation_at_write`)

## Work

* [ ] Un-skip `DisplayRotationTest`, add the code-1 case (Orientation 8), watch both fail for the direction reason
* [ ] Fix `persons_display_box()` to count core's codes counter-clockwise
* [ ] Fix `editor.js` so drawing and storing use the same direction; cover it at the lowest layer that reaches the script (E2E with a seeded Orientation-6 photo if no JS unit layer exists)
* [ ] Check `persons_rotation_delta()` / `persons_correct_for_rotation()` for the same assumption; fix with a failing test first if wrong, or record why they are direction-free
* [ ] Remove the backlog item

## Verification

* [ ] A box at the raw file's top-left on a code-3 photo is shown top-right; on a code-1 photo bottom-left
* [ ] A box drawn on a code-3 photo in the editor is stored at the raw-file position that reads back as the same displayed box
* [ ] Boxes on code-0 and code-2 photos are unchanged
* [ ] persons unit, integration and E2E suites pass
