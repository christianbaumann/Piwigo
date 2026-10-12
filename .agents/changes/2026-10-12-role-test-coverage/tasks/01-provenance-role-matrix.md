---
id: 01
dependencies: []
---

# Task 01: provenance role matrix

Every provenance cell of the target matrix (8 functions × guest, generic, normal, admin, webmaster) is witnessed by a named `RoleMatrixTest` row. A changed provenance gate fails exactly the rows it affects.

## References

* `design.md#the-target-matrix` (provenance rows)
* `design.md#how-a-cell-is-checked`, `design.md#where-each-depth-lives`
* `design.md#one-rolematrixtest-per-plugin-is-the-executable-matrix`
* `design.md#a-structural-guard-ties-every-ws-method-to-a-matrix-row`
* `design.md#every-plugin-gets-_admin-and-_generic-accounts`
* `design.md#each-gate-is-flipped-once-by-hand-to-prove-the-rows-can-fail`
* Gates: `plugins/provenance/main.inc.php:114-168` (`admin_only`), `include/ws_functions.inc.php:74,182,307,366` (guest), `include/events_page.inc.php:29` (read-only page), `:211` (file-error history rows for admins), `:242` (album link), core `admin.php:27`, `provenance_inherit_policy()` in `include/functions.inc.php`
* `docs/agents/decisions/0062-provenance-writeback-keeps-no-webmaster-check.md`, `0064-...read-only-pages.md`, `0060-...lounge-inherits-in-keep.md`
* Existing role cases: `tests/Integration/{ApplyTest,WriteBackTest,SetAlbumInfoTest,SetPhotoInfoTest,HistoryTest,ReadOnlyPageTest,GuestLockoutTest,BatchManagerPageTest,InheritTest}.php`, `tests/e2e/{core-admin-screens,provenance-page}.spec.js`
* `.claude/rules/testing.md` (*Test accounts*), `.claude/rules/mutation-testing.md` (timing), `.claude/rules/plugin-test-suites.md`

## Deviation from the design

`design.md#tasks-shape` splits by layer (accounts, matrices, E2E, depth, flips). The owner chose a split per plugin on 2026-10-12, so this task carries all of these for provenance. The `testing.md` account sentence is updated here, once for all plugins.

Deviations made while implementing (2026-10-12):

* The probes return `see*` as well as `none | see | edit | INCONSISTENT`: the matrix itself distinguishes `see*` (read-only page only, 0064), and for `history` the difference between `see*` (normal) and `see` (admin, with `getHistory`) is the only thing an `admin_only` flip on `getHistory` changes.
* `move-prompt` and `inherit` have no plugin method; their ws part is core's `pwg.images.setCategory` (move with `replace`, associate), which they declare.
* The file-error detail (`events_page.inc.php:211`) is tested in `ReadOnlyPageTest` (admin case added), not as a matrix row: it is content detail on a page every role sees.
* Visibility `[BVA]`: an administrator does **not** see a private album without a grant on gallery pages (core's `calculate_permissions()` exempts admins only from locked albums), so the admin case asserts 404, like normal and generic.
* The four `is_a_guest()` checks in `ws_functions.inc.php` cannot turn a row red: `admin_only` and core's guest lockout (0055) refuse first. Recorded in the flip table instead of inventing a test.
* Shared docs (`TESTING.md`, `plugin-test-suites.md`, `testing.md`) are not edited here (parallel-agent rules); the text is in `scratchpad/role-matrix/01-docs.md`.

## Work

* [x] Add `provenance_admin` (status `admin`, level 8) and `provenance_generic` (status `generic`) to `tests/Support/TestUsers.php` and `create-test-users.php`. The script asserts both statuses. `Config` fails with a message that names the script. `tests/e2e/auth.setup.js` saves a session for each
* [x] `tests/Integration/RoleMatrixTest.php`: a data provider with one row per (function, role) for `provenance.public-row`, `album`, `apply`, `writeback`, `photo-note`, `move-prompt`, `inherit`, `history`. Each row is tagged and declares its ws methods. One probe per function returns `none | see | edit | INCONSISTENT`, following the design's table. Each role logs in once per run
* [x] Guest and normal rows request each function's own page, including `admin.php?page=album&cat_id=…&tab=properties`, `page=photo&image_id=…&tab=properties` and the Batch Manager move panel, plus its own ws method
* [x] `provenance.inherit`: the admin and webmaster rows link a photo through the API, and the album values arrive with a history row. The guest, generic and normal rows empty the lounge through a page view, which gives mode `keep` and no history row (0060). A normal account has no linking path at all
* [x] Guard case: `reflection.getMethodList` as the webmaster, filtered by the prefix `pwg.provenance.` (a constant), has a lower bound (5, measured 2026-10-12), and every method is declared by a row
* [x] Feature-file depth: add the visibility `[BVA]` cases that are missing for the new roles (private album / private album with a grant) where a provenance gate depends on visibility. Include the file-error history rows (`events_page.inc.php:211`) for admin vs normal
* [x] Delete the single-role cases the matrix replaces (for example `WriteBackTest::testANonAdminIsRefused`, `ApplyTest::testGuestIsRefused`). Keep the cases that also check content or a combination. Put the mapping old → row in the commit message
* [x] Decision file superseding the "checked by hand" consequence of 0062: Admin's write-back is now witnessed by a matrix row. The no-fix itself stands
* [x] Flip each gate listed in References once by hand. Record gate → expected red rows → result, dated, in `docs/agents/TESTING.md` (table handed to the main agent in `scratchpad/role-matrix/01-docs.md`; core `admin.php:27` is task 02's)
* [x] `.claude/rules/plugin-test-suites.md`: the new accounts, `RoleMatrixTest`, the guard. `.claude/rules/testing.md`: replace "both plugins follow this" with the actual account list (text in `scratchpad/role-matrix/01-docs.md`; the main agent edits the shared files)

## Verification

* [x] `RoleMatrixTest` has 8 × 5 = 40 role rows plus the guard, and all are green
  **Note:** Verified via `RoleMatrixTest` (41 tests) inside the provenance integration suite, 296 tests green twice in a row
* [x] `provenance_admin` gets `edit` on `writeback`, `apply`, `album`, `photo-note` and `move-prompt` with no hand check
  **Note:** Verified via the `[BVA] provenance.<function> as admin` rows; decision 0066 supersedes 0062's hand check
* [x] `provenance_generic` gets exactly the normal account's level on every row
  **Note:** Verified via the `[ECP] ... as generic` rows, whose expected level is read from the normal column
* [x] Each gate flip turns red exactly the rows recorded for it, and nothing else. After the revert, everything is green
  **Note:** Verified by 10 flips under the suite lock (table in `scratchpad/role-matrix/01-docs.md`); every flip turned red exactly the predicted rows; the four ws guest checks turn nothing red (shadowed, recorded). Full suite green twice afterwards
* [x] A new `pwg.provenance.*` method registered without a row makes the guard fail and name it (checked once by hand)
  **Note:** Verified 2026-10-12: `pwg.provenance.flipDummy` added in the container, `testEveryPluginMethodIsDeclaredByAMatrixRow` failed listing it, reverted
* [x] The provenance unit, integration and E2E suites pass twice in a row (commands in `plugin-test-suites.md`)
  **Note:** unit 273 green; integration 296 (3 skipped, pre-existing) green twice; E2E 57 green twice (setup saves all four sessions)
