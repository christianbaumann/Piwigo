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

* [x] Un-skip `DisplayRotationTest`, add the code-1 case (Orientation 8), watch both fail for the direction reason
  **Note:** code 3 gave LEFT `10%` for expected `80%`, code 1 `80%` for `10%`: swapped, the direction reason. Codes 0 and 2 and a `persons_display_turns()` table (incl. 4, 5, -1) added
* [x] Fix `persons_display_box()` to count core's codes counter-clockwise
  **Note:** new `persons_display_turns()` = `(4 - code) % 4`; `persons_rotate_region()` now takes clockwise turns, not a code; the E2E seed's expected corners use the same helper
* [x] Fix `editor.js` so drawing and storing use the same direction; cover it at the lowest layer that reaches the script (E2E with a seeded Orientation-6 photo if no JS unit layer exists)
  **Note:** `toStored()` now turns by the code itself. No JS unit layer exists, so: new `tests/e2e/rotation.spec.js` with seed scenarios `marked` / `marked-empty` and `--rotation=<code>`, a generated PNG with one red block (`FixtureBuilder::MARKER`); the spec finds the red pixels in the loaded photo, an oracle independent of the plugin's helpers
* [x] Check `persons_rotation_delta()` / `persons_correct_for_rotation()` for the same assumption; fix with a failing test first if wrong, or record why they are direction-free
  **Note:** Not direction-free, but already correct: current minus stored is the clockwise turn the pixels took when the file still looks the same on the page, which holds because codes count counter-clockwise (baking Orientation 6 in: 3 → 0, pixels a quarter clockwise, (0 - 3) mod 4 = 1). Existing `RegionGeometryTest` delta cases and `RotationTest` agree; docblock corrected, no code change. Recorded in decision 0054 (supersedes 0035's decision 2)
* [x] Remove the backlog item

## Verification

* [x] A box at the raw file's top-left on a code-3 photo is shown top-right; on a code-1 photo bottom-left
  **Note:** Verified via `DisplayRotationTest` (unit) and `rotation.spec.js` › saved box drawn on the face for codes 3 and 1 (E2E, red pixels)
* [x] A box drawn on a code-3 photo in the editor is stored at the raw-file position that reads back as the same displayed box
  **Note:** Verified via `rotation.spec.js` › box drawn on the face of a code-3 photo is stored on the face in the file (read back with plain exiftool, within 0.02 of `MARKER`)
* [x] Boxes on code-0 and code-2 photos are unchanged
  **Note:** Verified via `DisplayRotationTest::testABoxOnAnUnturnedPhotoStaysPut` / `...HalfTurnedPhoto...`; the whole existing persons E2E suite (code 0) passes
* [x] persons unit, integration and E2E suites pass
  **Note:** 2026-10-11: unit OK (163), integration OK (113, 1 pre-existing skip), E2E 154 passed. Mutants: old `editor.js` direction killed only the editor spec; old display direction killed both overlay specs and the two unit cases
