---
id: 09
dependencies: []
status: todo
owner:
branch:
---

# Task 09: Datum row saves as one value, without reload

The date opens with „✎ ändern“, saves as one value when focus leaves the date group or on „Fertig“,
shows the row status, and never reloads the page.

## References

* `design.md#save-model-konzept-b-every-step-saves-itself-visibly`
* `design.md#the-date-is-saved-as-one-value`
* `design.md#no-page-reload-after-a-save`
* `design.md#status-and-errors-stay-on-the-row`
* `design.md#one-look-for-the-row-status`
* `plugins/photoinfo/template/date.js`, `public_date.tpl`, `info.css`
* `plugins/photoinfo/include/ws_functions.inc.php` (`ws_photoinfo_setDate()` already returns `date`)
* `plugins/photoinfo/tests/e2e/date-edit.spec.js`, `date-qualifier.spec.js`, `editor-buttons.spec.js`

## Work

* [ ] Characterize current date editing first where not yet covered (`.claude/rules/testing.md`, *Cover the ground before you move it*)
* [ ] Visible „✎ ändern“ button replaces click-on-text; Speichern/Abbrechen become „Fertig“
* [ ] Save when focus leaves the whole date group or on Fertig; skip an unchanged value; an incomplete value shows the inline error and is not saved
* [ ] Update the row text from the answer's `date`; remove `window.location.reload()`
* [ ] Row status with the shared classes and strings: „Speichert …“, „✓ Gespeichert“, red „Nicht gespeichert: …“ (also for the partial-write message), staying until the next change
* [ ] E2E specs updated; unit test for the group-leave decision if it lives in a pure function

## Verification

* [ ] Changing year and qualifier and tabbing out saves once; the row shows the new date and „✓ Gespeichert“ with no reload
* [ ] „zwischen“ without an end year shows the error and saves nothing
* [ ] A read-only file shows the red status and it stays
* [ ] photoinfo E2E and integration suites pass
