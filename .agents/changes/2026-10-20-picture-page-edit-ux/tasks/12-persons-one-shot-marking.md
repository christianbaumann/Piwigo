---
id: 12
dependencies: []
status: done
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

* [x] Characterize the current editor flow in E2E where not yet covered
* [x] Replace the start/stop toggle with „+ Person markieren“ in the Personen row; after a name is chosen, or on Esc, the drawing state ends
* [x] × next to each name in the Personen row for regions `persons_may_delete_region()` allows; pointing at or focusing a name highlights its box; no × on the photo
* [x] Update the Personen row and boxes in place from the ws answers; remove `reloadOnExit`
* [x] Row status with the shared classes and strings
* [x] Adjust photoedit's hand-off (`enter()` no longer clicks a toggle)
* [x] E2E specs updated, including `roles.spec.js`

## Verification

* [x] One press of „+ Person markieren“, a drag and a name save one box; the button is available again and the page did not reload **Note:** `editor.spec.js` and `editor-marking.spec.js` one-shot cases; `admin.spec.js` „no reload“ cases.
* [x] Esc during drawing cancels without a request **Note:** `editor.spec.js` „Esc removes the drawn box and writes nothing“ (nothing reaches the file).
* [x] A normal account sees × only on names of boxes it drew; an administrator on all **Note:** `editor-marking.spec.js` „a box nobody drew“ for admin and normal, „a box just added shows its x at once“, and `roles.spec.js`; the server's check in `ws_persons_deleteRegion` is unchanged (`DeleteRegionTest`, `RoleMatrixTest`).
* [x] Starting Drehen/Zuschneiden while drawing ends drawing cleanly **Note:** photoedit `edit-mode.spec.js` „[ST] entering edit mode ends persons drawing first“. It starts drawing but does not open the name picker with a box drawn; that variant has no spec.
* [x] persons and photoedit E2E suites pass **Note:** Implementer: persons E2E 189/191 (two admin-role `editor-marking` cases timed out on a slow save and passed on rerun), photoedit E2E 34/34, persons integration `OK (226, 1 skipped)`, photoedit integration `OK (134)`. Verifier: `editor`, `editor-marking`, `roles` 85 passed; `edit-mode` 12 passed. After the rebase onto task 04: persons `AddRegionTest|DeleteRegionTest|RoleMatrixTest|PicturePageSourceTest` `OK (77 tests)`, photoinfo `RegionRecordingTest|HistoryListTest` `OK (17 tests)`, persons `editor`/`editor-marking` E2E 80 passed, photoinfo `history-list` E2E 6 passed.

## Implementation notes

- „+ Person markieren“ (`#persons-tag-add`) sits in the Personen row; when that row is off, and on the admin tagging screen, the names and the button sit in `#persons-editor` (`persons_force_controls`). The row is always shown, since it holds the button.
- `pwg.persons.getRegions`, `addRegion` and `deleteRegion` regions carry `url` and `deletable`; the row and the boxes are rebuilt from the answers (`facesFromRow()`, `faces` in `editor.js`). `reloadOnExit` is gone.
- photoedit's `enter()` dispatches the `document` event `persons:cancel`, which persons handles by ending the drawing; photoedit's `editor.css` hides `#persons-tag-add` and `.persons-name-delete` in edit mode. The event is documented only in a comment.
- A name with several boxes needs one × press per box.
- Removed specs and their successors are listed in the verifier's report: the toggle label, the reload-on-exit cases, the box × cases (the × is in the row now) and photoedit's „reloads instead of opening“.
- A save writes the file twice (the region, then photoinfo's keywords) under the persons lock, so „Speichert …“ can stay for a few seconds under load.
- After a crash, an empty `plugins/persons/tests/e2e/.state/snapshot.json` makes every seed fail; delete it.
- For task 14: `handbuch/tools/shoot.js` lines 653, 665 and 679 click `#persons-tag-toggle`, now `#persons-tag-add`; `persons-tagging` is set only while one box is drawn.
