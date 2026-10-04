---
id: 04
dependencies:
- 02
- 03
---

# Task 04: Describe the live row and the reload in the German handbook

The handbook page on person tagging tells users that the name appears in Personen at once and
that the page reloads when they leave the mode.

## References

* `design.md#target-behaviour`
* `.claude/rules/handbook.md`
* `handbuch/05-personen.html` — section "Abbrechen und löschen", the tagging steps

## Work

* [ ] Add the behaviour to `handbuch/05-personen.html`. The name appears in the Personen row
  right after saving. "Markieren beenden" or Esc reloads the page when something changed,
  and only then do Schlagworte and the links update
* [ ] Re-take screenshots only if a shown screen changed (`handbook.md`: commit a re-shoot only
  when the screen changed)
* [ ] Add a hand-check entry to the ledger in `docs/agents/TESTING.md` stating that the text matches the
  behaviour

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes
* [ ] The new text matches what tasks 02 and 03 ship (checked by hand, recorded in the ledger)
