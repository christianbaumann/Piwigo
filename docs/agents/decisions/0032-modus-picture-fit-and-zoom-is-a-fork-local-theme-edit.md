# 0032 — Picture fit and zoom is a fork-local edit of the modus theme

Date: 2026-10-05
Status: accepted
Supersedes nothing. Design:
[`.agents/changes/2026-10-04-picture-fit-and-zoom/design.md`](../../../.agents/changes/2026-10-04-picture-fit-and-zoom/design.md).

## Context

On the picture page the `modus` theme showed one derivative at its natural pixel size
(`rvas_choose()` in `themes/modus/js/photo.autosize.js`). 103 of the gallery's 106 photos are
smaller than the area, so most photos sat in a field of whitespace. The fix needs a different
file choice, an upscaled display size and a zoom control, and every piece of that lives in the
theme's picture-page code.

`themes/modus/` is tracked in this fork. Until now the fork carried no change to it.

## Decision

Edit `themes/modus/` in place:

- `js/photo.autosize.js`: `rvas_choose(display)` returns the smallest file covering a display
  size; the old sizing and `<area>`-map handling are gone from it
- `js/photo.zoom.js` (new): fit and `100 %`, the click guard, the size-menu hand-off
- `template/picture_content_asize.tpl`: `RVAS.original`, loads `photo.zoom.js`
- `template/picture_zoom_buttons.tpl` (new) and `themeconf.inc.php`: the toolbar buttons
  through core's `add_picture_button()`, and `RVAS_ORIGINAL`
- `css/hf_base.css`: `#theImage` scrolls, `#theMainImage` is a block
- `language/en_UK` and `language/de_DE`: three strings

`themes/default/` stays untouched.

### What was rejected

- A plugin that replaces `rvas_choose()` at runtime: patches a global function from outside,
  which breaks silently when the theme renames it.
- A CSS-only override in `local/css/`: cannot choose a file or zoom.

## Consequences

- An upstream merge that touches any of the files above conflicts. `photo.autosize.js` and
  `picture_content_asize.tpl` are the likely ones; resolve them by keeping the fork's
  `rvas_choose(display)` signature, which `photo.zoom.js` calls.
- The behaviour is covered by `plugins/persons/tests/e2e/picture-fit.spec.js` and
  `picture-display.spec.js`; the theme has no suite of its own.
