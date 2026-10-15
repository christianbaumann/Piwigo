---
id: 36
dependencies:
- 01
---

# Task 36: API checks and sync counts

Each API answer and sync counter agrees with what the server stored.

## References

* `design.md#api-checks-and-counts`

## Work

* [ ] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [ ] `pwg.categories.getList` returns the substitute's id as `representative_picture_id`
* [ ] `pwg.images.setInfo` refuses a level outside `available_permission_levels`
* [ ] `admin/site_update.php`: errors counted after the metadata pass; photos of removed folders counted as deleted
* [ ] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [ ] Integration: first `getList` after an admin change names no hidden photo
* [ ] Integration: level 3 and 5 refused, row unchanged
* [ ] Integration: counter equals listed errors; removed folders report their photos, simulation and real
* [ ] provenance suites pass
