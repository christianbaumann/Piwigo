---
id: 01
dependencies: []
---

# Task 01: Characterize the Personen row and leaving tagging mode as they are today

Before anything changes, record in tests how the Personen row and tagging-mode exit behave
today. Tasks 02 and 03 then run against these tests as their regression net.

## References

* `design.md#tests` (step 0)
* `.claude/rules/testing.md` — "Cover the ground before you move it"
* `.claude/rules/test-design.md` — `[ERR]` tagging, "Proving a check can actually fail"
* `plugins/persons/tests/Integration/PicturePageSourceTest.php`
* `plugins/persons/tests/e2e/editor.spec.js`, `tests/e2e/support/PicturePage.js`

## Work

* [x] Integration: `[ERR]` a photo with no face carries no `id="Persons"` at all. Comment that
  task 02 replaces this on purpose
  **Note:** `testAPhotoWithNoFaceCarriesNoPersonRow`
* [x] Integration: check that the existing row cases (`testThePersonRowIsRenderedInsideTheStandardInfoList`,
  `testTheRowIsAbsentWhenTheDisplayKeyIsOff`, `testAGuestSeesNoOverlay`) already cover
  the rendered, switched-off and guest cases. Add only what is missing
  **Note:** all three already covered; nothing added
* [x] E2E: `[ERR]` after saving a box, `#Persons` still shows the content from page load (no live
  update). Comment that task 02 replaces this on purpose
  **Note:** `a saved name does not reach the row without a reload` (overlay scenario) and
  `the first face on a photo does not create the row` (empty scenario)
* [x] E2E: `[ERR]` "Markieren beenden" and Esc after a save leave the page without a navigation
  (detect through a navigation/`load` event, not a timeout). Comment that task 03 replaces this
  on purpose
  **Note:** detected through a `window` marker (`markDocument()` / `sameDocument()`) that a
  reload replaces and that `beforeunload`/`pagehide` clear, so a reload still pending is seen too
  (review fix)
* [x] Put any new locators in `PicturePage.js`
* [x] Break the behaviour each new test watches once and watch it go red. Record in the commit
  message which change made it fail
  **Note:** template without `!empty($PERSONS_NAMES)` → integration red; `editor.js` writing
  the name into a (created) `#Persons` after save → both row specs red; `location.reload()` in
  `exit()` → both exit specs red (`sameDocument()` false). Host/container md5 compared before each run

## Verification

* [x] The new tests pass on unchanged production code
  **Note:** `PicturePageSourceTest` 15/15, `-g "current behaviour"` 4/4
* [x] Each new test was seen red against a deliberate break, then green again after the revert
  **Note:** see the break list above; production diff empty after revert
* [x] Persons integration and E2E suites pass twice in a row (commands in
  `.claude/rules/plugin-test-suites.md`)
  **Note:** integration 105 OK (1 skipped), E2E 35 passed, both runs; unit 114 OK
* [x] Committed on `fix/persons-live-row` with no production change
  **Note:** commit `a0cfef2c0`, test files only; review fixes (retrying row check, `#standard`
  anti-vacuity guard, navigation-pending detection) in the follow-up commit, re-seen red against
  deferred breaks. Task approved after /verify
