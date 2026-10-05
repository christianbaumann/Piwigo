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
- `js/photo.zoom.js` (new): fit and `100 %`, the click guard, the size-menu hand-off; `+`/`−`
  steps, the keys `+`, `-` and `0`, and Ctrl/Cmd + wheel (task 03)
- `template/picture_content_asize.tpl`: `RVAS.original`, loads `photo.zoom.js` and `photo.zoom.css`
- `template/picture_zoom_buttons.tpl` (new) and `themeconf.inc.php`: the toolbar buttons
  through core's `add_picture_button()`, and `RVAS_ORIGINAL`
- `css/photo.zoom.css` (new): `#theImage` scrolls, `#theMainImage` is a block, the buttons'
  size and colours, `+`/`−` hidden on the narrow layout
- `language/en_UK` and `language/de_DE`: five strings

`themes/default/` stays untouched.

### What was rejected

- A plugin that replaces `rvas_choose()` at runtime: patches a global function from outside,
  which breaks silently when the theme renames it.
- A CSS-only override in `local/css/`: cannot choose a file or zoom.

## Consequences

- An upstream merge that touches any of the files above conflicts. `photo.autosize.js` and
  `picture_content_asize.tpl` are the likely ones; resolve them by keeping the fork's
  `rvas_choose(display)` signature, which `photo.zoom.js` calls.
- The CSS must not go into `css/hf_base.css`, where task 02 first put it: `header.tpl` loads that
  file only for the skins that ship a stylesheet of their own, so 7 of the 18 skins (`clear`,
  `dark`, `dark_*`, `debug`, `grey`) got none of it - no scrolling at 100 %, overlapping labels.
  Found and moved 2026-10-05; `picture-zoom.spec.js` now runs once per skin.
- The behaviour is covered by `plugins/persons/tests/e2e/picture-fit.spec.js`,
  `picture-zoom.spec.js` and `picture-display.spec.js`; the theme has no suite of its own.
