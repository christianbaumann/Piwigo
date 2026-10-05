---
id: 01
dependencies: []
---

# Task 01: Characterize the picture page as it is

The behaviour that tasks 02–04 change, or must keep, is covered by `[ERR]` specs that pass on the current theme. They are committed on their own, before any theme change (`.claude/rules/testing.md#cover-the-ground-before-you-move-it`).

## References

* `design.md#current-state-measured-2026-10-04`
* `design.md#click-navigation-off-when-zoomed-in`
* `design.md#image-map-removed-when-scaled`
* `design.md#the-size-menu-keeps-its-meaning`
* `themes/modus/js/photo.autosize.js` (click handler, `rvas_choose()`)
* `themes/modus/template/picture_content_asize.tpl` (`<map>`)
* `plugins/persons/tests/e2e/overlay.spec.js` (already covers: overlay placement, smallest derivative via `changeImgSrc()`, one click-to-navigate case)
* `plugins/persons/tests/e2e/support/PicturePage.js`, `seed.php`, `seed.js`
* `.claude/rules/e2e-tests.md`, `.claude/rules/test-design.md`, `.claude/rules/plugin-test-suites.md`

## Work

* [x] Check which of the following the existing persons specs already cover, and do not duplicate those
  * **Note:** `overlay.spec.js` covers overlay placement, "smallest derivative → narrower" (calls `changeImgSrc()` directly, never asserts natural size), and one upper-middle click that only asserts *some* navigation at dpr 1 and 2. None of the four cases below was covered; listed in the new spec's header comment.
* [x] Add a spec file (for example `plugins/persons/tests/e2e/picture-display.spec.js`) with `[ERR]` cases, each commented with the behaviour it records and that no requirement confirms it:
  * the click zones on the photo: left part → previous, upper middle → thumbnails, right part → next (seed a second photo in the album if prev/next needs one)
  * at dpr 1 the photo carries a `usemap` and is shown at the natural size of the chosen derivative
  * choosing a size in the size menu shows that file at its natural size
  * the slideshow (`?slideshow=`) shows the photo
  * **Note:** `plugins/persons/tests/e2e/picture-display.spec.js`, 9 tests. Click zones run at dpr 1 (navigates through the `<area>` map) **and** dpr 2 (map removed, JS click handler), with the branch asserted via `hasImageMap()`. The click points sit inside both mechanisms' zones, so they carry over to task 02, but the three dpr-1 zone specs assert `hasImageMap() === true` as their branch guard and go red once fit mode removes the map; task 02 lists them for update. Destinations are asserted exactly (previous/next photo, album page), not just "URL changed". The size menu is driven through the real menu UI, not `changeImgSrc()`.
* [x] Put all new locators and interactions in `PicturePage.js` (no locator in a spec)
  * **Note:** added `sizeMenuButton`, `sizeMenu`, `slideshowButton`, `slideshowImage`, `settleImage()` (overlay-independent settle that also waits for `complete`), `imageDisplay()`, `derivatives()`, `clickImageAt()`, `chooseSize()`, `startSlideshow()`. `grep -E "locator\(|querySelector|getBy"` over the spec finds nothing.
* [x] Extend `seed.php`/`seed.js` only where a case needs it, with `--restore` still removing everything
  * **Note:** new `--scenario=neighbours` (untagged photo plus a previous and a next one; upload dates forced and the resulting order asserted, since `order_by` is `date_available DESC` and all three rows are inserted in the same second). Prints `previous_path`/`next_path`. Documented in `.claude/rules/plugin-test-suites.md`.
* [x] Prove that each new spec can fail by breaking the behaviour it watches, once by hand, and revert

## Verification

* [x] The new specs pass on the unchanged theme, twice in a row, with `--workers=1`
  * **Note:** `npx playwright test picture-display --workers=1` run twice: 11 passed (9 + 2 setup) both times.
* [x] Critical review (subagent) of the commit, findings fixed
  * **Note:** fixed: `settleImage()` accepted the loading GIF as a loaded photo (now rejected; slideshow spec also asserts the file is one of the photo's derivatives, proven red against a slideshow stuck on the GIF); `seed_neighbours()` leaked its fixtures on a failed order check (now destroyed before `fail()`); the order check used a hand-typed `ORDER BY` (now reads `order_by_inside_category` from `piwigo_config`); the task-02 claim above was wrong. Kept: `#derivativeChecked${type} + a` - every alternative couples to core's markup shape too, and the page object says why. Spec re-run after the fixes: 11 passed.
* [x] Each new spec went red once against a deliberate break of its behaviour
  * **Note:** temporary theme edits, container md5 checked before running: map `previous`/`next` hrefs swapped and `U_UP` → next (3 dpr-1 zone specs red, wrong URL); JS handler prev/next swapped and up → `#linkNext` (3 dpr-2 zone specs red, wrong URL); `rvas_choose()` dpr-1 width `best.w-10` (natural-size spec red, 1214 vs 1224); `changeImgSrc` wrapper forcing `width=100` (size-menu spec red, 100 vs 240); photo hidden in slideshow (slideshow spec red, timeout). Reverted with `git checkout -- themes/`, md5 re-checked.
* [x] The whole persons E2E suite passes (command in `.claude/rules/plugin-test-suites.md`)
  * **Note:** 44 passed. A first run had one `editor.spec.js` failure caused by a stale compiled `picture.tpl` from the `fix/persons-live-row` branch (prefilter source changed with the branch switch, compile id did not); passed after clearing `_data/templates_c/`.
* [x] `seed.php --restore` leaves no album, photo or file behind
  * **Note:** after the runs: 0 `piwigo_images` rows with `persons-test` paths, 0 `Persons E2E` albums, `upload/persons-test/` empty, no `snapshot.json`.
* [x] No file under `themes/` changed in this commit
  * **Note:** `git status --short themes/` empty after the revert.
