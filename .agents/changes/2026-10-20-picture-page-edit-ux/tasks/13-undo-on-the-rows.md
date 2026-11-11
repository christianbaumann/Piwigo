---
id: 13
dependencies:
- 07
- 08
- 09
- 10
- 11
- 12
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-13
---

# Task 13: „Rückgängig“ in each row's status

After a save, each row shows „✓ Gespeichert · Rückgängig“, which undoes exactly that change and keeps
the row and the change list in step.

## References

* `design.md#save-model-konzept-b-every-step-saves-itself-visibly`
* `design.md#one-look-for-the-row-status`
* The `history_id` / `history_ids` returned by tasks 02, 03, 04
* `pwg.photoinfo.undo` (task 07)

## Work

* [x] Datum and Info rows (photoinfo), Schlagwörter row (typetags, submodule) and Personen row (persons) add „Rückgängig“ to the saved status, calling `pwg.photoinfo.undo` with the returned entry id
* [x] After undo, the row shows „↶ Rückgängig gemacht“ and its value updates in place; the change list refreshes
* [x] Without photoinfo active (no entry id), the status shows no Rückgängig
* [x] E2E spec per row: save, undo, value restored, list shows the undo entry

## Verification

* [x] Each of the four rows undoes its own last save without reload **Note:** `row-undo.spec.js` in photoinfo (Datum, Info), typetags and persons: save, Rückgängig, value restored in place, no reload, change list shows the undo entry; also an undo started in the change list. Rotated photos (codes 1 and 3) in persons' spec.
* [x] A refused undo (conflict) shows the refusal in red on the row **Note:** The `[NEG]` refused-undo case in each of the three specs (409 „Wurde inzwischen von …“, red on the row).
* [x] With photoinfo deactivated, typetags and persons rows show „✓ Gespeichert“ only **Note:** typetags and persons `row-undo.spec.js`: with the history id stripped from the answer (a Playwright route; the plugin is not deactivated, since the database is shared) the row shows „✓ Gespeichert“ only. That the server omits the id with photoinfo off is not tested here.
* [x] typetags, persons and photoinfo E2E suites pass **Note:** Implementer: photoinfo E2E 79, typetags 75, persons 196 passed; verifier: photoinfo integration `OK (322 tests)`, typetags `OK (179 tests)`, persons `226 tests, 1 skipped`; final recheck: persons `row-undo` 11 passed twice, typetags 11, photoinfo 10.

## Implementation notes

- The rows learn their new value from the undo answer (Datum, Info, Schlagwörter) or by re-reading `pwg.persons.getRegions` (Personen). Every undo answer carries `field`, the value fields and one write shape (`written`, `message`, `message_l10n`); a region undo now reports a failed keyword write (`persons_undo_outcome()`).
- `photoinfo:undone` on `document` is the one contract between the plugins; `history.js` refreshes only on it. typetags and persons have no dependency on photoinfo.
- A write failure after an undo shows the red „Gespeichert, aber nicht in die Bilddatei geschrieben: …“ on the row, and in the change list's `#photoinfo-history-warning`.
- typetags: when an undo restores a tag the page never showed, the row says to reload the page instead of reloading. When a save's file write fails the green status is hidden, so that save offers its Rückgängig only in the change list.
- `photoinfo_date_fields()` moved to `date.inc.php` (ws.php never loads the public events file).
- persons gained `toDisplay()`, the inverse of `toStored()`.
- Three new German keys in `local/language/de_DE.lang.php` (42 in all); typetags' prefilter code changed, so an install needs `_data/templates_c` cleared once and the remote the manual purge.
- Hand check: with photoinfo deactivated, typetags' and persons' answers carry no history id.
- Open: push `plugins/typetags` (c48112b, 6419cf9, 315e328, af47f60) to `github.com/christianbaumann/Piwigo-Colored-Tags`.
