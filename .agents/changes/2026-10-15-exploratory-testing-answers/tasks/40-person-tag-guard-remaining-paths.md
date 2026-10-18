---
id: 40
dependencies:
- 18
---

# Task 40: The person tag guard covers rename, upload, prune and the Batch Manager's message

Added 2026-10-17 from task 18's Deviations (user's answers 1a, 2a, 3a). No path separates a marked person's tag from its mark, and every refusal shows its reason.

## References

* `design.md#tag-tools-refuse-to-remove-or-delete-a-persons-tag`
* Task 18 (Deviations), `plugins/persons/include/tag_guard.inc.php`
* core `admin/themes/default/js/batchManagerUnit.js`, `.claude/rules/fork-local-core-edits.md`

## Work

* [ ] Refuse `pwg.tags.rename` of a person's own tag; the message points to Personen
* [ ] Keep a marked person's tag on `pwg.images.add` / `pwg.images.uploadAsync` with `tag_ids` on an existing photo, and on `pwg.photoinfo.pruneTags`
* [ ] Batch Manager unit mode shows the server's refusal message (fork-local edit to core `batchManagerUnit.js`; characterize first, own commit; bullet in `.claude/rules/fork-local-core-edits.md`)
* [ ] Extend persons' `RoleMatrixTest` / guard method list where levels or methods change

## Verification

* [ ] Integration per path: refused or tag kept, tag still on the photo, name unchanged
* [ ] E2E: Batch Manager unit mode shows the message
* [ ] persons, photoinfo, typetags suites pass

## WIP (paused 2026-10-18)

* Nothing changed yet: no tracked or untracked file edited, no flip, no seed, no scratchpad copies. All four Work items stay `[ ]`.
* Read so far: `plugins/persons/include/tag_guard.inc.php` (guard hooks via `ws_invoke_allowed`), decision 0084, `UploadMergeTest`, `TagToolGuardTest`. Core `ws_images_add` (pwg.images.php ~1523) and `ws_images_uploadAsync` (~2219) call `set_tags(explode(',', tag_ids), $image_id)` after `add_uploaded_file()`; `set_tags_of` fires no event, so `loc_end_add_uploaded_file` (runs before set_tags) cannot restore the tag.
* Next step: decide the mechanism without touching pwg.images.php (task 47 edits it): either extend `persons_guard_ws_invoke` so these two methods (with `tag_ids` and an existing `image_id`) have the person tags merged into `$_REQUEST['tag_ids']` before the method reads params (check where `$params` is built), or a minimal `tag_ids` edit in core. Rename: add `pwg.tags.rename` to the guard with `persons_own_tags_among`, new message pointing to Personen (en/de). Prune: guard in photoinfo/persons for `pwg.photoinfo.pruneTags`. Then failing tests first in `TagToolGuardTest`, RoleMatrixTest/guard list, `batchManagerUnit.js` message with characterization commit first.
