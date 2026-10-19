---
id: 65
dependencies:
- 55
- 56
- 57
- 58
- 59
- 60
- 61
- 62
- 63
- 64
---

# Task 65: Independent review of the fixed handbook, ledger rows, German read

Added 2026-10-19 (brainstorm 8a plus review). A fresh agent confirms that every changed section matches the code and the live screens; the ledger records it; the owner has read the German.

## References

* `design-handbook-gap-fix.md`, key decision *Verification and a final review*
* Tasks 55-64 (their notes hold the browser-check results), `docs/agents/TESTING.md` (hand-check ledger), `.claude/rules/handbook.md`

## Work

* [x] Launch a fresh general-purpose agent with no context of this change. It gets the list of changed sections (`git diff 4b0400c3b -- handbuch/`) and the code paths they describe. With `playwright-cli` on the demo album (seed, check, `--restore` under the suite lock) it walks each statement as a normal account and as webmaster, and reports WRONG/MISSING with screenshots in `.agent-tests/2026-10-19-handbook-gap-fix/`
  * **Note:** Report `.agent-tests/2026-10-19-handbook-gap-fix/report.md` (screenshots a01-c03). 35 rows: 31 TRUE, 4 WRONG (F1-F4). The reviewer's own Write into `.agent-tests/` was refused; the main agent wrote the report from its answer.
* [x] The agent reports and fixes nothing; each finding becomes a follow-up task file
  * **Note:** F3 is task 66 (code). F1, F2, F4, the row 23 nit and doubts 3 and 4 are task 67. The upload error reason in English is core's `die('forbidden file type')` (`admin/include/functions_upload.inc.php:271`), not this fork's code: one line in `docs/backlog.md`, no task. The marking-mode stale-border colour has no E2E: one line in `docs/backlog.md`. The `handbook.md` figure "5 albums / 105 photos" is a dated measurement (2026-08-31); the install now has 106 photos (photo 56012, uploaded 2026-10-15): left as is, mentioned in `status.md`.
* [x] Dated ledger rows in `docs/agents/TESTING.md`, one per chapter, from the task notes and the review
  * **Note:** Six rows dated 2026-10-19 in the hand-check ledger of `docs/agents/TESTING.md`, chapters 01-06.
* [x] The owner reads the German of every changed paragraph; changes from that go into a follow-up task
  * **Note:** the owner delegated the German read to a critical subagent on 2026-10-19. Wording edits, no fact, quoted UI string, link or figure touched: chapter 01 "unsichtbar, weder ... noch" made grammatical, dialog sentence reordered; 02 the duplicated field list in the replace rule merged into one sentence, "Ein markierter Mensch" became "Das Schlagwort einer markierten Person", "Dann" added to the duplicate-name advice; 03 the failed-write sentence reworded, "Einzelansicht" became "Einzelmodus" (the batch manager's own label, also alt text and caption of shot 31); 04 "Einzelansicht" became "Einzelmodus", an ambiguous "sie" replaced, "wenn sie sich ... ändern" made explicit; 05 "ein Zug mit der Maus" reworded, the Aktionen parenthesis untangled, the stale-box border is now "blasser" not "heller"; 06 "Personen-Markierungen" became "Personenmarkierungen" in the restore paragraph. Open doubts are in the agent's report, not edited. `check.php` OK after the edits (8 pages, 79 references, 31 screenshots).
  * **Note (stale-box border):** checked 2026-10-19 in Chromium as `persons_normal` in marking mode, computed styles only, no screenshot, seeds `overlay` and `stale` of persons' E2E `seed.php` inside one suite lock, restored. Normal box: `border-top-color rgba(255, 255, 255, 0.9)`, solid, opacity 1. Stale box: `rgba(255, 255, 255, 0.45)`, dashed, opacity 1 (`editor.css`: `#persons-stage.persons-tagging .person-box-stale`). So the border is fainter, not lighter: chapter 05 now says "blasseren Rand". Persons' `overlay.spec.js` ('a stale person region') asserts dashed, dimmed outside marking mode and the tooltip in both modes, but not the marking-mode border colour.
  * **Note (doubts of the German read, settled 2026-10-19):** (1) "Modus für alle Fotos" was not core's label: the Batch Manager tab is `l10n('global mode')` (`admin/include/add_core_tabs.inc.php:48`), German *Übersicht* (`language/de_DE/admin.lang.php:190`), no local override. Fixed in chapters 03 and 04 to "im Modus <em>Übersicht</em>". (2) "nur die von der Galerie in die Datei geschriebenen" is right: `photoinfo_merge_uploaded_tags()` (`plugins/photoinfo/include/events_upload.inc.php:138`) acts on the file only when `tags_marker` (`XMP-pwginfo:TagsWritten`, written with the gallery's keywords) is present. No change. (3) "Code Points" and (4) "über die Schnittstelle" are decisions for the owner: items in task 67.
* [x] `status.md` updated
  * **Note:** Section for tasks 55-65, incidents and the lock rule added.

## Verification

* [x] `check.php` passes with all 31 screenshots referenced
  * **Note:** `ddev exec php handbuch/tools/check.php`: OK, 8 pages, 79 references, 31 screenshots all referenced, 8 admin routes (after the Übersicht fix).
* [x] Report exists and lists every changed section with a verdict
  * **Note:** `report.md` has 35 rows, each with a verdict.
* [x] No finding is left without a follow-up task or a recorded "no change"
  * **Note:** Every finding is in task 66 or 67, or recorded as no change (doubt 2) or backlog (see the work item above).
