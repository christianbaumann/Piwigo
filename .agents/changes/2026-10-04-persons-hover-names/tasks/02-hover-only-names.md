---
id: 02
dependencies:
- 01
---

# Task 02: Show a name only while its box is hovered

Hovering the photo shows the boxes without names. A name shows only while the pointer is over
its box, while its label has keyboard focus, or while tagging mode is on. A photo click still
navigates.

## References

* `design.md#target-behaviour`
* `design.md#mechanism`
* `design.md#key-decisions`
* `design.md#tests`
* `plugins/persons/template/overlay.js`, `overlay.css`, `editor.css`
* `plugins/persons/tests/e2e/overlay.spec.js`, `editor.spec.js`, `support/PicturePage.js`
* `.claude/rules/e2e-tests.md`, `.claude/rules/test-design.md`

## Work

* [x] Write the failing E2E specs first (see `design.md#tests`) and watch each fail for the
  expected reason:
  * `[HAPPY]` photo hovered outside every box: box visible, label opacity 0
  * `[HAPPY]` / `[NEG]` box 1 hovered: label 1 visible, label 2 stays 0
  * `[ST]` box 1 → empty photo area → off the stage: label 1 hides at each step
  * `[HAPPY]` label 1 focused by keyboard: label 1 visible
  **Note:** all four in `overlay.spec.js`. Run against the unchanged code, each failed for the
  expected reason: the first three read label opacity 1 where 0 was expected, the focus case
  read 0 where > 0.9 was expected
* [x] Add `hoverBox(regionId)` to `PicturePage.js` (pointer to the centre of that box's rect)
  **Note:** also `boxContains(regionId, point)` and `focusLabel(regionId)`
* [x] Rewrite task 01's `[ERR]` `hovering the photo outside every box shows every name` case (`overlay.spec.js`) in its own cycle; the
  commit message names the decision that replaced it
  **Note:** now `hovering the photo outside every box shows the boxes without names` `[HAPPY]`.
  Same edit cycle as the other new specs, before any production edit; one commit with the
  production change, since the rewritten case is red until then
* [x] `overlay.js`: on `#persons-stage` `mousemove`, toggle `.person-box-active` on every
  `.person-box` whose `getBoundingClientRect()` contains the pointer; on `mouseleave`, clear it.
  Query the boxes on every move (boxes added by `editor.js` must work without a reload)
* [x] `overlay.css`: label hidden by default; visible under `.person-box-active` and
  `.person-box:focus-within`. Leave the dimming rule and `pointer-events` untouched
  **Note — deviation:** `.person-box:focus-within` also sets the **box** to opacity 1. The design
  names only the label rule, but the label sits inside a box at opacity 0 while the pointer is off
  the photo, so the label rule alone left a focused name invisible (the focus spec stayed red)
* [x] `editor.css`: every label visible under `#persons-stage.persons-tagging`
* [x] Update the file header comments in `overlay.js` / `overlay.css` that state the old behaviour
  ("exactly one job", "the cost is that a box cannot be hovered")

## Verification

* [x] With the photo hovered and the pointer outside every box, no name is visible
  **Note:** Verified via `hovering the photo outside every box shows the boxes without names`
* [x] With the pointer on a box, that box's name is visible and no other name is
  **Note:** Verified via `hovering a box shows its name and no other`
* [x] A box drawn in tagging mode shows its name on hover after leaving tagging mode, with no reload
  **Note:** Verified via `admin.spec.js` `a box drawn here shows its name on hover after tagging,
  with no reload`. On the admin screen, because the public page reloads on leaving after a save.
  The spec passed against the old code as well (old CSS showed every name on any hover), so it
  was proven against a mutant instead: caching the box list once at init killed it, and only it
* [x] Tagging mode shows every name
  **Note:** Verified via task 01's `tagging mode shows every saved name without a hover`, unchanged and green; with labels hidden by default it now depends on the new `editor.css` rule
* [x] Unchanged and still green: `clicking the photo outside a box still navigates`,
  `hovering a name dims the photo outside that box`, the stale-box specs
  **Note:** Verified via the overlay/editor/admin run, 57 passed
* [x] Persons unit, integration and E2E suites pass, twice in a row (commands in
  `.claude/rules/plugin-test-suites.md`); name the commands that ran
  **Note:** each twice: `phpunit --testsuite unit` OK (130 tests); `phpunit --testsuite
  integration` OK (110 tests, 1 skipped, the skip was already there); `npx playwright test` in
  `plugins/persons` 117 passed (5.0m)

## Mutants

Each edit checked synced into the container (md5) before the run, then reverted.

| Mutant | Expected killer | Result |
|---|---|---|
| box list queried once at init instead of per move (`overlay.js`) | `a box drawn here shows its name on hover after tagging, with no reload` | killed; `hovering a box shows its name and no other` stayed green |
| `mouseleave` handler not registered (`overlay.js`) | `a name hides again once the pointer leaves its box` | **survived**, overlay/editor/admin specs 57 passed. Not a weak test: off the photo every box is at opacity 0, so a left-over `.person-box-active` cannot be seen, and re-entering fires a `mousemove` that recomputes it. The handler is in the design but has no observable effect |
