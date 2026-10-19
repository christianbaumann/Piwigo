---
id: 59
dependencies:
- 57
- 58
---

# Task 59: Chapter 01 describes album delete, empty albums, plain text and the apply rule

Added 2026-10-19. Chapter 01 matches the code for every 01 finding of the 2026-10-19 review.

## References

* `design-handbook-gap-fix.md#the-findings-and-where-they-go` (rows for chapter 01)
* `handbuch/01-alben.html`; decisions 0073, 0083, 0086 (task 57); `plugins/galleryrules/include/events_last_album.inc.php`, `events_empty_album.inc.php`; `admin/themes/default/js/albums.js`, `cat_modify.js`
* `.claude/rules/handbook.md`

## Work

* [x] *Album löschen*: the three delete options, and the refusal when a photo would end up in no album, with the German message and the way out (move first, or delete with the album); figure `29-album-loeschen-verweigert.png`
* [x] Empty album: visitors see an album only once it or a sub-album holds a photo; the admin hint on the album page
* [x] Name and description are plain text: markup shows as typed, line breaks stay
* [x] *Herkunft des Albums*: "Auf N Fotos anwenden" overwrites every photo after a confirm naming how many have another Herkunft; a sub-album without its own values applies the nearest higher album's; empty album fields clear the photos' fields
* [x] Each new statement checked once in the browser on the demo album (seed, check, `--restore` under the suite lock); result as a note here
  **Note:** Verified via a playwright script in one locked seed/check/restore cycle on the demo album (2026-10-19, as persons_webmaster and persons_normal): description `<b>fett</b> & <i>x</i>\nzweite Zeile` renders escaped with a `<br>` for the line break; an empty sub-album answers the normal account 401 and is not listed, the webmaster sees the German hint; `pwg.categories.delete` with `no_delete` answers 409 with the quoted "Nichts gelöscht: ..." message; the apply button shows the quoted confirm naming 6 photos, dismissing it applies nothing. Not reachable in the browser run (no second album with values): the nearest-ancestor and clear-all rule, covered by decision 0086 and its provenance tests; the three delete options come from albums.js and the language files.

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes for 01
* [x] Every quoted German string matches the language files

**Verifier (2026-10-19):** corrected three statements: the properties-page delete dialog has the button "Album löschen" (not "Löschen bestätigen"), closes on refusal and shows the message in the footer error line (cat_modify.js); the options vanish when the album holds no photo; the "Albumnotiz" is one of the four applied fields, only the photo's own "Notiz" stays untouched. `ddev exec flock ... phpunit --filter ApplyTest` (provenance integration): 22 tests, 1 skipped, OK. Apply order and confirm text match decision 0086 and the language file.
