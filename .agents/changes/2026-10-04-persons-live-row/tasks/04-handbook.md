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

* [x] Add the behaviour to `handbuch/05-personen.html`. The name appears in the Personen row
  right after saving. "Markieren beenden" or Esc reloads the page when something changed,
  and only then do Schlagworte and the links update
  **Note:** Step 7 in "Eine Person markieren" and a paragraph under "Abbrechen und löschen" (incl. delete/last-face case and "no change, no reload")
* [x] Re-take screenshots only if a shown screen changed (`handbook.md`: commit a re-shoot only
  when the screen changed)
  **Note:** Not needed: every persons shot in `shoot.js` is `#persons-stage` or `#persons-picker`, none shows the info box
* [x] Add a hand-check entry to the ledger in `docs/agents/TESTING.md` stating that the text matches the
  behaviour
  **Note:** Ledger row dated 2026-10-04

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes
  **Note:** `OK  6 pages, 48 references, 20 screenshots all referenced, 8 admin routes resolve`
* [x] The new text matches what tasks 02 and 03 ship (checked by hand, recorded in the ledger)
  **Note:** Read against `editor.js` (`renderPersonRow()`, `reloadAfterChange()`, `exit()`) and `public_persons.tpl`; recorded in the `docs/agents/TESTING.md` ledger
