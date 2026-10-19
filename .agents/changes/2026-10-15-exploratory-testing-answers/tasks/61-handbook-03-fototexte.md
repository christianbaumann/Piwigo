---
id: 61
dependencies:
- 55
- 57
- 58
---

# Task 61: Chapter 03 drops the wrong Notiz sentence and describes plain text, the last album, apply and write warnings

Added 2026-10-19. Chapter 03 matches the code for every 03 finding of the 2026-10-19 review.

## References

* `design-handbook-gap-fix.md#the-findings-and-where-they-go` (rows for chapter 03), key decision *The wrong Notiz sentence is deleted*
* `handbuch/03-fototexte.html` (*Verknüpfte Alben*, *Herkunft und Notiz* lines 104-150)
* Task 53 (wrong premise; not edited), task 55, task 57; decision 0073; `plugins/provenance/include/events_admin.inc.php:122`; `plugins/photoinfo/include/events_core_edit.inc.php`

## Work

* [x] Delete "Dieselbe Notiz mit derselben Schaltfläche steht auch auf der Seite Eigenschaften ..."; the commit message names task 53's wrong premise
* [x] *Verknüpfte Alben*: removing the photo's last album is refused
* [x] Inheritance bullets: replace "Es gilt also das Album, in das das Foto zuerst kam" with a rule that holds after task 57 (first album for new links; "Auf N Fotos anwenden" overwrites after a confirm; sub-album uses the nearest higher album)
* [x] Titel, Autor and Info are plain text
* [x] A failed file write of date or Info from Eigenschaften or the Stapelverarbeitung: "Fotoinfo: ..." at the top in global mode and on Eigenschaften, the message in the photo's badge in unit mode; figure `31-einzelansicht-warnung.png`
* [x] Each new statement checked once in the browser on the demo album; result as a note here

## Verification

* [x] `check.php` passes for 03
* [x] Every quoted German string matches the language files

**Note:** Verified in the browser on the demo album (webmaster, one flock cycle, seeded and restored): last-album refusal on Eigenschaften shows the German message, keeps the album and saves the other fields; Titel/Autor/Info markup shows as text on the picture page; with a read-only file, a changed Beschreibung (Eigenschaften) and "remove creation date" (Stapelverarbeitung, all photos) both show "Fotoinfo: Die Datei fehlt oder ist nicht beschreibbar" at the top. Unit-mode badge is shot 31 (task 55/58). The apply confirm and sub-album rule are covered by task 57's tests, not re-clicked. `check.php`: OK.

**Verifier:** diff reviewed against galleryrules events_last_album, provenance nearest-album rule and language files; `check.php` OK (ddev exec php handbuch/tools/check.php). APPROVED.
