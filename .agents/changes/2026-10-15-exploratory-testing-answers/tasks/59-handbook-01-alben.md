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

* [ ] *Album löschen*: the three delete options, and the refusal when a photo would end up in no album, with the German message and the way out (move first, or delete with the album); figure `29-album-loeschen-verweigert.png`
* [ ] Empty album: visitors see an album only once it or a sub-album holds a photo; the admin hint on the album page
* [ ] Name and description are plain text: markup shows as typed, line breaks stay
* [ ] *Herkunft des Albums*: "Auf N Fotos anwenden" overwrites every photo after a confirm naming how many have another Herkunft; a sub-album without its own values applies the nearest higher album's; empty album fields clear the photos' fields
* [ ] Each new statement checked once in the browser on the demo album (seed, check, `--restore` under the suite lock); result as a note here

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes for 01
* [ ] Every quoted German string matches the language files
