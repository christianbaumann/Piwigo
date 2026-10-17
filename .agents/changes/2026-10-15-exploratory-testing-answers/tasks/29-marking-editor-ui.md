---
id: 29
dependencies: []
---

# Task 29: Marking editor: delete, draw, labels, picker, search

Every control of the marking editor does what it shows.

## References

* `design.md#the-marking-editor-delete-draw-labels-picker`
* decisions 0019, 0065
* `plugins/persons/template/editor.js`, `editor.css`, `overlay.css`, `admin/persons.php`

## Work

* [x] Delete control above every box in marking mode
* [x] A drag starting inside a frame draws a new frame; `x` and label stay clickable
* [x] Labels ellipsis; full name on hover on top, inside the photo edge
* [x] Enter filters on the typed text at once, commits only an exact match, else Anlegen
* [x] 404 on delete removes the box with "Markierung ist nicht mehr vorhanden"
* [x] Admin search escapes `%`, `_` after `stripslashes`

## Verification

* [x] E2E per item, as normal and admin where 0065 differs
  **Note:** Verified via `tests/e2e/editor-marking.spec.js` (22 tests: 8 cases as normal and as administrator, plus a box nobody drew as administrator and as normal), `npx playwright test editor-marking` green, 22 passed (2026-10-17). Red first: 14 of 22 failed before the production change.
* [x] Integration: search for `%` lists only matching names
  **Note:** Verified via `AdminPersonsScreenTest::testAWildcardInTheSearchMatchesOnlyNamesContainingIt` (`%` and `_`) and `testAQuoteInTheSearchIsShownAsTyped`; both failed before the fix. `pwg.persons.getList` already escaped.
* [x] persons suites pass
  **Note:** unit 218 OK; integration 214 tests, 1152 assertions, 1 skipped, OK; E2E full run 184 passed, 2 failed (`picture-zoom-scrollbar.spec.js`, task 37's untracked spec with its modus `base.css.tpl` change in progress, not task 29) (2026-10-17). No `E2E Marking` person or tag left in the database. Handbook `05-personen.html` Enter paragraphs rewritten for the new rule; `check.php` OK.

## Deviations

* Stale boxes in tagging mode dim by border colour, not `opacity: 0.5` (an opacity below 1 makes the box a stacking context and a later box would cover its x).
* With text typed the picker highlights the exact match, else the Anlegen entry (was: first partial match), so Enter and the highlight agree. Arrow keys still reach any entry. The characterization test "a partial match is listed before Anlegen" was updated accordingly.
* After a 404 the Personen row is rebuilt from the boxes left on the page (the answer carries no list).
* New constant `PERSONS_MARK_GONE` (language key), assigned in `render.inc.php`; en_UK/de_DE entries added.
