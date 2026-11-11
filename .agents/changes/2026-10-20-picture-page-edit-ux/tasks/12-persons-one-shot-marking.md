---
id: 12
dependencies: []
status: in-progress
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-12
---

# Task 12: Personen row: one-shot marking, delete from the row

„+ Person markieren“ draws and names one box and then ends by itself; names in the Personen row carry
the × for regions the viewer may delete; the page never reloads.

## References

* `design.md#persons-one-shot-marking-delete-from-the-personen-row`
* `design.md#no-page-reload-after-a-save`
* `design.md#one-look-for-the-row-status`
* `prototype/picture-edit-concepts.html` (Konzept B)
* `plugins/persons/template/editor.js` (`#persons-tag-toggle`, `reloadOnExit`), `public_editor.tpl`, `public_persons.tpl`, `editor.css`, `overlay.js`
* `plugins/persons/include/functions.inc.php` (`persons_may_delete_region()`)
* `plugins/photoedit/template/editor.js` (leaves persons tagging before it starts)
* `plugins/persons/tests/e2e/editor.spec.js`, `editor-marking.spec.js`, `roles.spec.js`

## Work

* [ ] Characterize the current editor flow in E2E where not yet covered
* [ ] Replace the start/stop toggle with „+ Person markieren“ in the Personen row; after a name is chosen, or on Esc, the drawing state ends
* [ ] × next to each name in the Personen row for regions `persons_may_delete_region()` allows; pointing at or focusing a name highlights its box; no × on the photo
* [ ] Update the Personen row and boxes in place from the ws answers; remove `reloadOnExit`
* [ ] Row status with the shared classes and strings
* [ ] Adjust photoedit's hand-off (`enter()` no longer clicks a toggle)
* [ ] E2E specs updated, including `roles.spec.js`

## Verification

* [ ] One press of „+ Person markieren“, a drag and a name save one box; the button is available again and the page did not reload
* [ ] Esc during drawing cancels without a request
* [ ] A normal account sees × only on names of boxes it drew; an administrator on all
* [ ] Starting Drehen/Zuschneiden while drawing ends drawing cleanly
* [ ] persons and photoedit E2E suites pass

## Blocked (2026-11-08)

Docker Desktop stopped because the host disk was full. Committed on the task branch (`fe4d26b09`), not verified. Persons integration green (226 tests, 1 skipped). Persons E2E first full run: 164 passed, 27 failed; the fixes for those are committed but never run. Photoedit E2E was never run. For task 14: `handbuch/tools/shoot.js` lines 653, 665, 679 click `#persons-tag-toggle`, now `#persons-tag-add`.
