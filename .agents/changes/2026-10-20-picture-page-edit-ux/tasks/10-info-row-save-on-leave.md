---
id: 10
dependencies: []
status: done
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

* [x] Characterize current info editing first where not yet covered
* [x] Visible „✎ ändern“; Speichern/Abbrechen become „Fertig“
* [x] Save on blur and on Fertig, never on a timer; skip an unchanged value
* [x] „noch nicht gespeichert“ while dirty; `beforeunload` attached only while dirty and removed after save
* [x] Return the displayed info text from `ws_photoinfo_setInfo()` and update the row from it; remove `window.location.reload()`
* [x] Row status with the shared classes and strings, including the red partial-write state
* [x] E2E spec updated

## Verification

* [x] Typing shows „noch nicht gespeichert“; leaving the field saves once and shows „✓ Gespeichert“ with no reload **Note:** `info-edit.spec.js` [HAPPY] asserts the dirty status, exactly one request on leaving and a `window` marker that survives; verifier's full E2E run on the rebased state: 67 passed.
* [ ] Navigating away while dirty triggers the browser's leave warning; after saving it does not (manual testing required) **Note:** Automated part: `info-edit.spec.js` [ST] dispatches `beforeunload` and asserts it is armed only while dirty. Chromium shows the native dialog only after a user gesture, so the real prompt needs a look in a browser.
* [x] No request is sent while typing **Note:** `info-edit.spec.js` [HAPPY] asserts zero `setInfo` requests before the blur.
* [x] photoinfo E2E and integration suites pass **Note:** Verifier, rebased state: photoinfo unit `OK (288 tests, 537 assertions)`, integration `OK (233 tests, 1366 assertions)`, E2E `67 passed`.

## Implementation notes

- `info_html` in the ws answer is `nl2br(htmlspecialchars())`, not core's `render_element_description`, because galleryrules' handler returns the text unchanged under `IN_WS`. A reload gives the same result (galleryrules escapes, core's `pwg_nl2br` adds the breaks), checked by reading the code. Only the text "0" differs: the in-place row shows it, a reload shows the placeholder.
- No separate characterization commit: `info-edit.spec.js` and `SetInfoTest` already covered open, save, cancel and the unwritable file. The verifier accepted this.
- A click on the view text still opens the Info editor beside the new „✎ ändern“; the Datum row (task 09) dropped click-on-text.
