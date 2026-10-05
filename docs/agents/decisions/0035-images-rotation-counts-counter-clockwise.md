# 0035 — `images.rotation` counts counter-clockwise; persons' overlay bug on rotated JPEGs is recorded, not fixed

Date: 2026-10-05
Status: accepted
Design: `.agents/changes/2026-10-05-photo-rotate-crop/design.md` (task 05)

## Context

The photo-edit design and `plugins/persons` both describe `images.rotation` as quarter turns
**clockwise**. Core does the opposite. `pwg_image::get_rotation_angle()` maps EXIF Orientation 6
(shown turned 90° clockwise) to angle 270, which is code 3. `i.php` hands that angle to
`pwg_image::rotate()`, which turns counter-clockwise. Measured 2026-10-05: a 300x200 JPEG with a red
top-left marker and Orientation 6 comes out of that path as 200x300 with the marker top-right.

So the page shows the raw file turned `(4 - code) % 4` quarters clockwise.

`persons_display_box()` (`include/render.inc.php`) and the inverse in `template/editor.js` apply the
code as clockwise turns. For codes 1 and 3 (Orientation 8 and 6), every box is therefore drawn, and
every new box stored, half a turn away from the face. `persons_rotation_delta()` is not affected: it
only uses the difference between two codes.

None of the 105 photos in the gallery has a non-zero rotation (all PNG), so nothing visible is
wrong today.

## Decision

1. **photoedit uses core's real semantics.** `photoedit_raw_turns()` turns the raw file by
   `(4 - code) % 4` plus the requested turns, and hands the result to its events as `raw_turns`.
   persons' `photoedit_end` handler uses `raw_turns` and does not interpret the code itself.
2. **persons' display and editor bug is not fixed in this change.** It is recorded as a skipped
   reproducing test (`plugins/persons/tests/Unit/DisplayRotationTest.php`) and as a backlog entry.
   Fixing it touches the overlay and the editor, and their E2E coverage. That is a change of its
   own, and it only matters once a JPEG with Orientation 6 or 8 is in the gallery.

## Consequences

- Saving such a JPEG in photoedit bakes the rotation in (code 0), and from then on persons draws
  its regions correctly.
- Until the fix, a JPEG with Orientation 6 or 8 shows its persons boxes in the wrong place, and new
  boxes drawn on it are stored wrongly.
- The design document's "quarter turns clockwise" is corrected by this decision.
