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

* [x] F1, chapter 01 *Album löschen*: the properties-page dialog always says `Lösche Album "Name" und N Unteralben` (also with 0); only the album list uses the short form. Say so or name only the three options
* [x] F2, chapter 01: "Abbrechen schließt den Dialog" holds only for the album list; the properties page's confirm box has `Album löschen` and `Nein, ich habe meine Meinung geändert`. Name that button
* [x] F4, chapter 04 *Schlagworte in der Bilddatei*: keywords are written after a turn or crop only when the edit changed the photo's tag set (a crop that cuts a person away); add that renaming a person on the Personen page rewrites every photo of that person
* [x] Row 23 nit, chapter 04: the typetags hint says *Emoji einfügen*, the handbook says "ein Bild einfügen"; align
* [x] Owner decision (3), chapter 04 Emoji field: "Code Points" is jargon; reword or explain
* [x] Owner decision (4), chapter 04 "über die Schnittstelle" (API) is unclear to end users; reword or drop
* [x] After task 66: the rename statements in chapters 04 and 05 hold as written; re-check once in the browser
  **Note:** Verified via playwright as `persons_webmaster` on the seeded demo album (seed, check, `--restore` in one locked command, `_data/combined` cleared first): renaming `Anna Beispiel` shows in the error line `Personen-Schlagworte werden nur zusammen mit der Person umbenannt: Anna Beispiel. Die Person benennt der Webmaster auf der Seite Personen um.` Matches chapter 04 and 05 as written; no wording change. Restore returned `restored: true`; temp scripts removed.
* [x] Re-shoot only if a screen a shot shows changed (none expected); `check.php` OK
  **Note:** no screen changed, no re-shoot; `ddev exec php handbuch/tools/check.php` OK (8 pages, 79 references, 31 screenshots).

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes
* [x] Each changed statement checked once on the demo album (seed, check, `--restore` in one locked command); note here
  **Note:** not re-run in a browser: F1/F2 restate what the review measured live (a03, a05, `cat_modify.js:191`); F4 restates `TagWriteTest::testAPhotoeditCropThatCutsAFaceRewritesTheKeywords` and `testAPersonsRenameRewritesEveryPhoto`; the emoji field accepts a pasted emoji or code points per its hint in `plugins/typetags/template/admin.tpl:26`. The rename-refusal statements wait for task 66.
* [x] Ledger row in `docs/agents/TESTING.md`

**Note:** Owner decision (3), chapter 04 Emoji field: unanswered, recommended default applied. The field accepts a pasted emoji or code points (`admin.tpl:26`), so "Code Points" is not dropped silently: the text now says to paste the emoji itself and mentions the Unicode numbers (example `1F5BC FE0F`) as the alternative. The typetags hint says *Emoji einfügen*, so "ein Bild einfügen" became "das Emoji selbst einfügen" (row 23 nit).

**Note:** Owner decision (4), "über die Schnittstelle": unanswered, recommended default applied. No end-user page does this; it is an upload by a program (for example a Piwigo app), so the text says "durch ein Programm außerhalb der Galerie-Seiten, etwa eine Piwigo-App".

**Note:** F1 chosen wording: names that the properties page always uses the form with Unteralben (also `0 Unteralben`); F2 names the button `Nein, ich habe meine Meinung geändert`.
