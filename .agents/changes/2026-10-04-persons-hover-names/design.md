---
datetime: 2026-10-04T13:27:33+02:00
author: Christian Baumann
tags: [persons, picture-page, overlay, ux]
---

# Persons: show a name only while its box is hovered

## Problem

On the public picture page, hovering the photo shows every person box **and every name label**
at the same time. On a group photo the labels cover the faces they name.

Wanted: hovering the photo shows the boxes only. A name shows only while the pointer is over
**its** box.

## Current state

| Piece | Where | Behaviour today |
|---|---|---|
| Box markup | `plugins/persons/template/public_overlay.tpl` | one `.person-box` per region, each with a `.person-box-label` (link or span) pinned to its bottom edge |
| Reveal | `plugins/persons/template/overlay.css` | `#persons-stage:hover .person-box { opacity: 1 }`. The label is a child, so it shows with the box |
| Dimming | `overlay.css` | `.person-box:has(.person-box-label:hover)` darkens the photo outside that box |
| Tagging mode | `plugins/persons/template/editor.css` | `#persons-stage.persons-tagging` shows every box and gives the overlay pointer events |
| Placement | `plugins/persons/template/overlay.js` | keeps `#persons-overlay` exactly over `#theMainImage`; no hover logic |

### Why plain CSS `:hover` does not work

```
#persons-stage
 ├ #theMainImage                      ← theme click handler + <area> map: prev / album / next
 └ #persons-overlay   pointer-events: none   ← clicks pass through to the photo
    └ .person-box     (inherits none)         ← :hover can never match
       └ .person-box-label  pointer-events: auto  ← the only hoverable part
```

The theme navigates on a photo click (`themes/modus/js/photo.autosize.js:148`,
`themes/default/template/picture_content.tpl:23-30`). The spec
`clicking the photo outside a box still navigates` in
`plugins/persons/tests/e2e/overlay.spec.js` guards it.

## Target behaviour

```
pointer position                 boxes      labels
───────────────────────────────  ─────────  ──────────────────────────────
off the photo                    hidden     hidden
on the photo, outside any box    visible    hidden
inside box 1                     visible    label 1 only
inside box 1 and box 2 (overlap) visible    label 1 and label 2
on label 1                       visible    label 1, photo outside box 1 dims
label 1 has keyboard focus       —          label 1
tagging mode                     visible    all
```

### Mechanism

```
overlay.js
  #persons-stage  mousemove ─► for each .person-box (queried live):
                                 pointer inside getBoundingClientRect()?
                                   yes → add .person-box-active
                                   no  → remove .person-box-active
  #persons-stage  mouseleave ─► remove .person-box-active from every box

overlay.css
  .person-box-label                                   opacity: 0
  .person-box-active  .person-box-label               opacity: 1
  .person-box:focus-within  .person-box-label         opacity: 1

editor.css
  #persons-stage.persons-tagging  .person-box-label   opacity: 1
```

The label keeps `pointer-events: auto` and stays clickable. The `mousemove` from the label
bubbles to the stage, so hovering the label keeps its box active.

## Key Decisions

### Detect box hover with a JS hit-test

- **Decision:** `overlay.js` listens to `mousemove` / `mouseleave` on `#persons-stage`, compares the
  pointer with each box's `getBoundingClientRect()` and toggles `.person-box-active`. Boxes keep
  `pointer-events: none`.
- **Reason:** The only option that reacts to the whole box and leaves photo-click navigation
  exactly as it is.
- **Trade-offs:** Label visibility now depends on JS (about 20 lines). Rejected: `pointer-events:
  auto` on boxes with CSS `:hover`, because a click on a face would stop navigating. Rejected:
  an invisible but hoverable label, because only the thin label strip would react, not the box.

### Every box under the pointer shows its label

- **Decision:** With overlapping boxes, every box that contains the pointer is active.
- **Reason:** No tie rule to define or test.
- **Trade-offs:** Two labels can show at once. Rejected: "smallest box wins", which needs a tie
  rule plus a new overlapping seed scenario to test it (YAGNI).

### Query the boxes on every move, no cache

- **Decision:** `querySelectorAll('.person-box')` and `getBoundingClientRect()` on each
  `mousemove`.
- **Reason:** The editor adds and removes boxes, and a resize or derivative switch moves them. A
  photo has a few boxes, so the cost is negligible.
- **Trade-offs:** Rejected: a cached rect list, which must be invalidated in the same places
  `overlay.js` already re-places the overlay and in the editor.

### Dimming stays on label hover

- **Decision:** `.person-box:has(.person-box-label:hover)` stays unchanged.
- **Reason:** Dimming on box hover would flash the photo dark every time the pointer crosses a
  face.
- **Trade-offs:** The dim needs a second, deliberate move onto the name.

### Tagging mode shows every label

- **Decision:** `#persons-stage.persons-tagging .person-box-label { opacity: 1 }` in
  `editor.css`.
- **Reason:** Same rule `editor.css` already applies to the boxes: while tagging, the user must
  see who is already tagged.
- **Trade-offs:** None worth noting.

### Stale boxes follow the same rule

- **Decision:** A `.person-box-stale` box shows its label on hover like any other box.
- **Reason:** One rule for all boxes. The stale box is already dimmed to 0.5 and dashed.
- **Trade-offs:** None.

### Keyboard focus shows the label

- **Decision:** `.person-box:focus-within .person-box-label { opacity: 1 }`.
- **Reason:** The label links stay in the tab order. A focused link that is invisible is an
  accessibility defect.
- **Trade-offs:** None.

### Touch devices: names do not show on the photo

- **Decision:** Accept it. On a device without hover, names never show on the photo.
- **Reason:** A tap on the photo navigates (theme handler), so tap-to-show would compete with
  navigation. The **Personen** row in the photo info box
  (`plugins/persons/template/public_persons.tpl`) still lists every name.
- **Trade-offs:** Touch users lose the face-to-name mapping on the photo.

### No fallback without JS

- **Decision:** Without JS, labels stay hidden.
- **Reason:** A second code path for a case nobody hits. The Personen row still lists the names.
- **Trade-offs:** None in practice.

## Tests

E2E only, in `plugins/persons/tests/e2e/overlay.spec.js` and `editor.spec.js`. Persons has no
JS unit layer, and the behaviour is computed style after real pointer moves (see
`.claude/rules/testing.md`, *Placement rule*).

| Tag | Case |
|---|---|
| `[HAPPY]` | Photo hovered outside every box: box `opacity` > 0.9, label `opacity` 0 |
| `[HAPPY]` / `[NEG]` | Box 1 hovered: label 1 visible, label 2 stays 0 (also guards against "all labels hidden") |
| `[ST]` | Box 1 → empty photo area → off the stage: label 1 hides at each step |
| `[ST]` | Tagging mode on: every label visible |
| `[HAPPY]` | Label 1 focused by keyboard: label 1 visible |
| `[NEG]` | Kept unchanged: `clicking the photo outside a box still navigates`, `hovering a name dims the photo outside that box` |

- `[BVA]` on the box edge: not applicable. `getBoundingClientRect()` is the oracle on both
  sides, and a one-pixel edge case would test the browser, not the plugin.
- `[DT]`: not applicable beyond the target-behaviour table above.
- New helpers in `plugins/persons/tests/e2e/support/PicturePage.js`: `labelStyle(id)`,
  `hoverBox(id)`. Locators stay in the page object (`.claude/rules/plugin-test-suites.md`).
- Touch behaviour (R7) is not automated; it follows from the decision above and needs no
  hand check.

## Related

- Handbook: check whether any screenshot in `handbuch/` shows names on the photo; re-shoot rules
  in `.claude/rules/handbook.md`.
- Previous change on the same page: `.agents/changes/2026-10-04-persons-live-row/design.md`.
- Branch: `fix/persons-hover-names` off `master`, after the live-row work is committed.
