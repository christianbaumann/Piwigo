---
id: 50
dependencies: []
---

# Task 50: Read the German of the handbook paragraphs changed in this change

Added 2026-10-18 (user's answer 7a).

## References

* `handbuch/03-fototexte.html`, `04-schlagworte.html`, `05-personen.html`, `06-drehen.html`, `07-fotoseite.html` (changes since `094152ed5`)
* `.claude/rules/handbook.md`, `docs/agents/TESTING.md` (hand-check ledger)

## Work

* [ ] Each changed paragraph read against the screen it describes (demo album, persons test accounts); wording fixed where it is wrong or reads unnaturally
* [ ] One dated ledger row per page; anything for the user to decide listed in the task file

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes
* [ ] Ledger rows dated 2026-10-18

## WIP (paused 2026-10-18)

No handbuch page edited, no ledger row written, demo album never seeded (nothing to restore), check.php not run.

Read against source (labels only, no browser):
* 03-fototexte: "Notiz speichern", "Herkunft gespeichert", "Von wem" match `plugins/provenance/language/de_DE/plugin.lang.php`. OK.
* 04-schlagworte: "Nicht benutzte Schlagworte (Anzahl)" matches `local/language/de_DE.lang.php`; "Überprüfung", "Löschen", "Behalten" match; names listed one per line (`<br>` join in tags.js); exemption of colour-group and person tags matches `plugins/galleryrules/include/events_orphan_tags.inc.php`. OK, not yet seen in browser.

Not yet read: 05-personen (Enter behaviour vs persons picker JS; JS file not yet located under plugins/persons/template or admin), 06-drehen (restore procedure via replace upload, check 02-fotos.html link and option label "aktualisieren Sie die Datei"), 07-fotoseite (click zones "knapp ein Drittel", mobile +/- keys, zoom kept across widths: check modus js/photo.zoom.js and navigation zones CSS).
