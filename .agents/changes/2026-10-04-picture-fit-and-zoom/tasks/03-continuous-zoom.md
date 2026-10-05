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

* [x] Write the failing specs first, with new locators and actions (buttons, keys, wheel) in `PicturePage.js`
  * **Note:** `plugins/persons/tests/e2e/picture-zoom.spec.js`, 10 tests. New in `PicturePage.js`: `zoomIn()`, `zoomOut()`, `pressKey()`, `wheelOverPhoto()` (also reports whether the wheel event reached the window with its default prevented), `scrollPositions()`, `scrollAreaTo()`, `centredPhotoPoint()`, `visiblePhotoRect()` (`clickVisiblePhotoAt()` now reuses it). First run against the task-02 theme: 9 red (no `+`/`−` buttons; keys and Ctrl+wheel did nothing); the plain-wheel `[NEG]` passed, as it must - it records behaviour that has to survive.
* [x] Add the `−` and `+` buttons in `picture_content_asize.tpl`, hidden on the narrow layout and in the slideshow
  * **Note (deviation, carried over from task 02):** the buttons are in `template/picture_zoom_buttons.tpl`, not `picture_content_asize.tpl` - that template renders inside `#theImage`, not the toolbar. Order `− Einpassen 100 % +`, titles `Verkleinern`/`Vergrößern` (`Zoom out`/`Zoom in`). Narrow layout: in the page but hidden by `html:not(.wide) #imageToolBar .zoomStep` in `css/photo.zoom.css` (see the skins item below), so they reappear when the window widens. Slideshow: not rendered (task 02's `$page['slideshow']` check). Task 02's phone case now asserts `toHaveCount(1)` + `toBeHidden()` instead of `toHaveCount(0)`; its slideshow case also asserts no `+`/`−`.
* [x] In `photo.zoom.js`: `+`/`−` multiply the factor by 1.25 and clamp it to [fit, 400 % of the `RVAS.original` size]
  * **Note:** `mode` is now `fit` | `natural` | a scale against the original. A step that lands on fit (within floating-point `SCALE_EPSILON`) becomes `fit` again, so it re-fits on resize and keeps `usemap`. A numeric mode is floored at fit when the area grows. Upper bound `max(fit, 4)`, so a tiny photo whose fit exceeds 400 % does not shrink on `+`. After the size menu, a step starts from the photo's shown size, not the last zoom.
* [x] Keys `+`, `−` and `0` (0 = fit). Ignore them while focus is in a text input, so the persons picker still takes typing
  * **Note:** `e.key` `+`, `-`, `0`, ignored with Ctrl/Cmd/Alt (the browser's own page zoom) and in any `:input` or contenteditable. Registered only where the zoom control exists, so not in the slideshow. They also work on a narrow desktop window, where the `+`/`−` buttons are hidden - harmless, and a phone has no such keys.
* [x] Ctrl/Cmd + wheel over the photo zooms and prevents the browser's page zoom. Plain wheel still scrolls
  * **Note:** non-passive `wheel` listener on `#theImage`, acting only when the pointer is inside `#theMainImage`'s box (the persons overlay sits on top of it). Wheel travel is accumulated to `WHEEL_STEP_PX` (100, one Chromium notch) per step, so a touchpad pinch - which Chromium sends as many small Ctrl+wheel deltas - does not jump one step per event. Line/page delta modes count one step per event.
* [x] On each zoom change, ask `rvas_choose()` for the smallest file ≥ the new display size and switch `src` only when it changes
  * **Note:** already how `show()` worked since task 02 (never swaps down). One change there: the inline size is now set at once for any file except the loading GIF, not only once the current file is `complete` - otherwise a step that swaps in a bigger file kept the old layout size until it loaded, and the centring below measured the wrong box.
* [x] Keep the scroll position centred on the same photo point across a zoom step
  * **Note:** `step()` reads the photo point at the middle of what `#theImage` shows (height capped at the window), re-applies, then scrolls `#theImage` by the offset. Applies to buttons, keys and wheel alike (the wheel keeps the middle, not the point under the pointer, as the task says).

## Verification

* [x] `[ST]` `+` from fit gives fit × 1.25. `−` returns to fit. `0` returns to fit from any zoom
  * **Note:** Verified via `picture-zoom.spec.js` › `+ from fit zooms by one step and − returns to fit` and `+ and - step the zoom and 0 returns to fit` (from ×1.25², and from `100 %`).
* [x] `[BVA]` `+` stops at 400 % of the original size. `−` stops at fit. A further press changes nothing
  * **Note:** Verified via `+ stops at 400 % of the original` (presses until a press changes nothing, then asserts exactly 4 × 3540 × 2383 and more than one step taken) and `− stops at fit`. Mutant: lower clamp `Math.max(from * factor, fit)` → `from * factor` **survived** - equivalent, not a weak test: a step below fit still becomes `fit` through `to <= fit` and `apply()`'s own floor. Kept as the guard for the no-op early return.
* [x] `[HAPPY]` Ctrl+wheel up over the photo zooms in. Plain wheel scrolls the page and does not zoom
  * **Note:** Verified via `Ctrl+wheel up over the photo zooms in` (also asserts `defaultPrevented` on the event) and `a plain wheel scrolls the page and does not zoom` (`scrollY` 0 → > 0, size unchanged, default not prevented). Whether the browser's own page zoom stays off cannot be observed headless; `defaultPrevented` is the causal fact.
* [x] `[BVA]` Zooming past the loaded file's natural size switches to a bigger file
  * **Note:** Verified via `zooming past the loaded file switches to a bigger one` (anti-vacuity: one step's display width exceeds the fit file's width; then the new file is wider and covers the display).
* [x] `[NEG]` Typing `+` into the persons name picker does not zoom
  * **Note:** Verified via `typing + into the persons name picker does not zoom` (anti-vacuity: the key zooms on the same page first). Mutant: `:input` guard removed (container md5 checked) → red, reverted.
* [x] `[HAPPY]` At a zoom > 100 % the seeded face boxes are on their regions (same tolerance as `overlay.spec.js`)
  * **Note:** Verified via `the face boxes stay on their regions above 100 %` (`overlay` scenario, `100 %` then `+`, 2 px tolerance).
* [x] The whole persons E2E suite passes
  * **Note:** final run after `/verify` and the review fixes: 87 passed; typetags E2E 36 passed. First run: 65 passed; `picture-zoom.spec.js` re-run alone: green again. The typetags E2E suite (also drives the picture page): 36 passed. Centring is covered by the extra case `a step keeps the same photo point in the middle`; mutant with the scroll correction zeroed → red, reverted.
* [x] The `+`/`−` buttons sit in the toolbar with the others
  * **Note:** ad-hoc probe at 1920×1080: `− Einpassen 100 % +` in one row at top 80 px, 28 px tall, German titles.
* [x] The zoom control works and reads right in every modus skin (task 02's open manual item, automated in `/verify` 2026-10-05)
  * **Note:** a toolbar-only probe of all 18 skins (`?skin=`, no config change, no photo in frame; screenshots in `.agent-tests/2026-10-05-zoom-skins/`) found two defects:
    * **Task 02 bug:** `hf_base.css` loads only for skins with a stylesheet of their own (`header.tpl:17`). In the 7 others (`clear`, `dark`, `dark_lagoon`, `dark_radish`, `dark_sky`, `debug`, `grey`), none of the fork's CSS applied: `#theImage` did not scroll at 100 %, `+`/`−` showed on a phone, and `Einpassen` overflowed its 42 px button onto `100 %`. Fix: the CSS moved to a new `css/photo.zoom.css`, loaded by `picture_content_asize.tpl` for every skin. `hf_base.css` is identical to `master` again.
    * **Contrast:** 5 skins colour their icons separately from links, so the labels were e.g. black on blue in `glacier`, and 5 others coloured the phone-menu labels differently. Fix: the label is a `<span class="pwg-icon pwg-button-text zoomLabel">`, so each skin's icon colour applies in the toolbar and its label colour in the menu. `photo.zoom.css` undoes the icon size and shows the text; the menu indent went from 34 to 29 px (`.pwg-button-text` brings the 5 px gap).
    * Regression: `picture-zoom.spec.js` › `the zoom control in every skin`, one test per skin plus an anti-vacuity count (≥ 10; 18 measured 2026-10-05). Each checks: the labels have the icon colour, sit in one row, stay inside their buttons; `#theImage` really scrolls at 100 % and the page does not get wider; `+`/`−` hidden on a phone; the menu labels have the label colour. Red before each fix (first 5 skins, then 12 after tightening the overlap and scroll checks, then 5 for the menu colour), green after. A first version passed `dark` falsely: button boxes do not overlap (only their text overflows them), and `scrollWidth > clientWidth` also holds without `overflow:auto`. Both checks were tightened.
    * Remaining judgment, not automated: whether the labels look good next to the icons. I looked at screenshots of `dark`, `glacier`, `newspaper` and `quartz` (desktop and phone menu) and found nothing wrong.

## Critical review (subagent, 2026-10-05)

* [x] Stale docs: decision 0032 and `design.md` named `hf_base.css`. Both now name `photo.zoom.css`; 0032 records why the CSS must not go back.
* [x] Flake risk: the plain-wheel case read `scrollY` once, but `mouse.wheel()` does not wait for the scroll. It now uses `expect.poll`.
* [x] Centring with the page scrolled past the area's top took the middle from the area's own top. Fixed: the middle of the area's client box cut to the window, in `photo.zoom.js` and `PicturePage.centredPhotoPoint()` alike. Regression: `a step keeps the same photo point in the middle with the page scrolled`. Mutant with the old formula: survived at a 200 px page scroll (a 40 px error is 0.005 of drift, under the tolerance). The test now scrolls 600 px and the mutant is killed (0.0325), container md5 checked. The step goes through the key there, because clicking the button scrolls the toolbar back into view.
* [x] `+` while the size menu's file loads at dpr > 1 (photo hidden, width 0) jumped to fit with NaN scroll. `step()` now returns early. Not covered by a test: the window is a file load and cannot be held open reliably. Checked by reading the code.
* [x] `−` on a small photo at 100 % (below fit) enlarged it to fit. `−` now never enlarges. Regression: `− on a small photo at 100 % changes nothing` (red before, green after).
* [x] A numeric zoom the area outgrew stayed numeric, so it came back when the window shrank, and `usemap` was dropped although the photo was fitted. `apply()` now turns it into fit. Regression: `a zoom the area outgrew stays fitted when the window shrinks back` (red before, green after).
* [x] **Accepted, not fixed:** core's ←/→ go to the previous/next photo while zoomed, because the photo scrolls inside `#theImage`, not the page. The design keeps prev/next on the arrow keys deliberately (*Click navigation off when zoomed in* → trade-offs); panning goes through the scrollbars, the wheel and, after task 04, a drag.
* [x] **Accepted, not fixed:** leftover Ctrl+wheel travel (< 100 px) carries over to the next gesture, so at most one step comes one notch early. A decay timer would add a second tuning constant for no visible gain.

## Approval

* [x] Task 03 approved 2026-10-05: every work and verification item is done and verified by a named spec, a mutant or a recorded code reading. The two items not fixed are recorded above with their reasons. Nothing is left for manual testing.
