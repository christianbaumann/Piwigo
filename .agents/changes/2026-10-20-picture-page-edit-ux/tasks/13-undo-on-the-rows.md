---
id: 13
dependencies:
- 07
- 08
- 09
- 10
- 11
- 12
status: todo
owner:
branch:
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

* [ ] Datum and Info rows (photoinfo), Schlagwörter row (typetags, submodule) and Personen row (persons) add „Rückgängig“ to the saved status, calling `pwg.photoinfo.undo` with the returned entry id
* [ ] After undo, the row shows „↶ Rückgängig gemacht“ and its value updates in place; the change list refreshes
* [ ] Without photoinfo active (no entry id), the status shows no Rückgängig
* [ ] E2E spec per row: save, undo, value restored, list shows the undo entry

## Verification

* [ ] Each of the four rows undoes its own last save without reload
* [ ] A refused undo (conflict) shows the refusal in red on the row
* [ ] With photoinfo deactivated, typetags and persons rows show „✓ Gespeichert“ only
* [ ] typetags, persons and photoinfo E2E suites pass
