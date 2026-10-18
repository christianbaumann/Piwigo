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

* [x] Each changed paragraph read against the screen it describes (demo album, persons test accounts); wording fixed where it is wrong or reads unnaturally
  * **Note:** 05-07 driven in a browser on the demo album; 03 live; 04 source only (no unused tag older than a day on the demo)
* [x] One dated ledger row per page; anything for the user to decide listed in the task file

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes
  * **Note:** `OK  8 pages, 74 references, 28 screenshots all referenced, 8 admin routes resolve` (2026-10-18)
* [x] Ledger rows dated 2026-10-18

## Result (2026-10-18)

Demo album seeded and restored under `flock /tmp/pwg-suite.lock`; screenshots and scripts in `.agent-tests/2026-10-18-task50-handbook/`.

* 03: matches the live screens (*Notiz speichern*, provenance page columns incl. *Von wem*). No change.
* 04: checked against source only (message needs a tag unused over a day; not producible on the demo). No change.
* 05: Enter behaviour verified in the picker; the hinweis box reworded (dropped the "sucht zuerst mit genau diesem Text" implementation detail).
* 06: restore text named a non-existent label ("Option *aktualisieren Sie die Datei*"); now *Optionen* and the quoted switch.
* 07: "oberen Streifen" was wrong, the zone is the upper half of the middle area; fixed. Side zones keep "knapp ein Drittel" (design.md rejected "30 %"). Narrow window and kept zoom verified.
* No screenshot changed meaning, none re-shot. Install after `--restore`: 5 albums, 106 photos (the baseline in handbook.md says 105; another task's fixture, not the demo, which is gone).

### For the user to decide

* On the first photo of an album a click on the left zone led to the album page in the check (there is no previous photo); the handbook only says "zum vorigen Foto". Leave, or add "(beim ersten Foto zum Album)"? Not verified further, theme behaviour.
* The photo's Eigenschaften page also has a *Notiz speichern* button; handbook 03 only describes it on the picture page - confirm that is the intended place.
