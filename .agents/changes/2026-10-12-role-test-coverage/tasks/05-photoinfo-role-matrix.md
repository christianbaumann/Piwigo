---
id: 05
dependencies: []
---

# Task 05: photoinfo role matrix

Every photoinfo cell (6 functions × 5 roles) is witnessed by a named `RoleMatrixTest` row. This includes a normal user's tag change reaching the file as keywords.

## References

* `design.md#the-target-matrix` (photoinfo rows)
* `design.md#a-structural-guard-ties-every-ws-method-to-a-matrix-row` (wrapped methods are not enforced)
* `design.md#every-plugin-gets-_admin-and-_generic-accounts`
* Gates: `plugins/photoinfo/main.inc.php:137,149` (`admin_only` on rescan and prune), `include/ws_functions.inc.php:163` (guest), `:179` + `photoinfo_image_visible()` `:195`, `include/events_public.inc.php:35` (`$editable`), core `admin.php:27` (core screens), the wrappers in `include/events_core_edit.inc.php` and `include/events_tags.inc.php`
* `docs/agents/decisions/0046-a-photos-tags-travel-in-its-file.md`, `0058-...editable-by-logged-in-users.md`, research decision 5
* Existing role cases: `tests/Integration/{SetDateTest,SetInfoTest,PicturePageSourceTest,RescanTest,PruneTagsTest,CoreEditTest,TagWriteTest}.php`, `tests/e2e/{date-edit,info-edit}.spec.js`

## Deviation from the design

The split is per plugin (owner, 2026-10-12). `photoinfo_admin` already exists, so only `photoinfo_generic` is new.

Implementation deviations (2026-10-12):

* `core-screens` declares and calls only `pwg.images.setInfo` and `pwg.tags.rename` of the wrapped core methods. `pwg.tags.duplicate|merge|delete` share core's `admin_only` and the same wrapper (`photoinfo_wrap_method()` keeps the options, flipped once); their writes stay covered by `TagWriteTest`. `tag-keywords` declares and calls all three wrapped typetags methods.
* Shared docs not edited here (RULES.md): the `plugin-test-suites.md` text and the `TESTING.md` flip rows are in `scratchpad/role-matrix/05-docs.md`.
* `PicturePageSourceTest` keeps its role providers: each case also checks content, and its guest runs with `guest_access` on, which the matrix's guest does not. Deleted instead: the single-role cases of `SetDateTest`, `SetInfoTest`, `RescanTest`, `PruneTagsTest` (mapping in the commit message).
* `TagWriteTest` gains no normal case: no gate in photoinfo's write path depends on role (the gate is typetags'), and the matrix's `tag-keywords` normal row already writes as normal.
* Each flip was run against the role-dependent subset of the integration suite (63 tests), not the whole suite, to keep the shared lock short; the whole suite ran twice green afterwards.

## Work

* [x] Add `photoinfo_generic` to `tests/Support/TestUsers.php` and `create-test-users.php` (the status is asserted), and a session in `auth.setup.js`
* [x] `tests/Integration/RoleMatrixTest.php`: rows for `photoinfo.rows`, `edit-date`, `edit-info`, `core-screens` (guest and normal: `admin.php?page=photo` refused and `pwg.images.setInfo` refused), `tag-keywords`, `rescan-prune`. Each row declares its methods, including the wrapped core and typetags methods
* [x] `tag-keywords` for normal and generic: a tag added on the picture page reaches the file's three keyword fields and the marker. Read it back with `FixtureBuilder::readKeywords()`. This needs typetags active, which `assertPluginActive()` checks
* [x] Guest rows: the matrix assumes `guest_access` off. Keep the existing `guest_access`-row cases where they test something else
* [x] Guard case: prefix `pwg.photoinfo.`, lower bound 4 (measured 2026-10-12)
* [x] Feature-file depth: the private / granted / public `[BVA]` cases in `SetDateTest` and `SetInfoTest` gain generic. `TagWriteTest` gains a normal-account case only if a gate in the write path depends on role
* [x] Delete the single-role cases the matrix replaces (for example `PicturePageSourceTest` role-provider cases that check only editability), with the mapping in the commit message
* [x] Flip each gate once by hand (including `$editable = !is_a_guest()` → `is_admin()`, and removing `photoinfo_image_visible()`), and record the results in the `TESTING.md` table
* [x] Update `plugin-test-suites.md`: the generic account, the matrix, the guard and its limit

## Verification

* [x] 30 role rows plus the guard are green
  **Note:** Verified via `RoleMatrixTest` (31 tests) inside the full integration suite, `OK (211 tests, 1219 assertions)` twice.
* [x] The `tag-keywords` row as normal fails if photoinfo stops writing a normal user's tags
  **Note:** Verified via a flip in `photoinfo_call_and_write()` (`if (!is_admin()) return $result;`): `tag-keywords` as normal and generic red, nothing else.
* [x] Each flip turns red exactly its rows. After the revert, everything is green
  **Note:** Verified via 9 flips (table in `scratchpad/role-matrix/05-docs.md`); each red list matched the expected rows; full integration suite green twice after the last revert, host `git diff` of `plugins/photoinfo/main.inc.php` and `include/` empty.
* [x] The photoinfo unit, integration and E2E suites pass twice in a row
  **Note:** Verified via unit `OK (269 tests, 511 assertions)`, integration `OK (211 tests, 1219 assertions)` x2, E2E `58 passed` x2 (all under `/tmp/pwg-suite.lock`).
