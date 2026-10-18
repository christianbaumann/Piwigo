---
id: 41
dependencies:
- 25
---

# Task 41: The add-album dialog of Fotos hinzufügen and the Batch Manager shows "Name fehlt"

Added 2026-10-17 from task 25's Deviations (user's answer 4a).

## References

* `design.md#a-blank-album-name-shows-a-hint`
* Task 25 (`plugins/galleryrules/include/events_album_name.inc.php`, `template/album_name_hint.tpl`)
* core `admin/themes/default/template/include/add_album.inc.tpl`

## Work

* [x] ~~Show "Name fehlt" next to the name field of the shared add-album dialog, through the same prefilter-and-include pattern (decision 0036)~~ Closed as not needed (user's answer 2026-10-17, option a); see Deviations

## Verification

* [x] ~~E2E: blank name shows the hint in Fotos hinzufügen and in the Batch Manager; no album created~~ not applicable, task closed
* [x] ~~Unit: structural guard that the dialog's name field still occurs once~~ not applicable
* [x] ~~galleryrules suites pass~~ nothing changed

## Deviations

Examined 2026-10-17 in the running app (throwaway probe spec, deleted afterwards); nothing implemented.

- `include/add_album.inc.tpl` (`#addAlbumForm`) is live only in the **Batch Manager** (action "Verschieben", the `+` next to the album field, `addAlbum.js`). There a blank name already shows core's red "Der Name eines Albums darf nicht leer sein." (`#categoryNameError`) and sends no request. The dialog is not silent; "Name fehlt" would be a second message beside it.
- **Fotos hinzufügen** includes the same template but never opens it (no `pwgAddAlbum()` call on that page). Its real create paths are the album selector (`album_selector.js`, blank name shows core's "Namensfeld darf nicht leer sein") and the first-album modal `#addFirstAlbum`, which exists only while the gallery has no album and on a blank name only logs `console.error` (`add_first_album()`, `photos_add_direct.js`). That modal is the one silent case, and the install always has albums, so an E2E needs an empty gallery.
- Premise of the task ("silent dialog in both screens") does not hold; decision for the user, options: (a) close as not needed; (b) restyle/reword Batch Manager's message to "Name fehlt" (prefilter on `batch_manager_global`/`photos_add_direct`, replacing the text inside `#categoryNameError`); (c) add the hint to the first-album modal (prefilter on `photos_add_direct`, stop `btnAddFirstAlbum` in the capture phase).

**Closed 2026-10-17** (user's answer a): nothing implemented; the first-album modal is in `docs/backlog.md`.
