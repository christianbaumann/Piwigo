---
id: 53
dependencies:
- 44
---

# Task 53: Handbook 03 names both places of "Notiz speichern"

Added 2026-10-18 (user's answer 3a): the photo's Eigenschaften page has its own *Notiz speichern* (task 44); chapter 03 describes only the picture page.

## References

* Tasks 44, 50; `handbuch/03-fototexte.html`; `plugins/provenance/template/photo_provenance.tpl`; `.claude/rules/handbook.md`

## Work

* [x] One sentence in chapter 03 naming the Eigenschaften page's Notiz block and its button (checked against the live page on the demo album)
  * **Note:** Eigenschaften page shows the Herkunft block with Notiz and a visible Notiz speichern button (demo album, 2026-10-18)

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes; dated ledger row in `docs/agents/TESTING.md`
  * **Note:** `OK  8 pages, 74 references, ...` (2026-10-18); ledger row dated 2026-10-18
