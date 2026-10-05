---
id: 01
dependencies: []
---

# Task 01: Plugin skeleton, webmaster gate and edit mode

A webmaster sees `Bearbeiten` on the picture page and can open and cancel an edit mode with zoom and the persons overlay off; nobody else sees it. Nothing is written yet.

## References

* `design.md#target-behaviour`
* `design.md#code-shape`
* `design.md#a-new-fork-local-plugin`
* `design.md#on-the-public-picture-page-webmaster-only`
* `plugins/persons/main.inc.php`, `plugins/persons/maintain.class.php`, `plugins/persons/tests/` (layout, `Support/TestUsers.php`, `create-test-users.php`, `phpunit.xml`, `playwright.config.js`, `auth.setup.js`)
* `include/template.class.php:1202` (`add_picture_button()`)
* `themes/modus/js/photo.zoom.js`, `plugins/persons/template/overlay.js`
* `.claude/rules/testing.md#test-accounts`, `.claude/rules/plugin-test-suites.md`, `.claude/rules/piwigo-dev-environment.md` (`.gitignore` re-include)

## Work

* [x] Create `plugins/photoedit/` (`main.inc.php` header, `maintain.class.php`, `index.php` guards, `language/de_DE`, `language/en_UK`) and add `!/plugins/photoedit` to `.gitignore`
* [x] Set up the suites like persons: `composer.json` (PHPUnit), `package.json` (Playwright, shared browser cache), `phpunit.xml` (unit + integration, fail on warnings/risky), `.gitignore` for `vendor/`, `node_modules/`, run output
  **Note:** `package-lock.json` is persons' lock, so Playwright stays at 1.62.1. A fresh `npm install` resolved 1.63.0, whose Chromium is not in the shared browser cache.
* [x] `tests/Support/create-test-users.php` creating `photoedit_webmaster`, `photoedit_admin` (administrator, not webmaster) and `photoedit_normal`, writing `local/config/photoedit-test.env`; asserts each role took effect
* [x] `loc_end_picture` handler: only for `is_webmaster()`, add the `Bearbeiten` button and load `template/editor.js` / `editor.css`
  **Note:** Deviation from the design: the button reads `Drehen/Zuschneiden`, not `Bearbeiten`. Core's own edit button (pencil, `cmdEditPhoto`) is already labelled "Bearbeiten" for a webmaster, so two buttons would have had the same name. Decided by the user on 2026-10-05.
* [x] `editor.js`: edit mode on `#theImage` — disables zoom/pan and click navigation, hides the persons overlay, shows `↺ ↻ Speichern Abbrechen` (turn and save are inert placeholders until task 02); `Abbrechen` restores the page as it was
  **Note:** The controls replace the other buttons in `#imageToolBar .actionButtons` while edit mode is on (server-rendered with the button, CSS-switched by `html.photoedit-active`). The theme's handlers are held back by capture-phase listeners on `window`, not unbound: clicks on the photo or its image map, zoom keys, core's navigation keys (←/→, Ctrl+Home/End/↑) and Ctrl+wheel. Entering refits a zoomed photo by clicking `#zoomFit` and first ends persons tagging if it is on. `Escape` also leaves. On a narrow screen the collapsed action menu stays open while edit mode is on.
* [x] Page object `tests/e2e/support/PicturePage.js`; seed script for a throwaway album with a copied photo (reuse the persons pattern, never a real scan)
* [x] Write `docs/agents/decisions/0034-photo-edits-change-the-file.md` (file not database, new plugin, webmaster only)
* [x] Update `CLAUDE.md` (fork-local plugin list) and `.claude/rules/plugin-test-suites.md` (photoedit commands, accounts)
  **Note:** Also updated the re-include list in `.claude/rules/piwigo-dev-environment.md`.

## Verification

* [x] `[HAPPY]` As `photoedit_webmaster` the button is shown; it opens edit mode, and `Abbrechen` returns the photo to fit with the persons overlay back
  **Note:** Verified by `tests/e2e/edit-mode.spec.js` (`[HAPPY] edit mode opens…`, `[ST] edit mode refits a zoomed photo`) and `PicturePageButtonTest::testTheWebmasterGetsTheButton`.
* [x] `[NEG]` As `photoedit_admin` and as `photoedit_normal` there is no button (page source and DOM), and as a guest neither
  **Note:** Verified by `PicturePageButtonTest::testNobodyElseGetsTheButton` (source) and `button-visibility.spec.js` (DOM). Mutating `is_webmaster()` to `is_admin()` turned the admin case red in both.
* [x] `[ST]` In edit mode a click on the photo does not navigate and `+`/`−`/Ctrl+wheel do not zoom
  **Note:** Verified by `edit-mode.spec.js` (`[ST] in edit mode the theme gets no click…`). It counts the clicks and keys that reach the theme's targets. A control step outside edit mode then zooms and navigates by click, which proves both counters work. Removing the click blocker or the navigation-key blocker turned it red. The image-map case is not exercised: at the test viewport the theme sets no `usemap`.
* [x] `[ST]` (added in review) Entering edit mode ends persons tagging; on a 400px screen the controls stay reachable in the collapsed menu
  **Note:** Verified by `edit-mode.spec.js` (`[ST] entering edit mode ends persons tagging first`, `[ST] on a narrow screen…`). Each turned red when its fix was mutated away.
* [x] The persons E2E suite (`picture-*.spec.js`, `overlay.spec.js`) still passes with photoedit active
  **Note:** 82 passed (2026-10-05). Those specs run as `persons_normal`, so the button is not on their pages. The overlap only shows for a webmaster, and `edit-mode.spec.js` covers that.
* [x] All three photoedit suites run with the one command each, twice and in reverse order
  **Note:** After the review fixes: unit 3 tests and integration 4 tests, green twice with `--order-by=reverse`; E2E 13 tests green twice (2026-10-05). Before the fixes, the two E2E spec files also passed run separately in swapped order.

## Review

A subagent reviewed the code on 2026-10-05. Its findings were fixed:

* image-map clicks and core's navigation keys were not blocked
* persons tagging stayed active under edit mode
* the collapsed menu closed on narrow screens
* the overlay assertions were vacuous
* the click counter had no control step
* seed did not check that the plugin is active
* the unit guard used constants no production code read (moved into the test, `include/functions.inc.php` dropped until task 02 needs it)
* derivatives were left behind by the fixtures
* Playwright was not pinned exactly
* `docs/agents/TESTING.md` and the design were stale

**Status: approved 2026-10-05.**
