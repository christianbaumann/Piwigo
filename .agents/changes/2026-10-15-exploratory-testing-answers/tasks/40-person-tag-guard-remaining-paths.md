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
