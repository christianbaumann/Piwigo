---
id: 14
dependencies:
- 13
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-14
---

# Task 14: Handbook, screenshots and the save-model decision

The German handbook describes the new way to edit date, info, persons and tags, with fresh
screenshots, and the save model is recorded as a decision.

## References

* `design.md#save-model-konzept-b-every-step-saves-itself-visibly`
* `.claude/rules/handbook.md` (seed, shoot, restore, `check.php`; never photograph real scans)
* `handbuch/` pages on photo texts, tags and person tagging
* `docs/agents/decisions/0058-photoinfo-date-and-info-editable-by-logged-in-users.md`

## Work

* [x] Record decision „the picture page saves each step at once, with visible status and undo“ (Konzept B; cites this change's design and rejected A and C)
* [x] Rewrite the affected handbook sections: „✎ ändern“, saving on leaving the field, „+ Person markieren“, deleting from the Personen row, the change list and Rückgängig
* [x] Re-take only the screenshots whose screens changed (seed demo, `shoot.js`, `--restore`)
* [x] Update `CLAUDE.md` / rules files where they describe the old flow (persons toggle, photoinfo reload)
* [x] Run `ddev exec php handbuch/tools/check.php`

## Verification

* [ ] Every handbook page describing these rows matches the page as built (manual testing required) **Note:** Text checked by the verifier against templates, language files and the plugins' JS with no mismatch found; nobody has read the pages against the live page, and no shot shows Rückgängig, the expanded change list or a × in the Personen row. Ledger row in `docs/agents/TESTING.md`.
* [x] `check.php` passes; the demo album is restored afterwards **Note:** `php handbuch/tools/check.php` through the worktree harness: „OK 8 pages, 81 references, 31 screenshots all referenced, 8 admin routes resolve“; `seed.php --restore` returned `restored: true`, a second restore found no snapshot.
* [x] No rule file still mentions „Personen markieren / Markieren beenden“ or the reload after Speichern **Note:** grep over CLAUDE.md and `.claude/rules/*.md` finds no persons toggle and no reload after Speichern. Old dated rows in `docs/agents/TESTING.md` stay as history.

## Implementation notes

- Decision 0093 (Konzept B: each step saves itself, visible status, undo).
- Handbook pages 03, 04, 05, 06 rewritten; ✎, ✓ and ↶ are written as words because `check.php` refuses those characters. Screenshots 11, 15, 17, 23, 24, 25 re-taken; `shoot.js` uses `#persons-tag-add`, `#photoinfo-date-edit` / `#photoinfo-info-edit`, empties the date fields before Fertig in shot 24, and scrolls the photo into view before drawing.
- Shots 11 and 15 show the tag „ETS27 Alt“, an older test leftover in the install, not from this change.
- typetags `row-status.spec.js` created `_test_e2e_status` and never removed it; it now tracks the tag for `--restore` (submodule commit 424ea62), and the leftover tag was deleted from the local install.
