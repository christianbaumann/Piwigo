---
id: 10
dependencies: []
---

# Task 10: typetags: failed saves are reported and undone

Every failed tag or colour save shows its reason and leaves the page in the saved state.

## References

* `design.md#-and-x-report-a-failed-save`
* `design.md#german-messages-give-the-reason`
* `design.md#schlagwort-farben-festlegen-reports-a-failure`
* `plugins/typetags/include/events_public.inc.php`, `template/tags.js:97-226`

## Work

* [x] `+` and `x`: on failure show the typing path's message with the reason, revert the badge
  **Note:** The badge only moves on success, so a failure leaves it where it was (the saved state);
  the reason goes into the typing path's `.typetags-new-tag-error`. HTTP 4xx answers now give their
  reason on the typing path too (before: always "Das Schlagwort wurde nicht gespeichert").
* [x] A 401 gives "Sitzung abgelaufen – bitte neu anmelden" (override or plugin string, row in `GermanOverrideKeyTest` if an override)
  **Note:** Override `Session expired - please sign in again` in `local/language/de_DE.lang.php`
  (the picture page does not load the plugin's language file), row added to
  `GermanOverrideKeyTest`; `The tag was not saved` row now 1 occurrence, `escape:html`. Both strings
  sit as `data-` attributes on the error span. `handbook.md` count 25 -> 26 keys.
* [x] festlegen: catch shows the error, removes `loading`, hides an old "Farbe gespeichert"; `stat:"fail"` counts as failure
  **Note:** New plugin string `The color was not saved` (de_DE/en_UK); the create button's catch
  reports the same way.
* [x] Submodule commit, pushed
  **Note:** typetags `ac55c5b`. Push refused (403, see task 09); local only.

## Deviations

* Task 15's quirk is fixed here: the file-write warning shows only when the answer carries
  `tags_message_l10n`; `tags_written: false` without a message (nothing changed) clears it.
* "E2E per role with a read-only photo": a read-only file does not make a + or x fail. The tag is
  saved and only the file write fails, which already shows its warning (`roles.spec.js`
  write-warning, per role). The per-role failure cases use real server refusals instead: the request
  goes out with one field changed (`tag_id` of no tag: HTTP 404; `image_id` 0: HTTP 200 with
  stat "fail"), plus a dropped connection and a cleared session.
* `edge-cases.spec.js` "a server rejection leaves the badge clickable" asserted the old
  `console.warn`; it now asserts the visible reason.

## Verification

* [x] E2E per role with a read-only photo: `+` and `x` show the message, badge reverted
  **Note:** Verified via `tests/e2e/failed-saves.spec.js` (generic, normal, admin, webmaster: + with
  HTTP 404, x with stat "fail", message equals the server's, badges and a reload unchanged, the next +
  clears it), see Deviations for the read-only photo.
* [x] E2E: expired session shows the reason
  **Note:** `failed-saves.spec.js` "an expired session says so, for + and for typing" (real 401
  after clearing cookies); "a lost connection" covers the network failure.
* [x] E2E: a forced festlegen failure shows an error, a second save goes through without reload
  **Note:** `tests/e2e/admin-color-failure.spec.js` (stat "fail" and HTTP 403, both real server
  refusals; error shown, no `loading`, old "Farbe gespeichert" hidden, next save OK, reload shows it).
  With `events_public.inc.php` and `tags.js` flipped to the pre-task versions all 7 new cases went
  red.
* [x] typetags and provenance unit suites pass
  **Note:** typetags unit 91 OK; provenance `GermanOverrideKeyTest` 81 OK (2026-10-16); full typetags
  E2E 65 passed + `edge-cases` after its update.
