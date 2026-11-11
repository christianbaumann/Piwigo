---
id: 06
dependencies:
- 01
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-06
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

* [x] Pure formatter: entry → German text („„Kirche“ hinzugefügt“, „Datum geändert: ca. 1955 → vor 1960“, „„Anna Schmitz“ markiert“, „Foto gedreht“), relative time („heute, 14:05“, „gestern, 18:42“, date), user („(Sie)“, „gelöschter Benutzer“)
* [x] Use the formatter in `provenance_page_history_rows()` for the new fields
* [x] photoinfo renders the list under the info rows: collapsed „▸ Letzte Änderungen an diesem Foto (N)“, expands to the last 8 entries, „alle anzeigen“ links to the read-only page; refreshes from `pwg.provenance.getHistory` on open and after a save event on the page
* [x] Shown to every logged-in account that sees the photo, never to guests
* [x] Unit tests for the formatter (each entry type, today/yesterday/older `[BVA]`, deleted user); integration test of the rendered block per role; E2E: collapsed, expand, count; photoinfo `RoleMatrixTest` row `history-list`
* [x] German strings in photoinfo's language file

## Verification

* [x] With three seeded entries the page shows „▸ Letzte Änderungen an diesem Foto (3)“ and no entries until clicked **Note:** E2E `history-list.spec.js` (count, closed until opened) and `HistoryListTest`.
* [x] Expanded, each entry names user and time; the viewer's own show „(Sie)“ **Note:** `HistoryListTest::testTheBlockCountsAndNamesWhoChanged`; the E2E spec checks „heute,“.
* [x] A guest and an account without access to the album see no list **Note:** `RoleMatrixTest` row `photoinfo.history-list` (guest none), `HistoryListTest::testAPrivatePhotoWithoutGrantHasNoHistory` and `testNoBlockInThePageOfAPhotoTheAccountCannotSee`.
* [x] provenance's read-only page shows tag and region entries as text, not JSON **Note:** `ReadOnlyPageTest::testNewEntryTypesShowAsTextNotJson`.
* [x] photoinfo and provenance suites pass **Note:** Verifier on the rebased state: provenance unit `OK (355 tests)`, integration `OK (449 tests, 3 skipped)`; photoinfo unit `OK (313 tests)`, integration 275 tests with only the known flaky `HistoryRecordingTest` case failing (fixed in task 04), E2E 72 passed. After the last fixes: `HistoryLogTest|ReadOnlyPageTest|HistoryTest` `OK (33 tests)`, `HistoryListTest|RoleMatrixTest` `OK (44 tests)`.

## Implementation notes

- Formatter: `plugins/provenance/include/history_text.inc.php` (pure half takes a translator; `provenance_history_display_rows()` reads „now“ from the database, since `occured_on` is the database's clock).
- `pwg.provenance.getHistory` gained optional `fields` (whitelist `provenance_history_list_fields()`) and `formatted` (adds `display` per row). Without them the answer is unchanged. The task did not name these; they let the picture page and the read-only page share one formatter.
- The list counts and shows only date, info, tag, region and photo entries; write-back rows stay on the read-only page.
- Refresh: on opening, and 300 ms after any save, through a `window.fetch` wrapper and a jQuery `ajaxSuccess` hook filtered on ws method names. A `Request` object or `FormData` body is not matched. Task 13 may replace this with a shared event.
- For task 07: each entry has an empty `.photoinfo-history-action` slot; the display rows do not carry the entry id yet.
- A prefilter's code changed: an install running this needs `_data/templates_c` cleared once.
