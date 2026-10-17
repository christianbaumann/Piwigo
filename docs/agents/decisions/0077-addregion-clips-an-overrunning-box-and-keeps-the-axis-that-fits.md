# 0077 — addRegion clips an overrunning box and keeps the axis that fits

Date: 2026-10-17
Status: accepted
Refines [0020-persons-index-is-derived-the-file-is-the-source-of-truth.md](0020-persons-index-is-derived-the-file-is-the-source-of-truth.md);
supersedes none. Task 28 of `.agents/changes/2026-10-15-exploratory-testing-answers`.

## Context

SES-2026-10-15-03: a call with y 0.1, h 0.3 came back as y 0.125, h 0.25 on a 1200x800 photo.
The coordinates are normalized and centre origin, so the top edge was at 0.1 - 0.15 = -0.05, outside
the photo. `persons_prepare_region_for_write()` runs `persons_clip_region()`, which applies the MWG
rule: a box whose centre is inside but whose edge is not is cut to the edge (top 0, bottom 0.25,
centre 0.125, height 0.25). The photo size plays no part.

## Decision

- The clip stays: a region past the frame is not valid MWG, and other readers of the file
  would clamp or reject it differently. The editor clamps its drawn box to the photo
  (`editor.js`), so only a hand-made request can overrun.
- A box inside the frame is stored exactly as sent (already so; now pinned at both layers).
- Found while analysing: once one axis was clipped, the other was recomputed through the corner
  form too, so x 0.5, w 0.2 came back as w 0.20000000000000007. `persons_clip_region()` now
  recomputes only the clipped axis and keeps the sent values of the axis that fits.

## Rejected

- Refusing an overrunning box: a rejected call for a frame one pixel past the edge helps no one.
- Accepting and documenting the old behaviour: it left float noise on the axis nobody clipped.
