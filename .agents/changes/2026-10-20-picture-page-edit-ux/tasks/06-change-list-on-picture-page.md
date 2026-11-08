---
id: 06
dependencies:
- 01
status: todo
owner:
branch:
---

# Task 06: „Letzte Änderungen an diesem Foto“ on the picture page

Logged-in users see a collapsed list of the photo's last changes with who and when, and provenance's
read-only page shows the new entry types as readable text.

## References

* `design.md#letzte-änderungen-an-diesem-foto`
* `design.md#values-of-tag-and-region-entries`
* `prototype/picture-edit-concepts.html` (Konzept B, history block)
* `plugins/provenance/include/events_page.inc.php` (`provenance_page_history_rows()`)
* `plugins/photoinfo/include/events_public.inc.php` (`photoinfo_picture_rows()`)

## Work

* [ ] Pure formatter: entry → German text („„Kirche“ hinzugefügt“, „Datum geändert: ca. 1955 → vor 1960“, „„Anna Schmitz“ markiert“, „Foto gedreht“), relative time („heute, 14:05“, „gestern, 18:42“, date), user („(Sie)“, „gelöschter Benutzer“)
* [ ] Use the formatter in `provenance_page_history_rows()` for the new fields
* [ ] photoinfo renders the list under the info rows: collapsed „▸ Letzte Änderungen an diesem Foto (N)“, expands to the last 8 entries, „alle anzeigen“ links to the read-only page; refreshes from `pwg.provenance.getHistory` on open and after a save event on the page
* [ ] Shown to every logged-in account that sees the photo, never to guests
* [ ] Unit tests for the formatter (each entry type, today/yesterday/older `[BVA]`, deleted user); integration test of the rendered block per role; E2E: collapsed, expand, count; photoinfo `RoleMatrixTest` row `history-list`
* [ ] German strings in photoinfo's language file

## Verification

* [ ] With three seeded entries the page shows „▸ Letzte Änderungen an diesem Foto (3)“ and no entries until clicked
* [ ] Expanded, each entry names user and time; the viewer's own show „(Sie)“
* [ ] A guest and an account without access to the album see no list
* [ ] provenance's read-only page shows tag and region entries as text, not JSON
* [ ] photoinfo and provenance suites pass
