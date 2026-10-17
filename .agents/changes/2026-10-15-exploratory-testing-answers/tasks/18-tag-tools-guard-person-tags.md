---
id: 18
dependencies:
- 01
- 17
---

# Task 18: Tag tools refuse to remove or delete a person's tag

No tag tool can separate a person's tag from its mark.

## References

* `design.md#tag-tools-refuse-to-remove-or-delete-a-persons-tag`
* decision 0031
* core `set_tags_of`, `del_tags`, `admin/tags.php`, `pwg.tags.delete`, typetags `removeTag`

## Work

* [x] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
  **Note:** Delete, merge, rename, properties replace, Batch Manager `del_tags` and `tag_list` were already pinned (`CoreTagCrudCharacterizationTest`, `CoreTagAssignmentCharacterizationTest`). Added to the latter: `testSetInfoTagIdsReplaceModeRemovesAnUnlistedTag` and `testBatchManagerUnitModeFormReplacesTheTagList`. Both went red with core's `set_tags_of()` turned into an append (DELETE disabled, insert ignore; flip in the container under the suite lock, restored, checksums equal, `git diff` empty); the append-mode control stayed green. typetags' `removeTag` is pinned by its own `RemoveTagTest`. The changed failure branches of core `tags.js` had no test; they are witnessed by the new E2E spec.
* [x] Refuse removal of a person tag from a photo in typetags `x` (also hide `x` on person tags), Eigenschaften, Stapelverarbeitung, `pwg.images.setInfo`, `pwg.tags.*`
  **Note:** `plugins/persons/include/tag_guard.inc.php`. API: core's `ws_invoke_allowed` (before the method runs, so no wrapper chain): `pwg.images.setInfo` (`tag_ids` in replace mode, `tag_list`), `typetags.image.removeTag` (only on a photo the caller sees; otherwise typetags' 404 stays), `pwg.tags.delete`, `pwg.tags.merge` (a person tag as a merged-away source). Answer: `stat: fail`, code 1003 (HTTP 200), message naming the tags. Eigenschaften (`picture_modify_before_update`) and the unit-mode form (`loc_begin_element_set_unit`) put the person tags back into the field and save the rest, with the message, like task 19's last-album rule; global `del_tags` (`loc_begin_element_set_global`) refuses the whole action. The `x`: new typetags filter `typetags_fixed_tag_ids($ids, $image_id)` in `typetags_picture_tags()` takes those ids out of `assignedColoredIds`; persons answers with the photo's marked tags. Pure decisions `persons_marked_tags_removed()`, `persons_split_raw_tags()` in `functions.inc.php`.
* [x] Refuse deleting a person tag in Schlagwortverwaltung; message points to the mark or to Personen
  **Note:** Messages (en/de): removal "... Entfernen Sie die Markierung auf dem Foto ...", delete "... Löschen Sie die Person unter Personen." Core `admin/themes/default/js/tags.js` now shows a refused call's message (single delete, multiple delete, merge); the multiple delete's `stat = 'ok'` assignment, which treated every answer as success, became `===`. Handbook ch4 (*Löschen*, the `x`) and ch5 say so; `check.php` OK. 2026-10-17: German text reviewed by a subagent (user's answer 6a) and reworded: plural messages, delete named as the webmaster's, someone else's mark deleted by an administrator (decision 0065), the merge refusal and the save-the-rest behaviour added to ch4/ch5, *Personen* as `pwgScreen`; `check.php` OK (re-run 2026-10-17).
* [x] Lookup via `persons.tag_id`
  **Note:** Removal: a photo's guarded tags are the `tag_id`s of persons with a Face region on it (the set `persons_sync_image_tags()` keeps). Delete/merge: tags that are a person's own tag (`persons_own_tag_id()`, task 17).
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`
  **Note:** The `tags.js` bullet. The persons and typetags parts are plugin code, not core.

## Deviations

* A person's tag is guarded only on a photo where the person is **marked** (Face region). The same tag put on another photo by hand, without a mark, stays removable (`testAPersonsTagWithoutTheMarkOnThePhotoIsRemovable`): there is no mark to separate it from, and persons' own reindex removes it there anyway. Deleting the tag is refused whenever it is a person's own tag.
* Eigenschaften and the unit-mode form do not refuse the whole save: the person tags stay, everything else is saved, the message is shown (same shape as task 19's properties rule). `pwg.images.setInfo` and global `del_tags` refuse the whole call/action.
* Not guarded, outside the design's list: `pwg.tags.rename` of a person tag (separates the tag from the person's name; task 17 re-creates the person's tag at the next resolve), `pwg.images.uploadAsync` / `pwg.images.add` with `tag_ids` on an existing photo (replace sets the tag list), and photoinfo's `pwg.photoinfo.pruneTags`. Open for the user to decide.
* The Batch Manager's unit mode (JavaScript, through `pwg.images.setInfo`) shows core's generic error badge, not the message: core's `batchManagerUnit.js` shows no server message, and changing it was left out of this core edit.

## Verification

* [x] Integration per path: refused, tag still on the photo, link 200
  **Note:** Verified via persons `TagToolGuardTest` (18 cases): setInfo `tag_ids` replace and `tag_list`, Batch Manager global `del_tags` and unit-mode form, Eigenschaften, `pwg.tags.delete`, `pwg.tags.merge`, `typetags.image.removeTag`, and the picture page's `assignedColoredIds`; each refusal asserts the exact message in the account's language, the tag on the photo and still the person's, and the box label's tag link answering 200 naming the person. The 9 refusal cases ran red before the guard existed (stat `ok`, no message, id present), green after. Unit: `PersonTagGuardTest` (10), red first (undefined functions).
* [x] E2E: no `x` on a person tag; Schlagwortverwaltung shows the message
  **Note:** Verified via persons `tag-guard.spec.js` (normal account: no `x` on the marked person's coloured tag, an `x` on the ordinary coloured tag beside it) and `admin-tag-guard.spec.js` (webmaster: delete through the tag's menu shows the German delete message, the tag box stays after reload; an ordinary tag is deleted). Seed: `seed.php --scenario=overlay --coloured-tags`, removed by name in `--restore`. Both [NEG] specs went red with the typetags filter and the `tags.js` message line reverted (flip in the container under the suite lock, restored, checksums equal).
* [x] Ordinary tags still removable on every path
  **Note:** Verified via the [HAPPY]/[ECP] cases of `TagToolGuardTest` (setInfo replace and `tag_list` by name, append, global `del_tags`, properties, delete, merge into the person's tag, typetags `x`, a person's tag on a photo without the mark) and `admin-tag-guard.spec.js`'s ordinary delete.
* [x] persons, typetags, provenance suites pass
  **Note:** 2026-10-17: unit persons OK (216), typetags OK (91), provenance OK (286). Integration persons OK (209, 1 skipped), typetags OK (179), provenance 363 with 4 failures, all in the untracked `CoreMenuPhotoCountTest` of a parallel agent's task (menu count 114 vs 107), not this task's. E2E persons 166 passed, typetags 66 passed.
