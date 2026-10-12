---
id: 03
dependencies: []
---

# Task 03: typetags role matrix

Every typetags cell (6 functions × 5 roles) is witnessed by a named `RoleMatrixTest` row. The work is committed in the submodule and pushed, so a fresh clone runs it.

## References

* `design.md#the-target-matrix` (typetags rows)
* `design.md#how-a-cell-is-checked`, `design.md#a-structural-guard-ties-every-ws-method-to-a-matrix-row`, `design.md#every-plugin-gets-_admin-and-_generic-accounts`
* Gates: `plugins/typetags/main.inc.php:85,99,108,123` (`admin_only`), `:329,384,566` (guest), `:352,401,587` + `typetags_image_visible()` `:497`, `include/events_public.inc.php:140`, `include/taggroups.inc.php:22` (read-only page, generic admitted), core `admin.php:27`
* `docs/agents/decisions/0044-picture-page-tag-methods-respect-album-permissions.md`, `0064-...read-only-pages.md`
* Existing role cases: `tests/Integration/{AddTagTest,RemoveTagTest,AddNewTagTest,CreationCapTest,TypeAddPermissionTest,TypeListUpdateTest,TagGroupsPageTest,VisibilityTest,GroupStyleTest}.php`, `tests/e2e/{normal-account,taggroups,add-new-tag}.spec.js`
* `docs/backlog.md` (unpushed submodule commits), `.claude/rules/plugin-test-suites.md`

## Deviation from the design

The split is per plugin (owner, 2026-10-12).

Task 03 (2026-10-12):
- `typetags.groups` for normal/generic is `see*` in the matrix; the row expects `see` (the probe reads the
  read-only page as see) and the docblock says so.
- `typetags.write-warning` has no method of its own; its row declares `typetags.image.addTag`, whose answer
  carries the warning (assign-remove declares it too).
- `type-new-tag`: the account's cap state is asserted below the cap before the call, not forced (forcing would
  mean deleting the account's activity rows).
- Guard strength was checked by dropping a method from the row declarations (test side), not by registering a
  dummy production method.
- `typetags.badges` has no plugin gate to flip; a direct guest-check removal was not flipped (core refuses guests
  first, decision 0055) - the `!is_classic_user()` flips witness those checks through the generic rows.
- Out of scope, fixed on the way: `SchemaUpgradeTest` drops columns with `ALGORITHM=COPY` (repeated instant DROP
  COLUMN made a later DROP fail with "Row size too large").
- E2E: `roles.spec.js` also has a guest row (login page, no controls); `normal-account.spec.js` is deleted as
  superseded.

## Work

* [x] Add `typetags_admin` (status `admin`, level 8) and `typetags_generic` to `tests/Support/TestUsers.php` and `create-test-users.php`. The script asserts the statuses. Add sessions in `tests/e2e/auth.setup.js`
* [x] `tests/Integration/RoleMatrixTest.php`: rows for `typetags.badges`, `assign-remove`, `type-new-tag`, `write-warning`, `groups` (normal: `see` on `index.php?/taggroups`, edit methods refused), `admin-chips` (normal: `admin.php?page=photo` and `batch_manager` refused). Each row declares its methods
* [x] `type-new-tag` as webmaster and admin. The fixtures stay below the daily cap, and the account's cap state is forced and asserted beforehand
* [x] `write-warning`: the ws answer carries the warning for every logged-in role when the file write fails. Force the failure the way photoinfo's `TagWriteTest` does
* [x] Guard case: prefix `typetags.`, lower bound 7 (measured 2026-10-12). `typetags.tags.setType` and `typetags.type.list` are declared by the `groups` row
* [x] `tests/e2e/roles.spec.js`: `assign-remove` (the + button and the x on a badge) and the `write-warning` display, one row per role
* [x] Feature-file depth: `VisibilityTest` private / granted / public `[BVA]` gains admin and generic where they are missing. `CreationCapTest` gains a webmaster case at the cap boundary if the cap applies to every role
* [x] Delete the single-role cases the matrix replaces, with the mapping in the commit message
* [x] Flip each gate once by hand, and record the results in the `TESTING.md` table of the main repository. **Note:** 10 flips run 2026-10-12; the table is in the scratchpad `role-matrix/03-docs.md` for the main agent (RULES: shared docs are the main agent's)
* [x] Commit inside the submodule, push to `christianbaumann/Piwigo-Colored-Tags`, and bump the submodule in the main repository. Update `plugin-test-suites.md` (accounts, matrix, guard). **Note:** committed and pushed inside the submodule; the bump and the `plugin-test-suites.md` text (in `role-matrix/03-docs.md`) are left to the main agent per RULES

## Verification

* [x] 30 role rows plus the guard are green. The E2E role rows are green. **Note:** Verified via `RoleMatrixTest` (31 tests) in the integration suite and `roles.spec.js` (9 rows: 4 roles x 2 + guest) in the E2E suite, 2026-10-12
* [x] `typetags_generic` matches the normal account on every row. Changing `taggroups.inc.php:22` to `is_classic_user()` turns the generic `groups` row red. **Note:** Verified via the matrix levels (generic = normal in every row, all green) and the `taggroups` flip: exactly `groups as generic` went red
* [x] Each flip turns red exactly its rows. After the revert, everything is green. **Note:** Verified via 10 hand flips with the full integration suite each; results table in `role-matrix/03-docs.md`. The `picture` flip first also killed `badges as generic` (marker coupled to the assignment prefilter); probe fixed, re-flip killed exactly its 3 rows. Guard strength: dropping `typetags.type.list` from the declarations made the guard fail naming it
* [x] The typetags unit, integration and E2E suites pass twice in a row. **Note:** Verified 2026-10-12: unit 88/88 twice, integration 155/155 twice, E2E 56/56 twice (all under the suite lock). Reverse-order run not done
* [x] A fresh `git submodule update` finds the pushed commit. **Note:** Verified 2026-10-12 with a fresh clone of `christianbaumann/Piwigo-Colored-Tags`: commit `40dafb9` is on `master` and carries `RoleMatrixTest.php`. The main repo's submodule bump to it is still the main agent's
