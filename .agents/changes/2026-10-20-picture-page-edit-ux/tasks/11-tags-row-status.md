---
id: 11
dependencies: []
status: done
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

* [x] Characterize the current badge click and error spans in E2E where not yet covered
* [x] Row status with the shared classes and strings after `addTag`, `removeTag`, `addNewTag`; photoinfo's `tags_message_l10n` and the server error use the red state and stay until the next change
* [x] The new-tag field saves on Enter and on „+ Hinzufügen“
* [x] E2E specs updated
* [ ] Commit inside the submodule, push to `github.com/christianbaumann/Piwigo-Colored-Tags`, then bump the submodule pointer

## Verification

* [x] Clicking „+ Kirche“ shows „✓ Gespeichert“ on the Schlagwörter row, without reload **Note:** `row-status.spec.js` HAPPY case (delays the `addTag` answer so „Speichert …“ is seen first).
* [x] A read-only file shows the red status and it stays until the next tag change **Note:** `row-status.spec.js` ERR case: only the red warning, no green status, after the first and second add and after 1.5 s idle.
* [x] typetags E2E and integration suites pass **Note:** Verifier on the fix commit: typetags unit `OK (91 tests, 33103 assertions)`, E2E row-status and failed-saves specs 13 passed. Implementer on the same code: typetags E2E 69 passed, integration `OK (179 tests, 792 assertions)`; provenance `RoleMatrixTest` `OK (66 tests)`.
* [ ] A fresh clone's `git submodule update --init` fetches the new commit **Note:** NOT VERIFIED: the submodule commits are unpushed, by this session's no-push instruction, so a fresh clone cannot fetch the pinned commit yet.

## Implementation notes

Open: push `plugins/typetags` (c48112b, 6419cf9) to `github.com/christianbaumann/Piwigo-Colored-Tags`. The submodule commits are on the main checkout's `plugins/typetags` master, ahead of origin.

Deviations: the status span sits inside the `#typetags-new-tag` form below the info box, not in the `#Tags` row, because that row is absent on a photo with no tags. Four typetags-specific de_DE keys were added; `.claude/rules/handbook.md`'s count is now 39. The button text „+ Hinzufügen“ is the changed `Add the tag` translation. While the next change is saving, the old red warning stays beside „Speichert …“ until the answer.

A prefilter's injected template changed, so an install running this needs `_data/templates_c` cleared once.
