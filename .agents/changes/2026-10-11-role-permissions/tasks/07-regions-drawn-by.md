---
id: 07
dependencies:
- 02
---

# Task 07: Normal users delete only regions they drew

Each region records who drew it; a normal user can delete only their own regions, admins and webmasters can delete any.

## References

* `design.md#regions-record-who-drew-them-in-the-index-carried-like-the-id`
* `plugins/persons/include/functions.inc.php` `persons_region_columns()`, `persons_carry_region_ids()`
* `plugins/persons/maintain.class.php` (`add_missing_columns()`)
* `plugins/persons/include/ws_functions.inc.php` `ws_persons_addRegion`, `ws_persons_deleteRegion`
* `docs/agents/decisions/0019-person-region-permission-model.md`, `0020-...`, `0033-region-ids-survive-a-reindex.md`

## Work

* [x] Add a nullable `drawn_by` column to the region table; `update()` adds it to existing installs
  **Note:** `persons_region_columns()`; persons version 1.0.0 -> 1.1.0 so core's autoupdate runs `update()` (local install migrated on the next page load)
* [x] `addRegion` stores the caller's id; the reindex carries `drawn_by` together with the id
  **Note:** `persons_carry_region_ids()` carries it, `persons_claim_drawn_regions()` records the caller on new rows only; a photoedit turn or crop carries it too (`persons_attach_drawn_by()`, answer 1a)
* [x] `deleteRegion` lets a normal user delete only a region whose `drawn_by` is their id; admin and webmaster delete any
  **Note:** `persons_may_delete_region()`; refusal is 403, after the 404 visibility gate
* [x] Picture page: offer the delete control to a normal user only on their own regions
* [x] Unit test for the carry and the permission decision; integration and E2E for own / foreign / nobody's region, as `persons_normal`, `persons_admin`, `persons_webmaster`
  **Note:** `Unit/DrawnByTest`, `RegionIdCarryTest`, `Integration/RegionDrawerTest`, photoedit `ApplyRegionsTest::testACropKeepsWhoDrewEachRegion`, E2E `a face the normal account did not draw`; seed `--drawn-by-viewer` for specs that delete seeded faces as the normal account
* [x] Decision file refining 0019 and 0020
  **Note:** `docs/agents/decisions/0065-normal-users-delete-only-the-regions-they-drew.md`

## Verification

* [x] A region drawn by `persons_normal` can be deleted by `persons_normal`
  **Note:** Verified via `RegionDrawerTest::testANormalUserDeletesTheirOwnRegion`
* [x] A region drawn by another user, or with empty `drawn_by`, is refused for `persons_normal` and the file is unchanged
  **Note:** Verified via `RegionDrawerTest::testANormalUserCannotDeleteAnotherUsersRegion` / `...NobodysRegion` (exiftool reads the file back)
* [x] `persons_admin` and `persons_webmaster` delete any region
  **Note:** Verified via `RegionDrawerTest::testAPrivilegedUserDeletesAnyRegion` (both roles)
* [x] After a write that reindexes the photo, `drawn_by` of unchanged regions is preserved
  **Note:** Verified via `RegionDrawerTest::testAnotherWriteKeepsWhoDrewAnUnchangedRegion`; after a crop via photoedit `ApplyRegionsTest::testACropKeepsWhoDrewEachRegion`
* [x] After `IndexRebuildTest`'s full rebuild, all `drawn_by` values are empty (fails closed)
  **Note:** Verified via `IndexRebuildTest` (sets `drawn_by` on the fixture rows first, asserts none is left)
* [x] Persons unit, integration and E2E suites pass
  **Note:** 2026-10-12, after the last code change: persons unit 203 OK, integration 136 OK (1 pre-existing skip), E2E 157 passed; photoedit unit 95 OK, integration 96 OK, E2E 31 passed
* [x] A normal user cannot crop away a region they did not draw (added 2026-10-12, user decision)
  **Note:** Verified via photoedit `ApplyRegionsTest::testANormalAccountsCropOfAnotherUsersRegionIsRefused` (nobody's and the webmaster's; dry run and write 403, file md5 unchanged), `testANormalAccountCropsAwayItsOwnRegion`, E2E `[NEG] normal: a crop that would cut away a person somebody else marked is refused by name, nothing written`
* [x] The handbook passages in `05-personen.html` and `06-drehen.html` read naturally in German
  **Note:** reviewed by a critical review agent on the user's request; its fixes applied (ledger row 2026-10-12 in `docs/agents/TESTING.md`), `check.php` OK, photoedit `ApplyRegionsTest` and `other-accounts-save.spec.js` rerun green

## Implementation notes

Deviations from the task and design, each decided with the user on 2026-10-12:

* **A photoedit turn or crop carries `drawn_by`** (`persons_attach_drawn_by()`). The design carried it only with the id, and a moved region gets a new id, so the drawer would have lost the right to delete their own region after any edit.
* **A crop is refused when it would cut away a region the caller may not delete.** The review found that photoedit (open to every logged-in account since 0059) bypassed the delete rule. photoedit gained a `photoedit_refusal` filter and persons answers it. This touches photoedit, outside the task's references.
* **A re-type through a hand-made request leaves the region nobody's.** The original drawer loses the right too. Recorded in 0065.
* **Handbook text updated, no re-shoot.** No committed screenshot shows the delete **x**: shot 17 (tagging mode) is of the `atelier` photo, which has no regions. Per `.claude/rules/handbook.md`, a re-shoot is committed only when the screen changed.
* **photoedit's editor shows a refusal's reason.** `editor.js` threw `HTTP <status>` for any non-2xx answer, and ws.php sends a refusal's code as the HTTP status, so the crop refusal showed `HTTP 403`. It now reads the message from the body; found by the new E2E spec.
* Review fixes: 404-before-403 test in `VisibilityTest`; redundant checks removed after equivalent mutants survived (`$drawn_by !== null` in the delete decision, `id !== null` and the null-drawer guard in the claim, a second refusal check under photoedit's lock); `IndexRebuildTest` anti-vacuity scoped to the fixture photos; `PicturePageSourceTest`'s delete-control test renamed for the administrator it runs as.

Mutants (unit unless noted; all killed): claim ignoring previous rows; admin check negated; carry dropping the drawer; attach ignoring type; cut-away test negated; cut-away ignoring permission. Integration: photoedit not passing moved regions to the reindex; `deleteRegion` without the drawer check; `addRegion` without the caller; the template always rendering the delete control; no refusal in photoedit; persons' refusal without the admin bypass; the visibility 404 removed from `deleteRegion`.
