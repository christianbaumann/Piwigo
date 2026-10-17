# 0078 - Marking a person needs a mouse

Date: 2026-10-17
Status: accepted
Design: `.agents/changes/2026-10-15-exploratory-testing-answers/design.md` (*Marking a person stays
mouse-only*). Task 31 of that change. (The design names this file 0069; that number was taken by
*the remote shows no PHP errors*.)

## Context

SES-2026-10-15-18/FND-01: with the keyboard alone, a user can enter marking mode, pick a name from
the picker and delete a frame they drew, but cannot draw a frame. The editor draws with a mouse
drag (`plugins/persons/template/editor.js`); its only key handlers are the picker's
ArrowUp/ArrowDown/Enter and Escape. ArrowRight in marking mode is not handled by the editor, so the
theme's arrow-key navigation opens the next photo and the mode ends with the page.

## Decision

No code change. `handbuch/05-personen.html` already says "Mit gedrückter Maustaste". Two gaps are
accepted:

- No keyboard way to draw a frame (WCAG 2.1 SC 2.1.1, Keyboard).
- ArrowRight in marking mode leaves the photo and ends the mode.

## Consequences

- Marking a person is not keyboard-operable.
- No skipped test: the gap is a choice, not a defect to fix later, so
  `.claude/rules/test-design.md` (*A known gap becomes a deliberately failing test*) does not apply.

## Rejected

- Drawing, moving and resizing a frame with keys: a second editor input model for a handful of
  users who mark faces in scanned family photos.
- Blocking only arrow-key photo navigation in marking mode: fixes the smaller of the two gaps and
  leaves the larger.
