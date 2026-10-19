# 0086 — "Auf N Fotos anwenden" uses the nearest ancestor's values and confirms overwrites

Date: 2026-10-19
Status: settled

Task 57 of `.agents/changes/2026-10-15-exploratory-testing-answers`; design `design-handbook-gap-fix.md`, "The apply rule". Builds on decision C3 (the album is authoritative) and the inheritance rule of task 20 (`provenance_inherited_album_values()`).

## Decision

- `pwg.provenance.applyToPhotos` writes the album's own four values when it has any, else those of its nearest ancestor that has any, else blanks (the photos are cleared, as before). All four come from one album, never field by field. Same rule as the move/upload inheritance.
- The method takes `dry_run=1`: it writes no row and no history, and answers `would_overwrite`, the number of the chunk's photos that hold at least one of the four fields and differ from what the apply would write. The field is `0` on a real run. No new method, so no new role-matrix row.
- The album screen's apply button asks every chunk with `dry_run=1` first and shows a `confirm()` naming the summed count when it is above 0. Abbrechen applies nothing and puts the button back. With 0, the apply runs without a prompt.
- The photo's own note is still never touched, and is not part of the count.

## Consequences

- Accepted trade-off: a sub-album whose Herkunft was cleared on purpose no longer clears its photos while an ancestor has values; to clear them, the ancestor's values must go too, or the photos are cleared through the move prompt's "Leeren".
- The confirm counts photos by value, not by origin: a photo that came from another album and already equals the values is not named. A photo is never told apart by which album wrote it, because the columns do not record it.
- The dry run costs one extra request per chunk before every apply.
- `confirm()` is the browser's own dialog, not a styled modal: one line of code, and Playwright answers it.
