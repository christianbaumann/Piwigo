---
id: 07
dependencies:
- 02
---

# Task 07: Normal users delete only regions they drew

Each region records who drew it; a normal user can delete only their own regions, admins and webmasters can delete any.

## References

* `design.md#regions-record-who-drew-them-in-the-index-carried-like-the-id`
* `plugins/persons/include/functions.inc.php` `persons_region_columns()`, `persons_carry_region_ids()`
* `plugins/persons/maintain.class.php` (`add_missing_columns()`)
* `plugins/persons/include/ws_functions.inc.php` `ws_persons_addRegion`, `ws_persons_deleteRegion`
* `docs/agents/decisions/0019-person-region-permission-model.md`, `0020-...`, `0033-region-ids-survive-a-reindex.md`

## Work

* [ ] Add a nullable `drawn_by` column to the region table; `update()` adds it to existing installs
* [ ] `addRegion` stores the caller's id; the reindex carries `drawn_by` together with the id
* [ ] `deleteRegion` lets a normal user delete only a region whose `drawn_by` is their id; admin and webmaster delete any
* [ ] Picture page: offer the delete control to a normal user only on their own regions
* [ ] Unit test for the carry and the permission decision; integration and E2E for own / foreign / nobody's region, as `persons_normal`, `persons_admin`, `persons_webmaster`
* [ ] Decision file refining 0019 and 0020

## Verification

* [ ] A region drawn by `persons_normal` can be deleted by `persons_normal`
* [ ] A region drawn by another user, or with empty `drawn_by`, is refused for `persons_normal` and the file is unchanged
* [ ] `persons_admin` and `persons_webmaster` delete any region
* [ ] After a write that reindexes the photo, `drawn_by` of unchanged regions is preserved
* [ ] After `IndexRebuildTest`'s full rebuild, all `drawn_by` values are empty (fails closed)
* [ ] Persons unit, integration and E2E suites pass
