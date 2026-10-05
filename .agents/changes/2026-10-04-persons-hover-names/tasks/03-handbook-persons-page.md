---
id: 03
dependencies:
- 02
---

# Task 03: Update the handbook persons page to hover-only names

The German handbook describes and shows the new behaviour: boxes appear on photo hover, a name
appears when the pointer is on its box.

## References

* `design.md#target-behaviour`
* `.claude/rules/handbook.md` (seed / shoot / restore cycle, never photograph the real gallery)
* `handbuch/05-personen.html:22-32` — "erscheinen sie samt Namen", caption "Der Name steht unten am Rahmen"
* `handbuch/tools/shoot.js` — shot `16-personen-boxen.png`, which hovers the centre of `#theMainImage`
* `docs/agents/TESTING.md` — hand-check ledger

## Work

* [x] Rewrite the paragraph under "Vorhandene Markierungen ansehen": boxes on photo hover, the
  name when the pointer is on a box, a click on the name opens the person's photos. Mention that
  the **Personen** row lists every name (the only place on a touch device)
* [x] Update the caption and `alt` of `16-personen-boxen.png` to match what the shot shows
* [x] `shoot.js` shot 16: hover one face of the demo photo (not the photo centre), so exactly one
  name shows
* [x] Re-shoot with seed → shoot → `--restore`; commit only `16-personen-boxen.png` and revert
  churn in the other shots
* [x] Add a dated hand-check ledger entry for the reworded text and the new shot

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes
  **Note:** Verified via `ddev exec php handbuch/tools/check.php`: `OK  6 pages, 48 references, 20 screenshots all referenced, 8 admin routes resolve, 38515 bytes free of em-dashes and emoji`
* [x] `16-personen-boxen.png` shows the boxes and exactly one name
  **Note:** Verified via looking at the new PNG (both boxes, only "Anna Beispiel" named) and via
  `shoot.js`, which now throws unless exactly one label has an effective opacity above 0.5 after
  every CSS transition under `#persons-stage` finished
* [x] The install is back to its pre-seed state after `--restore`
  **Note:** Verified via `ddev mysql` counts before seed and after `--restore`, identical:
  categories 5, images 105, tags 8, image_tag 76, image_category 105, persons 0, person_region 0
* [x] No other screenshot changed in the commit
  **Note:** Verified via `git checkout --` of the 11 other re-shot PNGs and `git status` before
  committing. The churn came from the modus fit-to-area change (decision 0032) altering the photo
  stage size, not from content changes (17 compared by eye: same content, larger stage)

## Deviations

* `shoot.js` aims at the leftmost box by rendered position rather than a named face, and gained a
  `waitForStageTransitions()` helper (same predicate as `PicturePage.labelStyle()`) plus a
  one-visible-name assertion, so a shot showing zero or two names fails instead of being written.
* The paragraph was split in two: the hover behaviour, then the Personen row (incl. touch devices).

## Manual checks remaining

* [x] Whether the reworded German reads naturally
  **Note:** Verified via a critical review by a sub-agent (2026-10-05). Its findings were applied:
  shorter opening sentence, consistent *Mauszeiger*, *Smartphone*, *rechts neben dem Foto*,
  corrected `alt` and caption, and *am unteren Rand des Rahmens* (a name can overhang a small
  box since `f4a13a462`). Recorded in the hand-check ledger of `docs/agents/TESTING.md`
