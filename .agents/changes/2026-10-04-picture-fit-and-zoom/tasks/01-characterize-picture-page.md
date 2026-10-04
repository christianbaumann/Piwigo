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

* [ ] Check which of the following the existing persons specs already cover, and do not duplicate those
* [ ] Add a spec file (for example `plugins/persons/tests/e2e/picture-display.spec.js`) with `[ERR]` cases, each commented with the behaviour it records and that no requirement confirms it:
  * the click zones on the photo: left part → previous, upper middle → thumbnails, right part → next (seed a second photo in the album if prev/next needs one)
  * at dpr 1 the photo carries a `usemap` and is shown at the natural size of the chosen derivative
  * choosing a size in the size menu shows that file at its natural size
  * the slideshow (`?slideshow=`) shows the photo
* [ ] Put all new locators and interactions in `PicturePage.js` (no locator in a spec)
* [ ] Extend `seed.php`/`seed.js` only where a case needs it, with `--restore` still removing everything
* [ ] Prove that each new spec can fail by breaking the behaviour it watches, once by hand, and revert

## Verification

* [ ] The new specs pass on the unchanged theme, twice in a row, with `--workers=1`
* [ ] Each new spec went red once against a deliberate break of its behaviour
* [ ] The whole persons E2E suite passes (command in `.claude/rules/plugin-test-suites.md`)
* [ ] `seed.php --restore` leaves no album, photo or file behind
* [ ] No file under `themes/` changed in this commit
