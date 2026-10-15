---
id: 35
dependencies:
- 01
---

# Task 35: Album properties: move errors show, no sub-albums in the picker

The album properties page never reports a save that the server refused.

## References

* `design.md#album-properties-move-errors-show-sub-albums-are-not-offered`
* `admin/themes/default/js/cat_modify.js:70-155`, `album_selector.js`

## Work

* [ ] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [ ] Wait for `setInfo` and `move`; show the move error
* [ ] Parent picker excludes the album's own sub-albums
* [ ] "Just now" shown once
* [ ] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [ ] E2E: own sub-album not offered; a refused move shows the error
* [ ] E2E: info box shows the German text once (string from task 24, English until it lands)
* [ ] provenance suites pass
