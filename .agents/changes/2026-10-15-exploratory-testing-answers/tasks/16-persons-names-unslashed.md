---
id: 16
dependencies: []
---

# Task 16: persons: names unslashed once, escaped at every SQL use

Any person name, quotes included, saves once and never blocks a photo.

## References

* `design.md#person-names-are-unslashed-once-escaped-at-every-sql-use`
* `plugins/persons/include/index.inc.php:334-367`, `:105`, `:615`, `:732-746`
* `plugins/persons/include/ws_functions.inc.php`

## Work

* [ ] `stripslashes` the name once in `pwg.persons.addRegion` and `pwg.persons.rename`
* [ ] Escape the name for the SELECT, `tag_id_from_tag_name()` and `single_insert()` in `persons_person_id_from_name()`
* [ ] A reindex error becomes a ws error, not a fatal
* [ ] Repair photos 100, 103, 104, 105 once through the fixed `pwg.persons.rename`; record the step

## Verification

* [ ] Unit: `D'Arcy "R" & Töchter` resolves to one row
* [ ] Integration: two saves with that name, one person, file holds the name without backslashes
* [ ] photoedit crop refusal shows the name without backslashes
* [ ] persons and photoedit suites pass
