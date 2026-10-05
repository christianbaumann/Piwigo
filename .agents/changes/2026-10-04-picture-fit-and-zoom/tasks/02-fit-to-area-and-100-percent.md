---
id: 02
dependencies:
- 01
---

# Task 02: Fit to area, with `Einpassen` and `100 %`

Every photo opens fitted to the available area (small scans upscaled, the smallest sufficient file loaded), and the viewer can switch between fit and 1:1.

## References

* `design.md#target-behaviour`
* `design.md#upscale-small-originals-to-fill-the-area`
* `design.md#layout-zoom-not-transform-zoom`
* `design.md#load-the-smallest-sufficient-file`
* `design.md#100--means-11-against-the-original`
* `design.md#click-navigation-off-when-zoomed-in`
* `design.md#image-map-removed-when-scaled`
* `design.md#the-size-menu-keeps-its-meaning`
* `design.md#nothing-is-remembered`
* `design.md#mobile-native-pinch`
* `design.md#slideshow-fit-only`
* `design.md#edit-the-modus-theme-directly`
* `design.md#a-small-fixture-photo`
* `design.md#code-shape`
* `themes/modus/js/photo.autosize.js`, `themes/modus/template/picture_content_asize.tpl`, `themes/modus/themeconf.inc.php` (`modus_picture_content()`), `themes/modus/css/hf_base.css:877`
* `plugins/persons/template/overlay.js`
* `plugins/persons/tests/Support/FixtureBuilder.php` (`createTestImage()`), `plugins/persons/tests/e2e/support/seed.php`
* `.claude/rules/piwigo-dev-environment.md` (clear `_data/templates_c/` and `_data/combined/` after template and asset changes)

## Work

* [x] Add an optional source choice to `FixtureBuilder::createTestImage()` and `--source=small` to `seed.php` (copies the smallest gallery image, asserts its size is below the area of the test viewport)
  * **Note:** `createTestImage(FixtureBuilder::SOURCE_SMALL)` orders by `width * height`; picks id 91 (324×497) today. Every scenario now prints `width`/`height`; the size assertion against the viewport's area sits in the spec (`seed.php` does not know the viewport). Documented in `.claude/rules/plugin-test-suites.md`.
* [x] Write the failing specs first (see Verification), with new locators in `PicturePage.js`
  * **Note:** `plugins/persons/tests/e2e/picture-fit.spec.js`, 9 tests. New in `PicturePage.js`: `zoomFitButton`, `zoomNaturalButton`, `zoomInButton`, `zoomOutButton` (the last two for task 03), `fitArea()`, `zoomToFit()`, `zoomToNatural()`, `clickVisiblePhotoAt()`.
* [x] Change `rvas_choose()` in `photo.autosize.js` so it picks the smallest derivative ≥ a target display size, or the largest one if none is big enough
  * **Note:** `rvas_choose(display)` takes CSS px, multiplies by the pixel ratio, and searches `rvas_files()` (derivatives, plus the original when `RVAS.original.url` is set and larger). `rvas_get_scaled_size()` and the old sizing/`usemap` code are removed; the resize handler there now only toggles `wide`.
* [x] Add `themes/modus/js/photo.zoom.js` with the zoom state (`fit` | factor), the `Einpassen` and `100 %` buttons, and the click guard (no navigation when zoom > fit). Load it from `picture_content_asize.tpl` with `combine_script`
  * **Note:** state is `fit` | `natural` for now (task 03 adds the factor). The click guard is a capture-phase listener on `#theImage` that stops a click on `#theMainImage` while zoomed past fit, so `photo.autosize.js`'s handler stays unchanged. The `load` handler moved here from `rvas_choose()`. Never swaps to a smaller file than the one loaded.
* [x] Emit `RVAS.original` (`w`, `h`, and `url` only when `U_ORIGINAL` is set) and the two buttons from `picture_content_asize.tpl`
  * **Note (deviation):** `RVAS.original` is emitted from `picture_content_asize.tpl` as designed, but from `src_image->get_size()` (display orientation) rather than `piwigo_images.width/height`, and `url` is also withheld when the photo has a `rotation` - the original file is not shown upright then. The buttons are **not** in `picture_content_asize.tpl`: that template renders inside `#theImage`, not the toolbar. They are a new `template/picture_zoom_buttons.tpl`, added to `#imageToolBar` with core's `add_picture_button()` from `modus_picture_content()` - still inside `themes/modus/`, `themes/default/` untouched. Labels `Fit` / `Einpassen` in `themes/modus/language/{en_UK,de_DE}/theme.lang.php`.
* [x] Fit = the largest size with kept aspect ratio inside `#theImage`'s width and the viewport height below its top. Set `width`/`height` on `#theMainImage` and remove `usemap` when the size differs from the file's natural size
  * **Note:** area comes from the existing `rvas_get_available_size()` (still writes the `phavsz` cookie the server uses). `usemap` is kept only in fit, when the display size equals a derivative's size exactly.
* [x] `100 %` = `piwigo_images` size via the original, or the largest derivative when the original is not permitted
  * **Note (open question):** for a photo smaller than the area, 100 % is *smaller* than fit. Implemented as 1:1 anyway (the button means 1:1), so the design's "fit is the lower bound" holds for `−` (task 03) but not for `100 %`. Such a photo at 100 % is not "zoomed past fit", so a click on it still navigates.
* [x] `#theImage` gets `overflow:auto` and a height limit in `hf_base.css`, so a 100 % photo scrolls inside it
  * **Note (deviation):** `overflow:auto` is in `hf_base.css`; the height limit is set by `photo.zoom.js` (`max-height` = area height) and only while zoomed past fit. The area's top is not known to CSS, and a permanent limit would put the photo description (`.imageComment`, inside `#theImage`) behind an inner scrollbar in fit mode. `#theMainImage` became `display:block; margin:0 auto` so no line box adds height and an oversized photo starts at the left edge instead of overflowing both sides.
* [x] The size menu (`changeImgSrc()`) still shows the chosen file at natural size and leaves fit mode
  * **Note:** `photo.zoom.js` wraps it once more: clears the inline size and the height limit, records the chosen file, drops the click guard. `RVAS.disable` (set by the existing wrapper) stops resize refits until `Einpassen`/`100 %`.
* [x] Fit on the narrow layout too. Slideshow: fit, no buttons
  * **Note:** no buttons in the slideshow because `slideshow.tpl` never renders `PLUGIN_PICTURE_BUTTONS`, and `modus_picture_content()` skips adding them when `$page['slideshow']`. Below 600 px the toolbar's action buttons sit in modus' collapsed dropdown, so `Einpassen`/`100 %` are there on a phone.
* [x] Write `docs/agents/decisions/0032-…` recording the fork-local `modus` edit and its upstream merge risk. Update `CLAUDE.md`'s fork-local list if needed
  * **Note:** `docs/agents/decisions/0032-modus-picture-fit-and-zoom-is-a-fork-local-theme-edit.md`; one line added to `CLAUDE.md` (77 lines). Stale `rvas_choose()` references fixed in `overlay.js`, `overlay.css`, `overlay.spec.js` and `PicturePage.js` comments.
* [x] Update task 01's `[ERR]` specs whose recorded behaviour this task deliberately replaces (dpr-1 natural size, `usemap`, and the three dpr-1 click-zone specs in `picture-display.spec.js`, whose `hasImageMap() === true` branch guard fails once fit removes the map), each in its own cycle with the reason
  * **Note:** after the theme change exactly these 4 went red, nothing else. The dpr-1 natural-size/`usemap` case is deleted (successors: the fit and file-choice cases in `picture-fit.spec.js`); the dpr-1 click zones now expect no map, with the reason in the file header. 10 passed after the update.
* [x] At `100 %` the photo can be shown at its file's natural size, where the design keeps `usemap` - the `<area>` links then navigate past the click guard in the `img` click handler. Make the `[NEG]` "click at 100 % does not navigate" case hold anyway (remove the map whenever zoom > fit, or guard the areas)
  * **Note:** the map is removed in every mode except fit; the `[NEG]` spec asserts `hasImageMap() === false` at 100 % as its anti-vacuity guard.

## Verification

* [x] `[HAPPY]` On 1920×1080 a large photo touches the area on its limiting side (width or height = available, ±1 px)
  * **Note:** Verified via `picture-fit.spec.js` › `a large photo touches the area on its limiting side` (also asserts aspect ratio). Live: 1392×937 in a 1392×960 area, 1656 px file.
* [x] `[BVA]` The small fixture is upscaled to fill. The large fixture loads the smallest derivative ≥ the display size, not the largest that fits
  * **Note:** Verified via `a small photo is upscaled to fill the area`, `a large photo loads the smallest file that covers the display size` (asserts a smaller derivative exists below the display size), and `the loaded file covers the display size at pixel ratio 2`.
* [x] `[ST]` `100 %` shows the photo at its `piwigo_images` size, and `Einpassen` returns to fit
  * **Note:** Verified via `100 % shows the original size and Einpassen returns to fit` (3540×2383, original file loaded).
* [x] `[NEG]` A click on the photo at 100 % does not navigate. A click in fit still navigates
  * **Note:** Verified via `a click at 100 % does not navigate, a click in fit does`. Mutation `zoomedIn &&` → `false &&` in the guard (container md5 checked) turned it red, reverted.
* [x] `[ST]` The next photo opens in fit
  * **Note:** Verified via `the next photo opens in fit`.
* [x] At 390×844 the photo fills the width and no `+`/`−` buttons are shown
  * **Note:** Verified via `the photo fills the width and there is no + or -` (asserts `#zoomFit` present, `#zoomIn`/`#zoomOut` absent - vacuous until task 03 adds them, by design).
* [x] The size menu still makes the photo narrower (`overlay.spec.js:94` unchanged and green)
  * **Note:** `overlay.spec.js` untouched apart from a comment; green in the full run, as is `picture-display.spec.js` › `a size chosen in the size menu is shown at its file size`.
* [x] The whole persons E2E suite passes, including the overlay placement specs
  * **Note:** 52 passed. First run had one failure: `editor.spec.js` › `a drawn box survives a reload` compared viewport coordinates across a reload; the photo now fills the window, opening the picker scrolled the page 105 px (probed: `scrollY` 105 before, 0 after, photo size equal). The spec now measures the box against the photo's box. Persons unit (114) and integration (105, 1 pre-existing skip) also pass - `FixtureBuilder` is shared.
* [x] Every new spec went red before the theme change and green after it
  * **Note:** first run against the old theme: 8 red; the 9th (`smallest file`) passed vacuously because its oracle was relative to the 1:1 file, so it was anchored to fit. Re-run with the theme changes stashed (container md5 checked): all 9 red. With them: 9 green, twice.
* [x] The zoom buttons sit in the toolbar, unclipped, and line up in the phone's action menu
  * **Note:** probed: on desktop both buttons sit in the toolbar row at the icon buttons' top edge, labels unclipped (28 px tall against the icons' 23 px; the text reads level with the icons in an element screenshot of the toolbar alone). In the phone menu the text-only entries started 26 px left of the other labels; fixed in `hf_base.css` (indent to the icon-plus-gap column). Regression: `picture-fit.spec.js` › `their labels line up with the other entries in the action menu` (red before the fix, green after).
* [ ] (manual testing required) The buttons look right in the other modus skins (dark ones included). Not automatable: a visual judgment, and switching the skin changes the install's theme config. No screenshot of the real gallery may be taken (`.claude/rules/handbook.md`).

## Critical review (subagent, 2026-10-05)

* [x] `RVAS.original.url` could be a PDF or video behind a representative - now also requires `src_image->is_original()`. No fixture for a PDF/video, so this is checked by reading the code only.
* [x] Height-only resizes on the narrow layout (a mobile address bar) re-fitted the photo - now only a new width re-fits there. `picture-fit.spec.js` › `a change of the window height alone leaves the photo as it is` (red before, green after).
* [x] The area narrowing with no window resize (a page scrollbar appearing after load) left the fit stale - `ResizeObserver` on `#theImage` re-fits on a width change. `picture-fit.spec.js` › `the photo follows its area when only the area narrows` (red before, green after).
* [x] Pinch zoom no longer counted in the file choice - the display size is multiplied by `available.zoom` again, as the old code did. Not covered by a test: Playwright cannot pinch-zoom.
* [x] Dead zoom buttons on an embedded-PDF page - removed when there is no `#theMainImage`. Checked by reading the code only (no PDF fixture).
* [x] An exception when neither `RVAS.original` nor any derivative has a size would stop every later footer script - `apply()` returns early.
* [x] Fixture: rows without a size are no longer candidates for `small`; `seed.php` no longer repeats the source list (`--source=bogus` → `--source: unknown fixture source 'bogus'`, exit 1, nothing created).
* [x] **Accepted, not fixed:** the server still picks "largest derivative that fits" for the initial `src` and `U_PREFETCH`, while the client picks "smallest that covers the fit size". A photo larger than the area (3 of 106 today) therefore downloads `xl` and then `xxl`, and the prefetch fetches a file the next page discards. Fixing it means repeating the fit computation in `modus_picture_content()`. Worth doing if the gallery gets larger scans.
* [x] **Accepted, not fixed:** the slideshow spec could flake: a one-photo album with repeat on reloads every 4 s. This is the existing pattern from `picture-display.spec.js`; it has not flaked in 5 runs.
* [x] Re-run after the fixes: persons E2E 55 passed; persons integration 105 (1 pre-existing skip).

## Approval

* [x] Task 02 approved 2026-10-05: every work and verification item is done or recorded as accepted; one visual check (other skins) remains manual.
