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

* [x] Refuse `pwg.tags.rename` of a person's own tag; the message points to Personen
* [x] Keep a marked person's tag on `pwg.images.add` / `pwg.images.uploadAsync` with `tag_ids` on an existing photo, and on `pwg.photoinfo.pruneTags`
* [x] Batch Manager unit mode shows the server's refusal message (fork-local edit to core `batchManagerUnit.js`; characterize first, own commit; bullet in `.claude/rules/fork-local-core-edits.md`)
* [x] Extend persons' `RoleMatrixTest` / guard method list where levels or methods change
  **Note:** nothing to extend: no persons method or role level changed; the refusals extend `pwg.tags.rename` (a wrapped core method, not in the matrix) and wrap two core methods

## Verification

* [x] Integration per path: refused or tag kept, tag still on the photo, name unchanged
  **Note:** Verified via `TagToolGuardTest` (rename, uploadAsync, images.add, pruneTags and their [HAPPY]/[ECP] siblings)
* [x] E2E: Batch Manager unit mode shows the message
  **Note:** Verified via `admin-batch-unit-guard.spec.js` (flipping the one JS line made it red)
* [x] persons, photoinfo, typetags suites pass
  **Note:** persons unit 218 OK, integration 224 tests (1 skipped, pre-existing), E2E 187 passed (one `admin-tag-guard` HAPPY flake, green alone); photoinfo unit 288 + integration 230 OK (after the fix below); typetags unit 91 + integration 179 OK

## Deviations

* No core edit for the uploads, as decided 2026-10-18. `ws_invoke_allowed` receives `$params` by value and `ws.php` builds them before it fires, so the guard cannot change `tag_ids` there. Persons instead re-registers `pwg.images.add` and `pwg.images.uploadAsync` around core's callbacks (`persons_wrap_upload_methods()`, `ws_add_methods` at neutral + 10, the pattern photoinfo uses for `setInfo`); the wrapper adds the marked persons' tags to a non-empty `tag_ids` of an existing photo. Empty `tag_ids` stay empty (core sets no tags then).
* The prune keeps a marked person's tag through a new photoinfo filter `photoinfo_prune_keeps_tag_ids` (photoinfo has no dependency on persons); persons answers it with the photo's marked tags. One line in `photoinfo_prune_images()`.
* Batch Manager message: shown in a `<p class="local-error-message">` created by `showErrorLocalBadge()` inside the error badge, so no template edit.
* `RoleMatrixTest` unchanged (see Work item 4).
* Not covered: `pwg.images.addSimple`/`addFile` (no `tag_ids`), so no test.
* Fallout fixed in photoinfo: an upload whose file carries the keyword marker prunes the tags the file does not name, then rewrote the file only when persons restored a tag. With the prune now keeping a marked person's tag, nothing was restored and the file lacked the person's keyword (photoinfo's `UploadMergeTest::testAMarkedKeywordFileDoesNotTakeAMarkedPersonsTagOff` went red). `photoinfo_merge_uploaded_tags()` (`events_upload.inc.php`) now also rewrites the file when the prune left a tag the file does not name.
