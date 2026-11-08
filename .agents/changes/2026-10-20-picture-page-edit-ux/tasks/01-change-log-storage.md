---
id: 01
dependencies: []
status: todo
owner:
branch:
---

# Task 01: Change log storage for picture-page edits

provenance's history table can hold date, info, tag, region and photo-edit entries with their sources,
undo entries can point at what they undo, and non-admins can read the history of photos they see.

## References

* `design.md#change-log-extend-provenances-history`
* `design.md#reading-the-change-list-over-the-api`
* `design.md#undone-entries`
* `design.md#values-of-tag-and-region-entries`
* `plugins/provenance/include/history.inc.php` (`provenance_history_row()`, `provenance_record_changes()`, `provenance_fetch_history()`)
* `plugins/provenance/include/functions.inc.php` (`provenance_history_sources()`, field/source label keys)
* `plugins/provenance/maintain.class.php` (`install()`, `widen_source_enum()`)
* `plugins/provenance/main.inc.php` (`pwg.provenance.getHistory`)
* `.claude/rules/plugin-test-suites.md`, `.claude/rules/testing.md`

## Work

* [ ] Add field constants `date`, `info`, `tag`, `region`, `photo` and one source per edit path (picture page, admin photo screen, Batch Manager, API, undo); widen the source enum through `maintain.class.php`
* [ ] Add nullable column `undoes_id` (with index) to the history table in `install()`/`update()`; `provenance_record_changes()` accepts and writes it; `provenance_fetch_history()` returns it and whether an entry is undone
* [ ] Add pure helpers that encode/decode the JSON values of tag (`{"id","name"}`) and region (`{"name","box","drawn_by"}`) entries
* [ ] Drop `admin_only` from `pwg.provenance.getHistory`; refuse guests; allow a non-admin only an album or photo it can see (same check as the read-only page)
* [ ] Unit tests for the new row shaping and JSON helpers; integration tests for the schema update on an existing install, `undoes_id` round trip, and getHistory per role
* [ ] Update provenance's `RoleMatrixTest` rows for getHistory
* [ ] Record decision „the change log extends provenance's history“ in `docs/agents/decisions/` (next free number at commit time)

## Verification

* [ ] An install updated from the previous plugin version has the `undoes_id` column and keeps its existing rows
* [ ] An entry with `undoes_id` is written and read back; the target reads as undone
* [ ] A normal account reads the history of a photo it can see and is refused for a photo in a private album without grant; a guest is refused
* [ ] Existing provenance entries and the read-only page render as before
* [ ] provenance unit and integration suites pass (commands in `.claude/rules/plugin-test-suites.md`)
