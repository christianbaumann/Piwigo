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

* [x] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [x] Wait for `setInfo` and `move`; show the move error
* [x] Parent picker excludes the album's own sub-albums
* [x] "Just now" shown once
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [x] E2E: own sub-album not offered; a refused move shows the error
  * **Note:** Verified via `album-properties.spec.js` (search and browsable list cases, refused move case); each went red against the pre-change `album_selector.js` / `cat_modify.js` and green after.
* [x] E2E: info box shows the German text once (string from task 24, English until it lands)
  * **Note:** Verified via `album-properties.spec.js` "just now once": compares against the page's own `str_just_now` (German `Gerade eben` from task 24), card holds it once, subcontent empty; red against old `cat_modify.js`.
* [x] provenance suites pass
  * **Note:** Verified via unit (307 OK), integration (387 tests, 3 skipped, OK) and a full Playwright run (71 passed, 5 failed, all in the untracked `long-words.spec.js` another agent is writing for the modus wrap task; not touched by this task).

## Deviations

* The second line of the "Modified" card is emptied after a save instead of showing a date; the design only asked for "Just now" once.
* A refused move also puts the parent field back to the saved parent (not in the design; otherwise the page keeps showing a parent the server refused).
* The save now runs `setInfo`, then `move`, in sequence (the design said "wait for both"); a failed `setInfo` skips the move.
* No decision file: nothing debatable beyond the design's.
* `pwg.categories.getAdminList`'s non-recursive `id_uppercat` mutant (line 625; the same text also occurs in `getList`) was run in verification, flipping `=` to `<>` by line number: `testTheAdminListCarriesTheChainAndTheChildCountThePickerUses` went red (child not listed), file restored.
