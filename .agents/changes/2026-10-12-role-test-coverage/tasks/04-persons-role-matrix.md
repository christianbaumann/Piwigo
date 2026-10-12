---
id: 04
dependencies: []
---

# Task 04: persons role matrix

Every persons cell (7 functions × 5 roles) is witnessed by a named `RoleMatrixTest` row. This includes the `own` level for region deletion and the admin's save on the tagging screen.

## References

* `design.md#the-target-matrix` (persons rows), `design.md#how-a-cell-is-checked` (`own`)
* `design.md#a-structural-guard-ties-every-ws-method-to-a-matrix-row`, `design.md#every-plugin-gets-_admin-and-_generic-accounts`
* Gates: `plugins/persons/main.inc.php:157,169,181` (`admin_only`), `include/ws_functions.inc.php:54,155,247` (guest), `:371` + `persons_may_delete_region()` (drawer), `:406,458,494` (`is_webmaster()`), `include/render.inc.php:71`, `include/events_public.inc.php:32`, `include/events_photoedit.inc.php:64` (crop refusal), `admin/photo.php:22`, `admin/persons.php:19,82`, the visibility checks (0061)
* `docs/agents/decisions/0019-...`, `0057-...`, `0061-...`, `0065-normal-users-delete-only-the-regions-they-drew.md`
* Existing role cases: `tests/Integration/{AddRegionTest,DeleteRegionTest,RegionDrawerTest,SearchTest,VisibilityTest,AdminOnlyTest,AdminPersonsScreenTest,AdminPhotoScreenTest,PersonAdminApiTest,PicturePageSourceTest}.php`, `tests/e2e/{overlay,editor,admin,admin-persons}.spec.js`
* E2E sessions: `auth.json` = normal, `auth-admin.json` = **webmaster**, `auth-administrator.json` = admin

## Deviation from the design

The split is per plugin (owner, 2026-10-12). `persons_admin` already exists, so only `persons_generic` is new.

Implementation deviations (task 04 agent, 2026-10-12):

* `admin-tagging`'s write part (a save sent from the screen, `addRegion` with the screen as Referer) is read only where the screen was served. `addRegion` is also add-region's method, which normal users may call from the picture page, so for normal/generic/guest the row checks only the screen and its editor.
* `names-as-tags` has no ws part of its own (it is core's tag row); its row declares no method.
* `name-list`'s integration row reads `getList` only; the picker is JS-built and lives in `roles.spec.js`.
* `delete-region`'s drawers are set in the database (`drawn_by`), so the row does not depend on the role's own `addRegion`. A guest's "own" region is a normal user's.
* `AdminOnlyTest::testANormalUserCannotRenameDeleteOrRescan` is kept: it pins core's 401 refusal code, which the matrix row does not assert. Only the guest case is deleted there.
* `RoleMatrixTest` takes about 2:40 for 36 rows (each region write runs exiftool and photoinfo's keyword write), not "seconds".

## Work

* [x] Add `persons_generic` to `tests/Support/TestUsers.php` and `create-test-users.php` (the status is asserted), and a session in `auth.setup.js`
* [x] `tests/Integration/RoleMatrixTest.php`: rows for `persons.overlay`, `names-as-tags`, `add-region`, `delete-region`, `name-list`, `admin-screen` (admin: `see`, the list without controls; webmaster: `edit`), `admin-tagging`. Each row declares its methods
* [x] `delete-region` probe for `own`: as each role, delete a region that role drew and one drawn by another account. Normal and generic: own accepted, other refused. Admin and webmaster: both accepted. Guest: both refused
* [x] `admin-tagging` as admin: open the screen and **save** a region on a visible photo. That case is missing today
* [x] `name-list` and `names-as-tags` as webmaster (missing today)
* [x] Guard case: prefix `pwg.persons.`, lower bound 7 (measured 2026-10-12). `getRegions` is declared by the `overlay` row
* [x] `tests/e2e/roles.spec.js`: overlay, the editor (draw), the delete menu and the name picker, one row per role, through the stored sessions
* [x] Feature-file depth: `RegionDrawerTest` `[DT]` (role × drawer own/other/nobody) gains generic, and `VisibilityTest` gains generic and admin where they are missing. Do **not** extend `IndexRebuildTest`
* [x] Delete the single-role cases the matrix replaces (for example parts of `AdminOnlyTest`), with the mapping in the commit message
* [x] Flip each gate once by hand (including `persons_may_delete_region()` → always true, and `is_webmaster()` → `is_admin()`), and record the results in the `TESTING.md` table
  **Note:** 16 flips + guard probe; rows handed to the main agent in scratchpad `role-matrix/04-docs.md` (RULES.md item 1)
* [x] Update `plugin-test-suites.md`: the generic account, the matrix, the guard
  **Note:** text in scratchpad `role-matrix/04-docs.md`; the main agent applies it (RULES.md item 1)

## Verification

* [x] 35 role rows plus the guard are green. The E2E role rows are green
  **Note:** Verified via `RoleMatrixTest` (36 tests) inside two full integration runs and `roles.spec.js` (5 tests) inside two full E2E runs
* [x] The `own` row fails if a normal user may delete another account's region, and also fails if a normal user may not delete their own
  **Note:** Verified via flips `persons_may_delete_region()` admin branch → `if (true)` and drawer branch → `return false`: `delete-region` as normal and generic red in both
* [x] Each flip turns red exactly its rows. After the revert, everything is green
  **Note:** Verified via 16 hand flips (table in scratchpad `04-docs.md`); three are shadowed gates (admin_only, admin/photo.php:22, admin/persons.php:19) and turn nothing red, as predicted; guard verified with a temporary `pwg.persons.zzGuardProbe` method
* [x] The persons unit, integration and E2E suites pass twice in a row
  **Note:** unit 203 OK; integration 179 OK (1 pre-existing skip, decision 0018) x2; E2E 163 passed x2 (back to back in one lock hold)
