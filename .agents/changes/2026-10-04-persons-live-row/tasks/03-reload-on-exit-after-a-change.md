---
id: 03
dependencies:
- 01
---

# Task 03: Reload the public picture page when tagging mode ends after a change

When the user leaves tagging mode after at least one add or delete, the public page reloads.
After the reload, the Personen links and the Schlagworte row show the current state.

## References

* `design.md#reload-only-on-the-public-page-only-after-a-change`
* `design.md#schlagworte-is-fixed-by-the-reload-not-patched`
* `plugins/persons/template/public_overlay.tpl` — `#persons-editor` data attributes
* `plugins/persons/include/events_public.inc.php` — `persons_picture_overlay()`
* `plugins/persons/admin/photo.php`, `template/admin_photo.tpl` — the surface that must not reload
* `plugins/persons/template/editor.js` — `enter()`, `exit()`, Esc handler

## Work

* [x] Public page only: assign a template flag in `persons_picture_overlay()` (not in the shared
  `persons_assign_overlay()`). `public_overlay.tpl` emits `data-persons-reload-on-exit` on
  `#persons-editor` when the flag is set
  **Note:** `PERSONS_RELOAD_ON_EXIT`; emitted as ` data-persons-reload-on-exit="1"` with an explicit
  leading space. The template's `{strip}` joins attribute lines with no whitespace, and the bare
  attribute first merged into the next one (`…-on-exitdata-persons-str-who`). The integration case
  caught it
* [x] `editor.js`: a `changed` flag, reset in `enter()`, set after each successful add or delete.
  `exit()` calls `location.reload()` when `changed` is set and the attribute is present
  **Note:** no reset in `enter()`, see *Deviations*
* [x] Integration (written first, watched red):
  * `[HAPPY]` the public editor element carries `data-persons-reload-on-exit`
  * `[NEG]` the admin tagging screen's editor element does not

  **Note:** `PicturePageSourceTest::testThePublicEditorReloadsOnExit` (red before the change, red
  again on the merged attribute); `AdminPhotoScreenTest::testTheAdminEditorDoesNotReloadOnExit`
  passed from the start, since the attribute did not exist yet. Seen red with the flag also
  assigned in `admin/photo.php`
* [x] E2E (written first, watched red), replacing task 01's "no navigation on exit" case:
  * `[ST]` save → "Markieren beenden" → page reloads; the row now links the name; Schlagworte shows the tag
  * `[ST]` save → Esc (no draft open) → page reloads
  * `[ST]` enter → exit without a change → no navigation
  * `[NEG]` a refused save → exit → no navigation
  * `[NEG]` admin tagging screen: save → exit → no navigation (`chromium-admin` project)

  **Note:** describe `leaving tagging mode` in `editor.spec.js` replaces task 01's
  `leaving tagging mode (current behaviour)`. Mapping: `leaving through the toggle after a save does
  not navigate` → `… reloads the page`; `leaving with Esc after a save does not navigate` →
  `… reloads the page`. New: `leaving without a change does not reload`, `leaving after a refused
  save does not reload`, and `admin.spec.js` `leaving tagging mode after a save does not reload`.
  The two reload specs were red (no `load` event) before the change. The three no-reload specs
  passed from the start (they describe today's behaviour). Each was seen red against a break:

  | Mutant | Killed by |
  |---|---|
  | `changed && reloadOnExit` → `reloadOnExit` | without a change, refused save |
  | `changed && reloadOnExit` → `changed` | admin no-reload spec |
  | `changed = true` in the `stat: fail` branch | refused save |
  | no `changed = false` in `enter()` | nothing: unreachable, removed (see *Deviations*) |
  | a delete does not count as a change | delete spec |
  | `location.replace(<own URL>)` → `location.reload()` | posted-page spec |
  | `pending > 0` dropped from `reloadAfterChange()` | later-save-pending spec |
  | no reload when a late answer lands with the mode off | both pending specs |
  | `draft !== saving` guard dropped | re-entered spec |
* [x] Detect navigation via a navigation/`load` event, never a timeout
  **Note:** `PicturePage.exitTaggingModeAndAwaitReload()` / `…WithEscapeAndAwaitReload()` listen for
  `load` before the click; `markDocument()` / `sameDocument()` assert the document was replaced
  or not

## Verification

* [x] Public page: tagging a person and pressing "Markieren beenden" reloads the page. The name is
  then a link in Personen and appears under Schlagworte
  **Note:** E2E `leaving through the toggle after a save reloads the page` (asserts `personRowLinks`
  and `#Tags`)
* [x] Entering and leaving tagging mode without a change does not reload
  **Note:** E2E `leaving without a change does not reload`
* [x] The admin tagging screen never reloads on exit
  **Note:** E2E `admin.spec.js` `leaving tagging mode after a save does not reload`, plus the
  integration `[NEG]` case
* [x] Persons integration and E2E suites pass twice in a row
  **Note:** 2026-10-04, after the /verify fixes, both runs: unit 130 OK; integration 110 (1
  skipped); E2E 51 passed. The flaky resize spec (task 05) happened to pass both times

## Deviations

* **No reset of `changed` in `enter()`.** The design asks for one. It cannot matter: on the
  public page, once a change has landed with the mode off, the page reloads (directly in `exit()`,
  or when the last pending answer lands), so the next `enter()` runs in a fresh document; on the
  admin screen `changed` is never read. The mutant that drops it survived every spec for that
  reason, so the line was removed and the variable's comment says why.
* **A GET of the page's own URL instead of `location.reload()`** (found in /verify review). Core
  renders picture.php straight from a comment post with no redirect, so a reload would post the
  comment again. `location.replace(href without #)` loads it with a GET.
* **Writes in flight are waited for** (found in /verify review). Leaving the mode while a save or
  delete is still unanswered used to skip the reload (first change) or abort the write (later
  change). `pending` counts unanswered writes; `exit()` reloads only at zero, and the last answer
  to land with the mode off reloads instead. The shared `write()` helper now holds the
  refused/failed handling both writes had duplicated. An answer that arrives after the mode was
  left (or after a new box was drawn) no longer adopts whatever box is current.

## Review (/verify)

Subagent review: no problem with the admin isolation, the reload detection or the anti-vacuity
guards. Applied: the two deviations above, a spec for reload after a delete, and the duplicated
`tagAda()` helper replaced by the module-level `tag()`. Each new spec was watched red
before the fix (the delete spec passed from the start and was seen red against its mutant). New
specs: `leaving after a delete reloads the page`, `leaving after a save on a posted page sends no
form again`, `leaving while the first save is pending reloads once it lands`, `leaving while a
later save is pending waits for it`, `a save answered after the mode was re-entered leaves the new
box a draft`.

**Task approved** after /verify.
