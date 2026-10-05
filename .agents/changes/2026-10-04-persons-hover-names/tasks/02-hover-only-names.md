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

* [ ] Write the failing E2E specs first (see `design.md#tests`) and watch each fail for the
  expected reason:
  * `[HAPPY]` photo hovered outside every box: box visible, label opacity 0
  * `[HAPPY]` / `[NEG]` box 1 hovered: label 1 visible, label 2 stays 0
  * `[ST]` box 1 → empty photo area → off the stage: label 1 hides at each step
  * `[HAPPY]` label 1 focused by keyboard: label 1 visible
* [ ] Add `hoverBox(regionId)` to `PicturePage.js` (pointer to the centre of that box's rect)
* [ ] Rewrite task 01's `[ERR]` "every label visible on photo hover" case in its own cycle; the
  commit message names the decision that replaced it
* [ ] `overlay.js`: on `#persons-stage` `mousemove`, toggle `.person-box-active` on every
  `.person-box` whose `getBoundingClientRect()` contains the pointer; on `mouseleave`, clear it.
  Query the boxes on every move (boxes added by `editor.js` must work without a reload)
* [ ] `overlay.css`: label hidden by default; visible under `.person-box-active` and
  `.person-box:focus-within`. Leave the dimming rule and `pointer-events` untouched
* [ ] `editor.css`: every label visible under `#persons-stage.persons-tagging`
* [ ] Update the file header comments in `overlay.js` / `overlay.css` that state the old behaviour
  ("exactly one job", "the cost is that a box cannot be hovered")

## Verification

* [ ] With the photo hovered and the pointer outside every box, no name is visible
* [ ] With the pointer on a box, that box's name is visible and no other name is
* [ ] A box drawn in tagging mode shows its name on hover after leaving tagging mode, with no reload
* [ ] Tagging mode shows every name
* [ ] Unchanged and still green: `clicking the photo outside a box still navigates`,
  `hovering a name dims the photo outside that box`, the stale-box specs
* [ ] Persons unit, integration and E2E suites pass, twice in a row (commands in
  `.claude/rules/plugin-test-suites.md`); name the commands that ran
