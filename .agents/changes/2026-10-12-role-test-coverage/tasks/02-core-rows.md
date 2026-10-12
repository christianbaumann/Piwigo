---
id: 02
dependencies:
- 01
---

# Task 02: core rows in provenance's role matrix

The five fork-local core functions have matrix rows for all five roles, so a change to their gates fails a named row: zoom, the sub-album count, the translated merge buttons, the German strings and the handbook.

## References

* `design.md#the-target-matrix` (core rows)
* `design.md#integration-is-the-layer-for-gates-e2e-only-where-javascript-builds-the-result`
* `docs/agents/decisions/0032-modus-picture-fit-and-zoom-is-a-fork-local-theme-edit.md`, `0037-album-thumbnails-count-only-listed-subalbums.md`, `0052-named-core-german-strings-are-replaced-on-purpose.md`, `0063-the-handbook-is-served-through-a-login-checking-entry.md`
* `themes/modus/js/photo.zoom.js`, `include/category_cats.inc.php` (`discount_hidden_subalbums()`), `admin/themes/default/template/tags.tpl`, `local/language/de_DE.lang.php`, `handbuch.php`, `include/functions_handbuch.inc.php`
* Existing cases: `plugins/provenance/tests/Integration/{CoreHiddenSubalbumCountTest,GermanAdminScreenTest,HandbookAccessTest}.php`, `tests/e2e/{core-admin-screens,handbuch-pages}.spec.js`, `plugins/persons/tests/e2e/picture-{zoom,fit,pan}.spec.js` (zoom, run only as normal)

## Deviation from the design

As in task 01: the split is per plugin. Core rows live in provenance's `RoleMatrixTest` (design), so this task depends on task 01's accounts and probe structure.

Deviations made while implementing (2026-10-12):

* `core.german-strings`: the only override a gallery page renders is typetags' `Type a new tag` field on the picture page, so the row reads that one for every role, asserts typetags is active, and also goes red if typetags hides its field for a role. No core-only string reaches a non-admin page.
* `core.zoom`'s integration row cannot look for `photo.zoom.js` by name: core combines the footer scripts into `_data/combined/*.js`. It fetches the page's scripts and looks for the global the file declares (`var pwgZoom`), with the name read from `photo.zoom.js`.
* The zoom E2E uses the `writeback` seed's throwaway album (a copied photo), not a new seed scenario.
* `admin.php:27` alone is shadowed: every admin page the matrix requests repeats `check_status(ACCESS_ADMINISTRATOR)`. Flipped alone (nothing red) and together with `admin/tags.php:15` (exactly `core.merge-buttons as normal` red). Recorded in `02-docs.md`.
* `CoreHiddenSubalbumCountTest`'s two role methods became one data-provided method over webmaster, admin, normal and generic.
* Shared docs are not edited here (parallel-agent rules); the text is in `scratchpad/role-matrix/02-docs.md`.

## Work

* [x] Add rows for `core.subalbum-count`, `core.merge-buttons`, `core.german-strings` and `core.handbook` to `plugins/provenance/tests/Integration/RoleMatrixTest.php`, each for all five roles
* [x] `core.merge-buttons`: guest and normal request `admin.php?page=tags` and are refused. Admin and webmaster see the translated buttons. Read the expected string from the language file, not as a second copy
* [x] `core.german-strings`: every logged-in role gets at least one overridden string rendered on a page it can reach. Guest gets the login page
* [x] `core.handbook`: each role requests one page through both servers (nginx and `http://apache`), as `HandbookAccessTest` does
* [x] `core.zoom`: a `core.zoom` integration row checks that `photo.zoom.js` is included for logged-in roles and not served to a guest. A new `tests/e2e/roles.spec.js` in provenance runs one zoom step per role through stored sessions (guest: login page). Zoom is built by JavaScript, so it needs a browser
* [x] Feature-file depth: `CoreHiddenSubalbumCountTest` gains the admin and generic accounts where its `[BVA]` (empty vs non-empty sub-album) depends on role
* [x] Delete the single-role cases these rows replace, with the mapping in the commit message
* [x] Flip each core gate once by hand: the `discount_hidden_subalbums()` call, the `|@translate` on the merge buttons, the `handbuch.php` login check, `admin.php:27` (`ACCESS_ADMINISTRATOR` → `ACCESS_CLASSIC`). Record the results in the `TESTING.md` table. After a template revert, run `touch` on the template

## Verification

* [x] 5 core functions × 5 roles = 25 rows are green, and the zoom E2E rows are green for every role
  **Note:** Verified via `RoleMatrixTest` core rows (25 green) in the provenance integration suite, and `tests/e2e/roles.spec.js` (guest + 4 roles green)
* [x] `core.zoom` has a webmaster and an admin witness. Before this task, zoom ran only as normal
  **Note:** Verified via `[HAPPY] core.zoom as admin|webmaster` rows and `roles.spec.js` "as admin" / "as webmaster"; removing the `zoomIn()` press turned the 4 logged-in E2E rows red (checked once, restored)
* [x] Each flip turns red exactly its rows. After the revert, everything is green
  **Note:** Verified by 5 flips under the suite lock (table in `scratchpad/role-matrix/02-docs.md`): discount call 4 red, merge `|@translate` 2 red, handbook check 11 red, `admin.php:27` alone 0 red (shadowed by each admin page's own `check_status`), `admin.php:27` + `admin/tags.php:15` exactly `core.merge-buttons as normal`. Green after every revert
* [x] The provenance integration and E2E suites pass twice in a row
  **Note:** integration 323 (3 skipped, pre-existing) green twice; E2E 61 green twice; unit 273 green
