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

* [x] Add a failing photoinfo integration test: a failed tag write's answer carries `tags_message_l10n` with the German text; a successful one does not change shape
  **Note:** `TagWriteTest::testTheTypetagsCallsBringTheFailureInGerman` (all three typetags calls on a read-only file; watched red: `null` instead of the German text) and `testASuccessfulTypetagsCallBringsNoReason` (no `tags_message_l10n` key on success)
* [x] Add `tags_message_l10n` in `photoinfo_tag_write_answer()`
  **Note:** only on a failure. `photoinfo_l10n_reason()` moved from `ws_functions.inc.php` to `writer.inc.php`: `events_core_edit.inc.php` does not load `ws_functions.inc.php`, both load `writer.inc.php`
* [x] Un-skip the E2E spec; extend it, or add cases, for `addTag` and `removeTag`
  **Note:** three `[NEG]` cases in `tags-freitext.spec.js` under "a failed tag write on the picture page"; new `seed.php --writable=<id>` (`makeWritable()`) so the removeTag case can show the next written change clearing the warning; `PicturePage.addTag()` / `removeTag()` / `tagWarning`
* [x] In typetags' picture-page script, show `tags_message_l10n` after each of the three calls when `tags_written === false`; keep the tag change itself (database saved) and clear the warning on the next successful change
  **Note:** `showTagWrite()` and a separate `.typetags-tag-warning` span in `#typetags-new-tag`, beside the existing save-error span. Prefilter code changed: compiled templates purged locally; the remote needs the hand purge after deploy (`deployment.md`)
* [x] Commit in the submodule, push, commit the pointer; move the backlog item to "admin tag screen only"
  **Note:** backlog item narrowed to the admin tag screen; handbook `04-schlagworte.html` no longer says the picture page shows no message; TESTING.md B8 row and `plugin-test-suites.md` (seed options) updated

## Verification

* [x] Adding a new tag to a read-only photo shows photoinfo's German not-writable text; the badge still appears
  **Note:** Verified via `tags-freitext.spec.js` "[NEG] the picture page says the new tag was not written into the file"
* [x] The same for adding an existing tag and removing one
  **Note:** Verified via the `[NEG][ST]` removeTag and `[NEG]` addTag cases; one layer down `TagWriteTest::testTheTypetagsCallsBringTheFailureInGerman` covers all three answers. Mutants 2026-10-11: warning condition forced false killed all three specs; clearing branch removed killed only the `[ST]` case
* [x] A successful write shows no warning
  **Note:** Verified via the `[NEG][ST]` case (no warning after the first written add; warning hidden again after the next written add) and `TagWriteTest::testASuccessfulTypetagsCallBringsNoReason`
* [x] Without photoinfo active, typetags' page behaves as before (no field, no warning)
  **Note:** Verified ad hoc 2026-10-11: photoinfo deactivated through `pwg.plugins.performAction`, typetags E2E 45/45 passed (incl. "the assignment UI initialises with no console or page errors"), photoinfo reactivated and confirmed active. Not kept as a regression test: it would need a typetags spec to deactivate photoinfo
* [x] photoinfo and typetags suites pass
  **Note:** 2026-10-11: photoinfo unit 268, integration 181, E2E 57; typetags unit 84, integration 114, E2E 45; all green. `tags-freitext.spec.js` re-run green after the review fixes

## Open for the owner

* [ ] (manual testing required) Wording: the page shows the bare reason ("Die Datei fehlt oder ist nicht beschreibbar"), as the design specifies; the date and info editors say "Gespeichert, aber nicht in die Bilddatei geschrieben: %s". Keep the bare reason, or use the same prefix?
* [ ] (manual testing required) German reading of the changed handbook sentence in `handbuch/04-schlagworte.html` (*Schlagworte in der Bilddatei*)
* [ ] (manual) After the next deploy, purge the remote's compiled templates (the typetags picture prefilter's code changed)
