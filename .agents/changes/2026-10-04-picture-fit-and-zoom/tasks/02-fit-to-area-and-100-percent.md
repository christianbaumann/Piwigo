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

* [ ] Add an optional source choice to `FixtureBuilder::createTestImage()` and `--source=small` to `seed.php` (copies the smallest gallery image, asserts its size is below the area of the test viewport)
* [ ] Write the failing specs first (see Verification), with new locators in `PicturePage.js`
* [ ] Change `rvas_choose()` in `photo.autosize.js` so it picks the smallest derivative ≥ a target display size, or the largest one if none is big enough
* [ ] Add `themes/modus/js/photo.zoom.js` with the zoom state (`fit` | factor), the `Einpassen` and `100 %` buttons, and the click guard (no navigation when zoom > fit). Load it from `picture_content_asize.tpl` with `combine_script`
* [ ] Emit `RVAS.original` (`w`, `h`, and `url` only when `U_ORIGINAL` is set) and the two buttons from `picture_content_asize.tpl`
* [ ] Fit = the largest size with kept aspect ratio inside `#theImage`'s width and the viewport height below its top. Set `width`/`height` on `#theMainImage` and remove `usemap` when the size differs from the file's natural size
* [ ] `100 %` = `piwigo_images` size via the original, or the largest derivative when the original is not permitted
* [ ] `#theImage` gets `overflow:auto` and a height limit in `hf_base.css`, so a 100 % photo scrolls inside it
* [ ] The size menu (`changeImgSrc()`) still shows the chosen file at natural size and leaves fit mode
* [ ] Fit on the narrow layout too. Slideshow: fit, no buttons
* [ ] Write `docs/agents/decisions/0032-…` recording the fork-local `modus` edit and its upstream merge risk. Update `CLAUDE.md`'s fork-local list if needed
* [ ] Update task 01's `[ERR]` specs whose recorded behaviour this task deliberately replaces (dpr-1 natural size, `usemap`, and the three dpr-1 click-zone specs in `picture-display.spec.js`, whose `hasImageMap() === true` branch guard fails once fit removes the map), each in its own cycle with the reason
* [ ] At `100 %` the photo can be shown at its file's natural size, where the design keeps `usemap` - the `<area>` links then navigate past the click guard in the `img` click handler. Make the `[NEG]` "click at 100 % does not navigate" case hold anyway (remove the map whenever zoom > fit, or guard the areas)

## Verification

* [ ] `[HAPPY]` On 1920×1080 a large photo touches the area on its limiting side (width or height = available, ±1 px)
* [ ] `[BVA]` The small fixture is upscaled to fill. The large fixture loads the smallest derivative ≥ the display size, not the largest that fits
* [ ] `[ST]` `100 %` shows the photo at its `piwigo_images` size, and `Einpassen` returns to fit
* [ ] `[NEG]` A click on the photo at 100 % does not navigate. A click in fit still navigates
* [ ] `[ST]` The next photo opens in fit
* [ ] At 390×844 the photo fills the width and no `+`/`−` buttons are shown
* [ ] The size menu still makes the photo narrower (`overlay.spec.js:94` unchanged and green)
* [ ] The whole persons E2E suite passes, including the overlay placement specs
* [ ] Every new spec went red before the theme change and green after it
