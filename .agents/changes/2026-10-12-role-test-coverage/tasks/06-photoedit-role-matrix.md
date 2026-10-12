---
id: 06
dependencies: []
---

# Task 06: photoedit role matrix

Both photoedit cells (turn/crop and warnings) are witnessed for all five roles by named `RoleMatrixTest` rows.

## References

* `design.md#the-target-matrix` (photoedit rows)
* `design.md#constraints-the-tasks-must-respect` (dry run only where it passes the gate first)
* `design.md#every-plugin-gets-_admin-and-_generic-accounts`
* Gates: `plugins/photoedit/include/pipeline.inc.php:175` `photoedit_user_may_edit()`, `include/ws_functions.inc.php:21` (guest), `include/events_public.inc.php` (button), `plugins/persons/include/events_photoedit.inc.php:64` (crop refusal of another account's region, 0065)
* `docs/agents/decisions/0059-photo-edits-are-open-to-every-logged-in-account-that-sees-the-photo.md`
* Existing role cases: `tests/Integration/{PicturePageButtonTest,ApplyTurnTest,ApplyJpegTest,ApplyRegionsTest}.php`, `tests/e2e/{button-visibility,other-accounts-save}.spec.js`

## Deviation from the design

The split is per plugin (owner, 2026-10-12). `photoedit_admin` already exists, so only `photoedit_generic` is new.

Found while implementing (2026-10-12):

- `photoedit.warnings` has no control and no write of its own. Its probe reads only the dry run's
  answer: `see` when it carries both the lost-region warning and the re-encode note, `none` when the
  call is refused, `INCONSISTENT` when accepted without them.
- The warnings rows' regions are drawn by the calling account. A normal or generic account's crop of
  somebody else's region is refused (0065), so with nobody's regions those rows would test persons'
  `own` cell instead of the warning. Admin and webmaster get the same fixture for uniformity.
- `button-visibility.spec.js` is deleted; its button-label check moved into `roles.spec.js`, which
  opens the editor as each role anyway.
- The `[BVA]` public side for generic is the matrix row itself; the private and granted cases gain
  generic. `ApplyTurnTest::testOtherLoggedInAccountsMayTurn` (a real write) also gains generic,
  because the matrix probe only dry-runs.
- Besides the two flips the task names, two more were run: `!is_a_guest()` → `is_classic_user()`
  (separates generic from normal), and persons' drawer ignored in the crop refusal (the gate the
  warnings rows pass through), plus the guard check with a dummy `pwg.photoedit.dummy` method.

## Work

* [x] Add `photoedit_generic` to `tests/Support/TestUsers.php` and `create-test-users.php` (the status is asserted), and a session in `auth.setup.js`
* [x] Check that `pwg.photoedit.apply`'s dry run passes `photoedit_user_may_edit()` before anything else. If it does, the `edit` probe may use it. If not, it turns a copied photo and checks the file
* [x] `tests/Integration/RoleMatrixTest.php`: rows for `photoedit.turn-crop` (button and `apply`) and `photoedit.warnings` (the dry-run answer carries the lost-region warning and the re-encode note), five roles each
* [x] Guard case: prefix `pwg.photoedit.`, lower bound 1 (measured 2026-10-12)
* [x] `tests/e2e/roles.spec.js`: the warning and the note are shown in the editor, one row per role
* [x] Feature-file depth: the private / granted / public `[BVA]` cases gain generic
* [x] Delete the single-role cases the matrix replaces (for example `PicturePageButtonTest` role cases, `button-visibility.spec.js` if it is fully covered), with the mapping in the commit message
* [x] Flip `photoedit_user_may_edit()` (guest check → `is_admin()`, visibility removed) once by hand, and record the results in the `TESTING.md` table (rows in scratchpad `role-matrix/06-docs.md`)
* [x] Update `plugin-test-suites.md` (text handed to the main agent in scratchpad `role-matrix/06-docs.md`): the generic account, the matrix, the guard

## Verification

* [x] 10 role rows plus the guard are green. The E2E role rows are green
* [x] Each flip turns red exactly its rows. After the revert, everything is green
* [x] The photoedit unit, integration and E2E suites pass twice in a row
