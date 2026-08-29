# 0013 — No album-delete provenance prompt in v1

Date: 2026-08-29
Status: accepted

## Context

Decision Q8 answered "ask the user" for what should happen to the provenance of photos that
survive an album's deletion. Phase 9 item 3 was to extend the existing `$photo_deletion_mode`
prompt with that choice.

Core offers **no pre-delete hook**. `trigger_notify('delete_categories', $ids)` fires at
`admin/include/functions.php:150`, after every row is already gone — the album's values and its
photo list no longer exist by the time a handler runs. `ws_categories_delete()`
(`include/ws_functions/pwg.categories.php:1145`) fires nothing, and neither does `admin/albums.php`.
Both delete prompts POST `pwg.categories.delete`.

A third fork-local trigger, `begin_delete_categories`, was agreed as the way in, and the
characterization net it required was written and committed first (`81f49176b`).

## Decision

**Item 3 is descoped from v1.** Phase 9 ships items 1 and 2 — the move mode and its Batch Manager
prompt — and closes without the album-delete prompt. The core patch was not made:
`admin/include/functions.php` is unmodified and this fork still carries exactly the two
fork-local triggers CLAUDE.md documents.

The current behaviour is the `keep` default and needs no code: deleting an album touches no
`piwigo_images` row, so photos that survive it keep the provenance they had. Nothing is lost by
not prompting; an administrator who wants those values gone clears them from the photos.

`PROVENANCE_HISTORY_SOURCE_ALBUM_DELETE` stays in `provenance_history_sources()` and in the
schema ENUM. No code path writes it today. It is left in place because removing it would mean a
second ENUM migration when the feature lands, and an unused enum value is cheaper than that.

## Consequences

- Three Phase 9 success criteria are left **unticked and unverified**, not passed: the
  album-delete `[ST]` case, `album_delete` `source` coverage, and both manual steps. They are
  recorded as descoped in the plan so nobody reads an empty box as an oversight.
- `CoreDeleteCategoriesCharacterizationTest` (6 `[ERR]` cases) stays. It documents what
  `delete_categories()` does today and is the regression net for the trigger whenever it is
  added.
- Carried in `docs/backlog.md`.
- Should it be picked up: add `trigger_notify('begin_delete_categories', ...)` after
  `get_subcat_ids()` in `delete_categories()`, and update CLAUDE.md, which currently states this
  fork has exactly two fork-local triggers.
