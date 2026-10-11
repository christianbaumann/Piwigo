# 0054 — persons reads `images.rotation` counter-clockwise

Date: 2026-10-11
Status: accepted
Supersedes, in [0035](0035-images-rotation-counts-counter-clockwise.md), decision 2 ("persons'
display and editor bug is not fixed in this change") and the consequence that boxes on a JPEG with
Orientation 6 or 8 are drawn and stored half a turn away from the face.

## Context

Decision 0035 found that core's `images.rotation` counts quarter turns counter-clockwise: the page
shows the raw file turned `(4 - code) % 4` quarters clockwise. persons applied the code as
clockwise turns in `persons_display_box()` and undid it the same way in `editor.js`, so for codes 1
and 3 both were wrong together and a draw-save-reload round trip still came back in place.

## Decision

- `persons_display_turns()` (`include/functions.inc.php`) converts a code into the clockwise turns
  the page shows; `persons_display_box()` and the E2E seed's expected corners use it.
  `persons_rotate_region()` now takes clockwise turns, not a code.
- `editor.js`'s `toStored()` undoes the display turn: the code itself, in clockwise quarters.
- `persons_rotation_delta()` is unchanged. Current minus stored is the clockwise turn a file's pixels
  took when it still looks the same on the page, which holds only because codes count
  counter-clockwise; its docblock now says so.

Tests: `tests/Unit/DisplayRotationTest.php` (codes 0 to 3, and `persons_display_turns()` per code)
and `tests/e2e/rotation.spec.js`, whose oracle is a red block in a generated photo that the browser
finds in the pixels it was sent.

## Consequences

- Boxes on a photo with code 1 or 3 sit on the face, and boxes drawn there are stored where the file
  has the face.
- Regions stored on such a photo before this fix are half a turn off in the file. None existed:
  every photo in the gallery had code 0 on 2026-10-11.
