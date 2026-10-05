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

* [ ] Rewrite the paragraph under "Vorhandene Markierungen ansehen": boxes on photo hover, the
  name when the pointer is on a box, a click on the name opens the person's photos. Mention that
  the **Personen** row lists every name (the only place on a touch device)
* [ ] Update the caption and `alt` of `16-personen-boxen.png` to match what the shot shows
* [ ] `shoot.js` shot 16: hover one face of the demo photo (not the photo centre), so exactly one
  name shows
* [ ] Re-shoot with seed → shoot → `--restore`; commit only `16-personen-boxen.png` and revert
  churn in the other shots
* [ ] Add a dated hand-check ledger entry for the reworded text and the new shot

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes
* [ ] `16-personen-boxen.png` shows the boxes and exactly one name
* [ ] The install is back to its pre-seed state after `--restore`
* [ ] No other screenshot changed in the commit
