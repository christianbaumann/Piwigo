---
id: 09
dependencies: []
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-09
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

* [x] Characterize current date editing first where not yet covered (`.claude/rules/testing.md`, *Cover the ground before you move it*)
* [x] Visible „✎ ändern“ button replaces click-on-text; Speichern/Abbrechen become „Fertig“
* [x] Save when focus leaves the whole date group or on Fertig; skip an unchanged value; an incomplete value shows the inline error and is not saved
* [x] Update the row text from the answer's `date`; remove `window.location.reload()`
* [x] Row status with the shared classes and strings: „Speichert …“, „✓ Gespeichert“, red „Nicht gespeichert: …“ (also for the partial-write message), staying until the next change
* [x] E2E specs updated; unit test for the group-leave decision if it lives in a pure function

## Verification

* [x] Changing year and qualifier and tabbing out saves once; the row shows the new date and „✓ Gespeichert“ with no reload **Note:** `date-qualifier.spec.js` [HAPPY] cases, `date-edit.spec.js` group-leave spec, no-reload check via `markDocument()`.
* [x] „zwischen“ without an end year shows the error and saves nothing **Note:** `date-qualifier.spec.js` [NEG] range-end cases assert the message and zero ws requests.
* [x] A read-only file shows the red status and it stays **Note:** `date-edit.spec.js`: the file not taking a date or a clear shows `--error` and „Nicht gespeichert:“, and it stays after Fertig.
* [x] photoinfo E2E and integration suites pass **Note:** Final verifier on the rebased state: photoinfo unit `OK (309 tests, 576 assertions)`, E2E `70 passed`, integration `SetDateTest|SetInfoTest|HistoryRecordingTest|TagHistoryTest` `OK (77 tests, 398 assertions)`. Full integration earlier in the loop: `OK (232 tests, 1359 assertions)`.

## Implementation notes

- No separate characterization commit: the existing `date-edit`, `date-qualifier`, `editor-buttons` specs and `SetDateTest` covered the old behaviour.
- A failed write leaves the editor open with the red status; a good save closes it. There is no Abbrechen any more, per the design.
- The empty date reads „Noch kein Datum“ (key `No date yet`); the old key is gone.
- The rebase onto task 10 produced conflicts in `info.css` and `editor-buttons.spec.js`; one `/* row status */` block and one `.photoinfo-edit` rule remain. Task 10's language keys serve both rows.
- The first attempt at the placeholder fix silently failed (`sed -i` without a backup suffix on macOS) while its commit claimed it; a commit with the same subject therefore touches only a spec, and a later commit does the work.
- For task 14: `handbuch/03-fototexte.html:231` quotes the old placeholder; `handbuch/tools/shoot.js` shots 23 and 24 click `#photoinfo-date-view` and `.photoinfo-cancel`, both gone. Open the date with `#photoinfo-date-edit`, and close it with `.photoinfo-done`, which saves a changed value.
- The Info row still opens on a click on the text (task 10); the Datum row does not.
