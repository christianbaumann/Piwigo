---
id: 67
dependencies:
- 66
---

# Task 67: Handbook text fixes from the independent review

Added 2026-10-19. Findings F1, F2, F4, one nit and two doubts of the German read. Chapters 01 and 04 (and the rename statement in 04/05 once task 66 is done).

## References

* `.agent-tests/2026-10-19-handbook-gap-fix/report.md` (F1, F2, F4, row 23)
* `admin/themes/default/js/cat_modify.js:191` (properties-page delete dialog), `plugins/photoinfo/include/events_tags.inc.php:170` (`photoinfo_photoedit_end()`), photoinfo `TagWriteTest::testAPhotoeditCropThatCutsAFaceRewritesTheKeywords`, `testAPersonsRenameRewritesEveryPhoto`
* `handbuch/01-alben.html`, `handbuch/04-schlagworte.html`, `handbuch/05-personen.html`; `.claude/rules/handbook.md`

## Work

* [ ] F1, chapter 01 *Album löschen*: the properties-page dialog always says `Lösche Album "Name" und N Unteralben` (also with 0); only the album list uses the short form. Say so or name only the three options
* [ ] F2, chapter 01: "Abbrechen schließt den Dialog" holds only for the album list; the properties page's confirm box has `Album löschen` and `Nein, ich habe meine Meinung geändert`. Name that button
* [ ] F4, chapter 04 *Schlagworte in der Bilddatei*: keywords are written after a turn or crop only when the edit changed the photo's tag set (a crop that cuts a person away); add that renaming a person on the Personen page rewrites every photo of that person
* [ ] Row 23 nit, chapter 04: the typetags hint says *Emoji einfügen*, the handbook says "ein Bild einfügen"; align
* [ ] Owner decision (3), chapter 04 Emoji field: "Code Points" is jargon; reword or explain
* [ ] Owner decision (4), chapter 04 "über die Schnittstelle" (API) is unclear to end users; reword or drop
* [ ] After task 66: the rename statements in chapters 04 and 05 hold as written; re-check once in the browser
* [ ] Re-shoot only if a screen a shot shows changed (none expected); `check.php` OK

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes
* [ ] Each changed statement checked once on the demo album (seed, check, `--restore` in one locked command); note here
* [ ] Ledger row in `docs/agents/TESTING.md`
