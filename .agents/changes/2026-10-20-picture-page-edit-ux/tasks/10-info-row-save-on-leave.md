---
id: 10
dependencies: []
status: in-progress
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-10
---

# Task 10: Info row saves on leaving the field, without reload

The info text opens with „✎ ändern“, saves when the field is left or on „Fertig“, shows „noch nicht
gespeichert“ while dirty with a leave warning, and never reloads the page.

## References

* `design.md#no-timed-autosave`
* `design.md#no-page-reload-after-a-save`
* `design.md#status-and-errors-stay-on-the-row`
* `design.md#one-look-for-the-row-status`
* `plugins/photoinfo/template/info.js`, `public_info.tpl`, `info.css`
* `plugins/photoinfo/include/ws_functions.inc.php` (`ws_photoinfo_setInfo()`)
* `plugins/photoinfo/tests/e2e/info-edit.spec.js`

## Work

* [ ] Characterize current info editing first where not yet covered
* [ ] Visible „✎ ändern“; Speichern/Abbrechen become „Fertig“
* [ ] Save on blur and on Fertig, never on a timer; skip an unchanged value
* [ ] „noch nicht gespeichert“ while dirty; `beforeunload` attached only while dirty and removed after save
* [ ] Return the displayed info text from `ws_photoinfo_setInfo()` and update the row from it; remove `window.location.reload()`
* [ ] Row status with the shared classes and strings, including the red partial-write state
* [ ] E2E spec updated

## Verification

* [ ] Typing shows „noch nicht gespeichert“; leaving the field saves once and shows „✓ Gespeichert“ with no reload
* [ ] Navigating away while dirty triggers the browser's leave warning; after saving it does not
* [ ] No request is sent while typing
* [ ] photoinfo E2E and integration suites pass
