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

* [x] Replace switch: replace the "Titel, Beschreibung und Schlagworte ... erhalten bleiben" sentence with the field-by-field rule (the title is always kept; Info, date, marked keywords and person marks from the file win; otherwise the gallery's values are kept and written into the file; a marked person's tag stays)
* [x] New upload: a file that carries Info, date, marked keywords or person marks brings them along
* [x] Replace refused when several photos of the album share the file name, with the German message
* [x] *Von Album abtrennen*: removing a photo's last album is refused, with the message and the way out; figure `30-album-abtrennen-verweigert.png`
* [x] Sync, under *Fallen*: when a folder disappears, photos stored elsewhere move to the parent album or to the private album "Ohne Album"; the result line
* [x] Abbrechen: files already transferred stay; unfinished ones are discarded; failed files are listed
* [x] Each new statement checked once in the browser on the demo album, or by its test where a browser cannot reach it (sync); result as a note here

  **Note:** Verified by tests, not by a demo-album run: galleryrules `last-album.spec.js`, `replace-ambiguous.spec.js`, `upload-page.spec.js` (11 passed, run under the suite lock); replace/upload merge by persons and photoinfo `UploadMergeTest` (decision 0084); sync relink by provenance `CoreSiteUpdateCountsCharacterizationTest` (a real sync is unsafe in the browser). Figure 30 was shot from the demo album by task 58.

## Verification

* [x] `check.php` passes for 02
* [x] Every quoted German string matches the language files

  **Note (verifier, 2026-10-19):** Demo album, webmaster account, one locked seed/check/restore cycle: Stapelverarbeitung "Von Album abtrennen" on all 6 demo photos answered "Nichts entfernt: Die Fotos #..., ... wären danach in keinem Album mehr. Verschieben Sie sie stattdessen in ein anderes Album." (matches the chapter). persons and photoinfo `UploadMergeTest` run: 4 and 9 tests OK. Quoted strings checked against `plugins/galleryrules/language/de_DE/plugin.lang.php`, `language/de_DE/admin.lang.php` (`Fehler bei Datei »%s« : %s`, `Ins Album verschieben`) and `local/language/de_DE.lang.php`. `check.php`: no finding for 02 (remaining: other tasks' chapters/screenshots). APPROVED.
