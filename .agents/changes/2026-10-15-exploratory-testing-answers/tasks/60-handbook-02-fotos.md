---
id: 60
dependencies:
- 58
---

# Task 60: Chapter 02 describes replace, the last-album rule, the sync relink and Abbrechen

Added 2026-10-19. Chapter 02 matches the code for every 02 finding of the 2026-10-19 review, including the wrong replace sentence.

## References

* `design-handbook-gap-fix.md#the-findings-and-where-they-go` (rows for chapter 02)
* `handbuch/02-fotos.html` (line 68 *Optionen*, line 147 *Von Album abtrennen*, *Große Mengen ohne Browser*)
* Decisions 0079, 0084, 0085; `plugins/photoinfo/include/events_upload.inc.php`, `plugins/persons/include/events_upload.inc.php`, `plugins/galleryrules/include/events_replace.inc.php`, `events_last_album.inc.php`; `admin/themes/default/js/photos_add_direct.js`; `admin/site_update.php`, `site_update.tpl`

## Work

* [ ] Replace switch: replace the "Titel, Beschreibung und Schlagworte ... erhalten bleiben" sentence with the field-by-field rule (the title is always kept; Info, date, marked keywords and person marks from the file win; otherwise the gallery's values are kept and written into the file; a marked person's tag stays)
* [ ] New upload: a file that carries Info, date, marked keywords or person marks brings them along
* [ ] Replace refused when several photos of the album share the file name, with the German message
* [ ] *Von Album abtrennen*: removing a photo's last album is refused, with the message and the way out; figure `30-album-abtrennen-verweigert.png`
* [ ] Sync, under *Fallen*: when a folder disappears, photos stored elsewhere move to the parent album or to the private album "Ohne Album"; the result line
* [ ] Abbrechen: files already transferred stay; unfinished ones are discarded; failed files are listed
* [ ] Each new statement checked once in the browser on the demo album, or by its test where a browser cannot reach it (sync); result as a note here

## Verification

* [ ] `check.php` passes for 02
* [ ] Every quoted German string matches the language files
