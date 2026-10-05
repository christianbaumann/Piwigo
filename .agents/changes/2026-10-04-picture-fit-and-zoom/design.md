---
datetime: 2026-10-04T13:09:05+02:00
author: Christian Baumann
tags: [modus-theme, picture-page, zoom, persons-overlay, fork-local]
---

# Picture page: fit to the available area, and zoom

## Goal

On the single-photo page (`picture.php`, `modus` theme) the photo fills the available area,
with almost no whitespace. A zoom control lets the viewer go back to natural size (100 %) and
zoom in further.

Status: designed and split into tasks (`tasks/`). Tasks 01 to 04 implemented.

## Current state (measured 2026-10-04)

Measured in the DDEV container browser as `persons_normal`. Only element sizes were recorded,
no screenshots (the gallery holds private family scans, see `.claude/rules/handbook.md`).

```
1920×1080, "wide" layout
┌────────────────── header + toolbar: 120 px ──────────────────┐
├────────────────────────────────────┬─────────────────────────┤
│ #theImage  1392 px (75 %)          │ #imageInfos 504 px      │
│                                    │                         │
│   ~440 px  ┌─────────┐  ~440 px    │                         │
│   empty    │ 515×771 │  empty      │                         │
│            │ natural │             │                         │
│            └─────────┘             │                         │
│          ~190 px empty             │                         │
└────────────────────────────────────┴─────────────────────────┘
```

| Viewport | Photo | Source | Shown at | Area `#theImage` |
|---|---|---|---|---|
| 1920×1080 | id 1 (landscape) | 3540×2383 | 1224×823 (`xl`) | 1392 × ~940 |
| 1920×1080 | id 2 (portrait) | 515×771 | 515×771 (original) | 1392 × ~940 |
| 1280×800 | id 2 | 515×771 | 396×594 (`me`) | 912 × ~660 |

Gallery (2026-10-04): 103 of 106 photos have a long side < 1242 px, average 867 px.

Three causes, in order of impact:

1. **No upscaling.** `rvas_choose()` in `themes/modus/js/photo.autosize.js` picks the largest
   derivative that fits and shows it at natural pixel size. Most scans are smaller than the area.
2. **Derivative steps.** A large photo gets the largest step that fits (`xl` 1224 px). The next
   step (`xxl` 1656 px) is skipped, so it does not fill the area either.
3. **Layout.** `.wide #theImage { width: 75% }` (`themes/modus/css/hf_base.css:877`) and 120 px
   of header and toolbar. This cause is accepted, see *Layout stays*.

## Target behaviour

```
#imageToolBar:  ◀ ▲ ▶ … [ − ] [ Einpassen ] [ 100 % ] [ + ] … ⓘ ⬇

zoom:  fit ──×1.25──▶ … ──▶ 400 % of natural size (max)
       ▲ every photo opens here        ▲ "100 %" jumps to natural size

#theImage (overflow:auto)
 ├─ #theMainImage   width/height = zoom × natural size   ← layout zoom
 └─ persons overlay  re-places through its ResizeObserver (no change)
```

- Default on every photo: **fit**. The photo fills the area with its aspect ratio kept, and
  small originals are upscaled (soft, accepted).
- `100 %` shows the loaded file at natural size. `+` and `−` step the zoom by ×1.25.
- Fit is the lower bound and 400 % of natural size is the upper bound.

## Key Decisions

### Upscale small originals to fill the area

- **Decision:** Fit mode scales every photo to the available area, and upscales when the
  original is smaller than the area.
- **Reason:** 97 % of the photos are smaller than the area, so no other option removes the
  whitespace. Old family scans are viewed for their content, not their sharpness.
- **Trade-offs:** Small scans look soft. Rejected: a 2× upscale limit (whitespace stays) and
  no upscaling (most photos would not change).

### Layout zoom, not transform zoom

- **Decision:** Zoom sets `width`/`height` of `#theMainImage` inside `#theImage`, and
  `#theImage` gets `overflow:auto`.
- **Reason:** The persons overlay (`plugins/persons/template/overlay.js:44-76`) places its face
  boxes from `getBoundingClientRect()` and re-places them on `resize` and through a
  `ResizeObserver`. A layout change triggers that, so the overlay needs no change.
- **Trade-offs:** Zoom is less smooth than a GPU transform. Rejected: CSS `transform: scale()`
  (does not trigger `ResizeObserver`, so `overlay.js` would need a rewrite), and a vendored
  library such as `@panzoom/panzoom` (transform-based as well, and a new dependency).

### Zoom controls

- **Decision:** Toolbar buttons `−`, `Einpassen`, `100 %` and `+`. Keys `+`, `−` and `0`
  (0 = fit). Ctrl/Cmd + wheel over the photo.
- **Reason:** The buttons are visible and need no explanation. The keys and Ctrl/Cmd+wheel match
  common viewer behaviour and keep plain wheel for page scrolling.
- **Trade-offs:** Rejected: buttons only (slower for frequent zooming), and plain wheel zoom
  (takes over page scrolling).

### Continuous zoom steps

- **Decision:** Each `+`/`−` multiplies the zoom by 1.25, between fit and 400 % of natural size.
  `Einpassen` and `100 %` jump straight to those two levels.
- **Reason:** Finer control than fixed steps (user's choice).
- **Trade-offs:** The levels in between are odd values (125 %, 156 %, …). Rejected: fixed steps
  (fit, 100 %, 200 %, 400 %), and fit/100 % only.

### Panning

- **Decision:** Scrollbars and wheel always pan. Drag-to-pan works only while the persons editor
  is not active.
- **Reason:** The persons editor draws a region with a mouse drag (`editor.js:368`). Drag-to-pan
  during editing would steal that drag.
- **Trade-offs:** While editing a zoomed photo, the viewer pans with scrollbars or wheel only.
  Rejected: no drag-to-pan at all, and drag-to-pan always (breaks region drawing).

### Click navigation off when zoomed in

- **Decision:** When zoom > fit, a click on the photo does not navigate.
- **Reason:** The click handler in `photo.autosize.js` goes to prev, up or next based on the click
  position. When zoomed in, a click is meant for the photo, not for leaving it.
- **Trade-offs:** Prev and next stay available through the toolbar and the arrow keys. Rejected:
  always on (a click leaves a zoomed photo), and always off (removes a feature in fit mode).

### Image map removed when scaled

- **Decision:** Remove `usemap` from `#theMainImage` whenever the photo is shown at a size other
  than its natural size, as the existing `dpr>1` path already does.
- **Reason:** The `<map>` coords in `picture_content_asize.tpl` use natural-size pixels. The
  percentage-based click handler already replaces the map when it is removed.
- **Trade-offs:** Rejected: recomputing the coords on every zoom (more code for the same result).

### Load the smallest sufficient file

- **Decision:** Load the smallest derivative that is ≥ the display size at the current zoom. On
  zoom-in, switch to a bigger one, up to the original when the viewer may see it (`U_ORIGINAL`).
- **Reason:** Large photos stay sharp (fixes cause 2), and bandwidth stays low in fit mode.
  `rvas_choose()` already follows this rule for `dpr>1`, and it becomes the normal path.
- **Trade-offs:** A zoom-in can trigger a file load. Rejected: always the largest file (heavy),
  and CSS scaling of the fit file only (large photos get soft when zoomed).

### Nothing is remembered

- **Decision:** Every photo opens in fit, and no zoom state is stored.
- **Reason:** Predictable behaviour, and no storage code (user's choice).
- **Trade-offs:** A viewer who prefers 100 % must press it on every photo. Rejected: remembering
  fit vs. 100 % in `localStorage`, and remembering the exact zoom level.

### Mobile: native pinch

- **Decision:** On the narrow layout (no `wide` class, < 1262 px) the photo is fitted as well.
  Pinch-zoom stays the browser's native one. Only `Einpassen` and `100 %` are shown, without
  `+`/`−`.
- **Reason:** Native pinch already works and is what mobile users expect.
- **Trade-offs:** Rejected: the full desktop control (`+`/`−` are not useful next to pinch), and
  custom pinch handling (complex, competes with the browser).

### Slideshow: fit only

- **Decision:** The slideshow shows photos in fit mode and has no zoom controls.
- **Reason:** A slideshow is for viewing, not for inspecting.
- **Trade-offs:** Rejected: full zoom in the slideshow, and leaving it at natural size.

### Layout stays

- **Decision:** The info column (25 %) and the header and toolbar (120 px) stay unchanged.
- **Reason:** In fit mode, portrait photos are limited by height, and 3:2 landscape photos fit the
  1392 × ~940 area almost exactly. The toolbar holds the new zoom control.
- **Trade-offs:** Some width stays unused for portrait photos. Rejected: a collapsible info
  column, and moving the info below the photo.

### Edit the modus theme directly

- **Decision:** Change `themes/modus/` in place: `js/photo.autosize.js`, a new `js/photo.zoom.js`,
  `template/picture_content_asize.tpl`, and CSS. Record this as fork-local in a new decision file
  `docs/agents/decisions/0032-…` (0031 is already taken).
- **Reason:** The theme is tracked in this fork, and the change is small. The fork already carries
  fork-local core changes (`CLAUDE.md`, the two provenance triggers).
- **Trade-offs:** An upstream merge can conflict in these files, and decision 0032 names that
  risk. Rejected: a plugin that overrides `rvas_choose()` at runtime (patches a global function,
  more fragile), and a CSS-only override in `local/css/` (no derivative choice, no zoom).

### Tests in the persons Playwright suite

- **Decision:** New specs in `plugins/persons/tests/e2e/`. They use the seeded throwaway photo
  (`tests/e2e/support/seed.php`) and the `PicturePage` page object (locators go there). After the
  change, the whole persons E2E suite runs as the regression check.
- **Reason:** The theme has no suite and there is no JS unit runner, so the behaviour is only
  visible in a real browser. The persons suite already seeds a picture page and checks overlay
  placement (`PicturePage.settle()`), which is the main thing a zoom can break.
- **Trade-offs:** Theme behaviour is tested from a plugin's suite. Rejected: a new theme-owned
  Playwright suite (a second setup to maintain), and hand checks only.

Planned cases (tags per `.claude/rules/test-design.md`):

| Tag | Case |
|---|---|
| `[HAPPY]` | fit: the rendered photo touches the area on its limiting side |
| `[BVA]` | a small original is upscaled. A large one loads the next-larger derivative |
| `[ST]` | `+` → `−` → `100 %` → `Einpassen` gives the expected sizes |
| `[BVA]` | `+` stops at 400 %. `−` stops at fit |
| `[ST]` | the next photo opens in fit again (nothing is remembered) |
| `[NEG]` | a click on a zoomed photo does not navigate |
| `[HAPPY]` | face boxes stay on target at a zoom > 100 % |

### The size menu keeps its meaning

- **Decision:** Choosing a size in the existing size menu (`picture_sizes_icon`, core's
  `changeImgSrc()`) shows that file at its natural size and leaves fit mode.
- **Reason:** This is the current behaviour. `overlay.spec.js:94` depends on it (smallest
  derivative → photo gets narrower) and stays valid.
- **Trade-offs:** The menu and the zoom control overlap. Rejected: hiding the menu (the spec must
  be rewritten), and changing only the file while staying fitted (the menu then has no visible
  effect).

### 100 % means 1:1 against the original

- **Decision:** `100 %` = the original's pixel size (`piwigo_images.width/height`). It loads the
  original or the derivative of that size. If the viewer may not see the original (`U_ORIGINAL`
  not set), it uses the largest derivative at its natural size.
- **Reason:** The result does not depend on which file fit mode happened to load.
- **Trade-offs:** A large photo at 100 % is bigger than the area, so scrolling and the click guard
  are needed as soon as `100 %` exists. Rejected: the natural size of the loaded file, and always
  the largest derivative.

### A small fixture photo

- **Decision:** `FixtureBuilder::createTestImage()` gets an optional source choice, and `seed.php`
  gets `--source=small`, which copies the smallest gallery image. Its size is asserted before the
  test runs.
- **Reason:** The default fixture copies the first `.png` by id, which is id 1 (3540×2383). The
  `[BVA]` upscale case needs a source smaller than the area.
- **Trade-offs:** Rejected: downscaling the copy with ImageMagick (a second fixture path), and
  testing only the large case.

### Code shape

- **Decision:**
  ```
  themes/modus/js/photo.autosize.js   rvas_choose(): picks the file for a target display size
  themes/modus/js/photo.zoom.js  NEW  zoom state (fit | factor), buttons, keys, wheel,
                                      drag-pan, click guard → rvas_choose() + img width/height
  themes/modus/template/picture_content_asize.tpl   zoom buttons, RVAS.original {w, h, url?}
  themes/modus/css/photo.zoom.css NEW #theImage overflow:auto, button styles (every skin)
  ```
- **Reason:** The file choice stays where it already is, and interaction is kept in one new file.
  The buttons are in the modus content template, so `themes/default/` stays untouched.
- **Trade-offs:** Rejected: everything in `photo.autosize.js` (one larger file), and the buttons in
  `themes/default/template/picture.tpl` `#imageToolBar` (changes the shared parent theme, which is
  a bigger upstream risk).

## Delivery

```
01 characterize ──▶ 02 fit + Einpassen/100 % ──┬──▶ 03 continuous zoom
                                               └──▶ 04 drag-to-pan
```

Tasks are in `tasks/`. Implement on a new `fix/picture-fit-and-zoom` branch off `master`
(`.claude/rules/git-and-commits.md`), not on `fix/persons-live-row`.

## References

- `themes/modus/js/photo.autosize.js`: `rvas_choose()`, `rvas_get_available_size()`, click handler
- `themes/modus/themeconf.inc.php:369-497`: `modus_picture_content()`, server-side derivative choice
- `themes/modus/template/picture_content_asize.tpl`: image, `RVAS` derivative list, `<map>`
- `themes/modus/css/hf_base.css:877`: `.wide #theImage { width: 75% }`
- `plugins/persons/template/overlay.js`, `plugins/persons/template/editor.js`
- `plugins/persons/tests/e2e/support/PicturePage.js`, `.claude/rules/plugin-test-suites.md`
- `.claude/rules/piwigo-architecture.md` (themes), `.claude/rules/e2e-tests.md`
