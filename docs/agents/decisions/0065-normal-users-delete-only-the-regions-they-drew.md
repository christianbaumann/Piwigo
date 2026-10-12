# 0065 — Normal users delete only the regions they drew

Date: 2026-10-12
Status: accepted
Refines [0019-person-region-permission-model.md](0019-person-region-permission-model.md),
[0020-persons-index-is-derived-the-file-is-the-source-of-truth.md](0020-persons-index-is-derived-the-file-is-the-source-of-truth.md),
[0033-region-ids-survive-a-reindex.md](0033-region-ids-survive-a-reindex.md) (the id is no longer
the only value carried) and
[0059-photo-edits-are-open-to-every-logged-in-account-that-sees-the-photo.md](0059-photo-edits-are-open-to-every-logged-in-account-that-sees-the-photo.md);
supersedes none. Task 07 of `.agents/changes/2026-10-11-role-permissions`.

## Context

Under 0019 every logged-in account that can see a photo could delete any face region on it,
including one another user drew or one that came with the file. The role matrix of the change
gives a normal user the right to remove only its own regions. The MWG region structure has no
field for who drew a region, so the file cannot say.

## Decision

- `piwigo_person_region` gets a nullable `drawn_by` column, the user id of whoever drew the
  region through `pwg.persons.addRegion`. Persons 1.1.0 adds it to an existing install through
  `update()`.
- The reindex carries `drawn_by` together with the id, under 0033's rule: same person, same type,
  every coordinate within `PERSONS_REGION_MATCH_EPSILON` (`persons_carry_region_ids()`). No file
  content comes from the old index, so 0020 holds.
- `addRegion` records the caller only on a row that is new to the index and whose box did not
  exist before under any type (`persons_claim_drawn_regions()`). Re-adding an existing box does
  not take it over. Re-adding it with another type (only a hand-made request can; the editor
  always sends `Face`) gives a new row nobody drew: the region loses its drawer rather than gain
  the caller, so the original drawer can no longer delete it either.
- A photoedit turn or crop moves every region, so each comes back as a new row. persons reads
  every region's `drawn_by` from the index before the move (`persons_attach_drawn_by()`), the
  value travels through `persons_transform_regions()`, and the reindex records it again on the
  moved row.
- Empty means drawn by nobody. `pwg.persons.deleteRegion` lets an administrator (webmaster
  included) delete any region and anyone else only a region whose `drawn_by` is their id
  (`persons_may_delete_region()`); otherwise it answers 403 and the file is untouched. The 404
  for a photo the caller cannot see comes first, so the 403 confirms nothing 0019 hides.
- The picture page renders the delete control only on a region the viewer may delete. A box the
  viewer just drew gets one from `editor.js`, as before.
- A crop may not do what a delete may not. photoedit fires a new filter, `photoedit_refusal`
  (`photoedit_refusal()` in `plugins/photoedit/include/pipeline.inc.php`), before it answers a
  dry run or writes; a non-empty reason refuses the edit with 403. persons answers it for a
  non-administrator with the names of the regions the crop would cut away and the caller may not
  delete (`persons_regions_cut_away_from_others()`). The editor's dry run fails first, so the page
  shows the reason and writes nothing. A turn cuts nobody away and is never refused.

Rejected: a parallel author list in the file's XMP. It would be a custom structure other MWG
readers drop, and a file could claim any author.

## Consequences

- A full rebuild (`pwg.persons.rescan` after a reinstall, the deploy's rescan on the remote)
  leaves every `drawn_by` empty. On the remote every region is nobody's after a deploy. This
  fails closed: a normal user loses a right, it gains none.
- A region moved by a photoedit edit keeps its `drawn_by` but gets a new id (0033 unchanged). A
  region moved by a rotation correction during a reindex loses both. Renaming a person keeps
  both: the person id and the box stay.
- A region written into the file by another program is nobody's; only an administrator removes it,
  by deleting it or by cropping it away.
- The refusal is asked once per request, before photoedit takes its lock. A region drawn between
  that check and the write is not considered; the window is milliseconds and no test can reach it.
- Known gap: re-adding a box identical to an existing one (to within
  `PERSONS_REGION_MATCH_EPSILON`) does not claim it, but `editor.js` still gives the new box a
  delete control the server then refuses. A mouse drag cannot hit an existing box that exactly.
- Tests: `tests/Unit/DrawnByTest.php`, `RegionIdCarryTest`'s drawn-by cases,
  `SchemaDefinitionTest::testDrawnByIsNullable`; photoedit's `ApplyRegionsTest`
  (`testACropKeepsWhoDrewEachRegion`, `testANormalAccountCropsAwayItsOwnRegion`,
  `testANormalAccountsCropOfAnotherUsersRegionIsRefused`,
  `testANormalAccountTurnsAPhotoOfOtherPeoplesRegions`) and E2E
  `[NEG] normal: a crop that would cut away a person somebody else marked is refused by name, nothing written`;
  `VisibilityTest::testDeletingARegionOnAForbiddenImageAnswersLikeAnUnknownOne` (the 404 before
  the 403); `tests/Integration/RegionDrawerTest.php` (own,
  another user's and nobody's region as `persons_normal`, `persons_admin` and `persons_webmaster`,
  the carry across a write, the page's control); `IndexRebuildTest` asserts the rebuild empties
  every `drawn_by`; E2E `a face the normal account did not draw` in `editor.spec.js`. The E2E seed
  marks its regions as the normal account's with `--drawn-by-viewer` where a spec deletes them.
