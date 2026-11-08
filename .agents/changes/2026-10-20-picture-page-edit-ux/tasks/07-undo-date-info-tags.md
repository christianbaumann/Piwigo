---
id: 07
dependencies:
- 02
- 03
- 06
status: todo
owner:
branch:
---

# Task 07: Undo for date, info and tag entries

A user undoes their own date, info or tag change from the change list, an administrator anyone's;
undo is refused when the value changed since.

## References

* `design.md#undo-runs-on-the-server`
* `design.md#who-may-undo`
* `design.md#conflicts-undo-rows-no-redo`
* `design.md#undone-entries`
* `plugins/photoinfo/main.inc.php` (`photoinfo_add_methods()`)
* `plugins/photoinfo/include/ws_functions.inc.php`, `events_tags.inc.php` (the write paths undo reuses)

## Work

* [ ] New method `pwg.photoinfo.undo(history_id, pwg_token)`: guest refused, token checked, photo visibility checked
* [ ] Pure rule functions: may undo (own entry, or `is_admin()`); is current (current value equals the entry's new value); is undoable (not an undo entry, not undone, not a `photo` entry)
* [ ] Apply the inverse through the same functions as the original write (file write, lock); write an undo entry with `undoes_id` and source undo
* [ ] Refusal messages: „Wurde inzwischen von X geändert“, not allowed, already undone
* [ ] Rückgängig links in the change list (task 06) for entries the viewer may undo; refresh after undo
* [ ] Unit tests for the three rules as a decision table `[DT]`; integration tests per field, conflict `[NEG]`, rights per role; photoinfo `RoleMatrixTest` row `undo`
* [ ] Record decision „undo rights: own changes for everyone, all changes for administrators“

## Verification

* [ ] A normal account undoes its own tag add; the tag is gone from the photo and its file, the list shows the undo entry
* [ ] A normal account sees no Rückgängig on another account's entry and the method refuses it
* [ ] An administrator undoes another account's info change
* [ ] Undo after a later change of the same field is refused with the user's name
* [ ] photoinfo suites pass, including the method guard of `RoleMatrixTest`
