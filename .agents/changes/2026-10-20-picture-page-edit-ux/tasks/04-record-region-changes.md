---
id: 04
dependencies:
- 01
status: in-progress
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-04
---

# Task 04: Record region changes

persons announces every added or deleted region through a new event, and photoinfo records it as a
change-log entry; persons itself gains no provenance dependency.

## References

* `design.md#photoinfo-writes-the-rows-persons-announces-region-changes`
* `design.md#values-of-tag-and-region-entries`
* `plugins/persons/include/ws_functions.inc.php` (`ws_persons_addRegion()`, `ws_persons_deleteRegion()`)
* `plugins/persons/include/index.inc.php` (`persons_apply_change()`)
* `plugins/photoinfo/main.inc.php`, `plugins/photoinfo/include/events_tags.inc.php`

## Work

* [ ] Fire `trigger_notify('persons_regions_changed', $image_id, $added, $removed)` from the add and delete region paths only, not from `pwg.persons.rescan`, reindexing or photoedit's region adjustment
* [ ] Each region carries name, box and `drawn_by`
* [ ] photoinfo listens and records one `region` entry per region, value `{"name","box","drawn_by"}`
* [ ] Return the entry id in the addRegion / deleteRegion answers when photoinfo recorded one (`history_id`, through a `trigger_change` or the answer filter persons already uses)
* [ ] Add the event to `tools/triggers_list.php`-style docs if plugin events are listed there; otherwise document it in persons' header comment
* [ ] Integration tests: add, delete, rescan `[NEG]`; persons suite passes with photoinfo inactive

## Verification

* [ ] Naming a new box writes one `region` entry with name, box and drawer
* [ ] Deleting a box writes one entry with the old value
* [ ] `pwg.persons.rescan` writes nothing
* [ ] persons works unchanged with photoinfo deactivated
* [ ] persons and photoinfo integration suites pass
