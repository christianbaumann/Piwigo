---
id: 03
dependencies:
- 02
---

# Task 03: Continuous zoom

The viewer zooms the photo in and out in ×1.25 steps between fit and 400 % of natural size, with buttons, keys and Ctrl/Cmd+wheel. A sharper file loads as the zoom grows.

## References

* `design.md#zoom-controls`
* `design.md#continuous-zoom-steps`
* `design.md#load-the-smallest-sufficient-file`
* `design.md#mobile-native-pinch`
* `design.md#code-shape`
* `themes/modus/js/photo.zoom.js` (from task 02), `themes/modus/js/photo.autosize.js`
* `plugins/persons/template/overlay.js` (`ResizeObserver` re-placement)
* `plugins/persons/tests/e2e/support/PicturePage.js` (`settle()`)

## Work

* [ ] Write the failing specs first, with new locators and actions (buttons, keys, wheel) in `PicturePage.js`
* [ ] Add the `−` and `+` buttons in `picture_content_asize.tpl`, hidden on the narrow layout and in the slideshow
* [ ] In `photo.zoom.js`: `+`/`−` multiply the factor by 1.25 and clamp it to [fit, 400 % of the `RVAS.original` size]
* [ ] Keys `+`, `−` and `0` (0 = fit). Ignore them while focus is in a text input, so the persons picker still takes typing
* [ ] Ctrl/Cmd + wheel over the photo zooms and prevents the browser's page zoom. Plain wheel still scrolls
* [ ] On each zoom change, ask `rvas_choose()` for the smallest file ≥ the new display size and switch `src` only when it changes
* [ ] Keep the scroll position centred on the same photo point across a zoom step

## Verification

* [ ] `[ST]` `+` from fit gives fit × 1.25. `−` returns to fit. `0` returns to fit from any zoom
* [ ] `[BVA]` `+` stops at 400 % of the original size. `−` stops at fit. A further press changes nothing
* [ ] `[HAPPY]` Ctrl+wheel up over the photo zooms in. Plain wheel scrolls the page and does not zoom
* [ ] `[BVA]` Zooming past the loaded file's natural size switches to a bigger file
* [ ] `[NEG]` Typing `+` into the persons name picker does not zoom
* [ ] `[HAPPY]` At a zoom > 100 % the seeded face boxes are on their regions (same tolerance as `overlay.spec.js`)
* [ ] The whole persons E2E suite passes
