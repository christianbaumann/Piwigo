---
id: 06
dependencies: []
---

# Task 06: picture page warns when a tag was not written into the file

When a tag change on the picture page is saved but photoinfo cannot write it into the photo file, the visitor sees the German reason next to the tag field.

## References

* `design.md#b8-warning-on-a-failed-tag-write-picture-page-only`
* `plugins/photoinfo/tests/e2e/tags-freitext.spec.js` (`[NEG] the picture page says the tag was not written into the file`, skipped)
* `plugins/photoinfo/include/events_core_edit.inc.php` (`photoinfo_tag_write_answer()`)
* `plugins/photoinfo/include/events_tags.inc.php` (`photoinfo_call_and_write()`, the three typetags wrappers)
* `plugins/photoinfo/include/ws_functions.inc.php:53` (`message_l10n` via `photoinfo_l10n_reason()`)
* `plugins/typetags/include/events_public.inc.php` (inline script: `addTag`, `removeTag`, `addNewTag`)

## Work

* [ ] Add a failing photoinfo integration test: a failed tag write's answer carries `tags_message_l10n` with the German text; a successful one does not change shape
* [ ] Add `tags_message_l10n` in `photoinfo_tag_write_answer()`
* [ ] Un-skip the E2E spec; extend it, or add cases, for `addTag` and `removeTag`
* [ ] In typetags' picture-page script, show `tags_message_l10n` after each of the three calls when `tags_written === false`; keep the tag change itself (database saved) and clear the warning on the next successful change
* [ ] Commit in the submodule, push, commit the pointer; move the backlog item to "admin tag screen only"

## Verification

* [ ] Adding a new tag to a read-only photo shows photoinfo's German not-writable text; the badge still appears
* [ ] The same for adding an existing tag and removing one
* [ ] A successful write shows no warning
* [ ] Without photoinfo active, typetags' page behaves as before (no field, no warning)
* [ ] photoinfo and typetags suites pass
