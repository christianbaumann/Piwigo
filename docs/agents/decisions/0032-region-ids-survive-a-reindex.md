# 0032 — An unchanged region keeps its id across a reindex

Date: 2026-10-04
Status: accepted
Refines [0020-persons-index-is-derived-the-file-is-the-source-of-truth.md](0020-persons-index-is-derived-the-file-is-the-source-of-truth.md);
does not supersede it.

## Context

`persons_reindex_image()` replaced a photo's `piwigo_person_region` rows wholesale on every
write, so every row came back under a new id. The tagging editor deletes by the
`data-person-region` ids the page was rendered with. After the first save on a page, those ids
named rows that no longer existed:

- deleting a box that was on the page before the save was refused;
- `commit()` in `editor.js` adopts "the one returned id the page does not know" for a new box.
  With every id new, that was the first region on the photo, so deleting the box just added
  removed somebody else's face from the file.

Found 2026-10-04 while building the live Personen row; both reproduced in
`plugins/persons/tests/e2e/editor.spec.js` (`removing a person`).

## Decision

The reindex still builds every row from the file alone. Before it deletes the old rows it reads
them, and `persons_carry_region_ids()` gives a new row the id of an old one when the region is
unchanged: same `person_id`, same `region_type`, every coordinate within
`PERSONS_REGION_MATCH_EPSILON` (the tolerance the delete path already matches the file by). Each
old id is used at most once. A row with no match gets no id and the database assigns one.

Only the id is carried. No content is taken from the previous index, so 0020's guarantee holds:
a wrong row still cannot outlive one rescan.

Rejected: re-syncing ids in the browser after each write. The page would have to match its boxes
to the returned regions by geometry, which needs the display-to-storage conversion for boxes it
did not draw, and it breaks as soon as a region on the page is one the server skipped rendering.

## Consequences

- A region that moved (rotation correction, an external edit) gets a new id. An open page then
  holds a stale id for it until reload. Accepted: that is the drift window 0020 already accepts.
- `>` against `>=` at exactly `PERSONS_REGION_MATCH_EPSILON` is not tested: no coordinate a file
  round trip produces lands on that boundary, so the mutant is unreachable rather than missed.
- Tests: `tests/Unit/RegionIdCarryTest.php`; `AddRegionTest::testAddingARegionKeepsTheIdsOfThoseAlreadyThere`;
  `DeleteRegionTest::testARegionCanBeDeletedByTheIdItHadBeforeAnotherDelete`; E2E
  `a box rendered before a save can still be deleted after it` and
  `a box added to a photo with faces deletes only itself`.
