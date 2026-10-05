---
id: 01
dependencies: []
---

# Task 01: Characterize name label visibility as it is today

Before anything changes, record in E2E tests when the name labels on the photo are visible
today. Task 02 then runs against these tests as its regression net.

## References

* `design.md#current-state`
* `.claude/rules/testing.md` — "Cover the ground before you move it"
* `.claude/rules/test-design.md` — `[ERR]` tagging, "Proving a check can actually fail", anti-vacuity
* `plugins/persons/template/overlay.css`, `plugins/persons/template/editor.css`
* `plugins/persons/tests/e2e/overlay.spec.js` (`a box is hidden until the photo is hovered`)
* `plugins/persons/tests/e2e/editor.spec.js`, `plugins/persons/tests/e2e/support/PicturePage.js`

## Work

* [x] Add a `labelStyle(regionId)` helper (computed `opacity` of the label, which inherits the box's)
  to `PicturePage.js`
  **Note:** opacity is not inherited in CSS, so the helper returns the product over the label and
  its ancestors up to `#persons-stage`, read only after every CSS transition on the stage has
  finished (see the M3 note below)
* [x] E2E `[ERR]`: before the photo is hovered, every label's effective opacity is 0
* [x] E2E `[ERR]`: with the pointer on the photo **outside** every box, every label is visible.
  Comment that task 02 replaces this on purpose
* [x] E2E `[ERR]`: in tagging mode, every saved box's label is visible without a hover
* [x] Assert the seeded region count is at least 2 before any "every label" assertion
* [x] Put any new locators in `PicturePage.js`
  **Note:** also `hoverPhotoAt(fx, fy)` and `stageIsHovered()`; the specs carry no locator
* [x] Break the behaviour each new test watches once (e.g. drop the `#persons-stage:hover` rule,
  drop the tagging-mode opacity rule) and watch it go red. Record in the commit message which
  change made each one fail
  **Note:** each edit checked synced into the container (md5) before the run, then reverted. Each
  run was filtered with `-g` to the three new tests, so "killed only by its own test" holds within
  those three. Outside them, the existing `a box is hidden until the photo is hovered` watches the
  rules M1 and M2 break and was not part of the mutant runs

  | Mutant | Expected killer | Result |
  |---|---|---|
  | M1 `.person-box { opacity: 0 }` → `1` (`overlay.css`) | `every name is hidden before the photo is hovered` | killed; the other two new tests stayed green |
  | M2 drop `#persons-stage:hover .person-box` (`overlay.css`) | `hovering the photo outside every box shows every name` | killed; the other two new tests stayed green |
  | M3 drop `#persons-stage.persons-tagging .person-box` (`editor.css`) | `tagging mode shows every saved name without a hover` | first **survived**: the toggle sits inside the stage, so the boxes were already shown by the hover and the poll read the 0.15 s fade-out above 0.9. Fixed by making `labelStyle()` wait for the stage's CSS transitions; then killed; the other two new tests stayed green |

## Verification

* [x] The new tests pass against today's code, twice in a row
  **Note:** Verified via two consecutive `npx playwright test -g ...` runs, 5 passed each (incl. setup)
* [x] Each new test has been seen red once against a deliberate break, and the break is reverted
* [x] The full persons E2E suite passes (command in `.claude/rules/plugin-test-suites.md`)
  **Note:** 113 passed (4.7m), full persons Playwright run
* [x] Committed on its own, before any production change of task 02
