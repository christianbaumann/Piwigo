---
id: 14
dependencies:
- 13
status: todo
owner:
branch:
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

* [ ] Record decision „the picture page saves each step at once, with visible status and undo“ (Konzept B; cites this change's design and rejected A and C)
* [ ] Rewrite the affected handbook sections: „✎ ändern“, saving on leaving the field, „+ Person markieren“, deleting from the Personen row, the change list and Rückgängig
* [ ] Re-take only the screenshots whose screens changed (seed demo, `shoot.js`, `--restore`)
* [ ] Update `CLAUDE.md` / rules files where they describe the old flow (persons toggle, photoinfo reload)
* [ ] Run `ddev exec php handbuch/tools/check.php`

## Verification

* [ ] Every handbook page describing these rows matches the page as built
* [ ] `check.php` passes; the demo album is restored afterwards
* [ ] No rule file still mentions „Personen markieren / Markieren beenden“ or the reload after Speichern
