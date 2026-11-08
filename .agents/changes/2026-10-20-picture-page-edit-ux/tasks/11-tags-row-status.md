---
id: 11
dependencies: []
status: in-progress
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-11
---

# Task 11: Schlagwörter row shows save status

Adding or removing a tag on the picture page shows „Speichert …“ then „✓ Gespeichert“ on the row, and
a failure stays visible in red.

## References

* `design.md#save-model-konzept-b-every-step-saves-itself-visibly`
* `design.md#status-and-errors-stay-on-the-row`
* `design.md#one-look-for-the-row-status`
* `plugins/typetags/include/events_public.inc.php` (inline template and footer script; **submodule**)
* `local/language/de_DE.lang.php` and `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php` if a translated string is touched
* `plugins/typetags/tests/e2e/roles.spec.js`

## Work

* [ ] Characterize the current badge click and error spans in E2E where not yet covered
* [ ] Row status with the shared classes and strings after `addTag`, `removeTag`, `addNewTag`; photoinfo's `tags_message_l10n` and the server error use the red state and stay until the next change
* [ ] The new-tag field saves on Enter and on „+ Hinzufügen“
* [ ] E2E specs updated
* [ ] Commit inside the submodule, push to `github.com/christianbaumann/Piwigo-Colored-Tags`, then bump the submodule pointer

## Verification

* [ ] Clicking „+ Kirche“ shows „✓ Gespeichert“ on the Schlagwörter row, without reload
* [ ] A read-only file shows the red status and it stays until the next tag change
* [ ] typetags E2E and integration suites pass
* [ ] A fresh clone's `git submodule update --init` fetches the new commit
