# 0091 — The change log extends provenance's history

Date: 2026-11-08
Status: accepted
Change: `.agents/changes/2026-10-20-picture-page-edit-ux`

## Context

The picture page gets a list of who changed what on a photo (date, info, tags, persons, turn or
crop) and an undo for each entry. Core's `piwigo_activity` stores no old and new value, and records
date and info edits only as "photo edit".

## Decision

- The entries go into provenance's `piwigo_provenance_history`, written through
  `provenance_record_changes()` and read through `pwg.provenance.getHistory`. photoinfo already
  requires provenance, so no new dependency arises.
- New fields: `date`, `info`, `tag`, `region`, `photo`. New sources, one per edit path:
  `picture_page`, `admin_photo`, `batch_manager`, `api`, `undo`. The source enum widens through
  `widen_source_enum()` in `maintain.class.php`.
- New nullable column `undoes_id` (indexed). An undo is a new entry whose `undoes_id` names the
  original; an entry is undone when another entry points at it, derived in
  `provenance_fetch_history()` as `undone`. The log stays append-only.
- Tag and region entries store JSON in `old_value` / `new_value`
  (`{"id","name"}`; `{"name","box","drawn_by"}`), encoded and decoded by pure helpers in
  `history.inc.php`. Date and info stay plain text.
- `pwg.provenance.getHistory` drops `admin_only`: guests are refused, an administrator reads any
  object, any other account only an album or photo it can see (`provenance_may_read_history()`,
  the read-only page's rule, decision 0064). A non-admin gets exiftool's file error replaced by the
  generic message, as on the page.
- The schema change reaches an existing install through core's autoupdate: the plugin version
  goes to 1.1.0 and `install()` (called by `update()`) adds the column and index idempotently. It
  is additive, so a checkout still running the previous plugin version keeps writing to the table.

## Rejected

- Core's `piwigo_activity`: no values, nothing for tag or region changes.
- A new table in photoinfo or galleryrules: a second log for the same photo.
- An `undone_by` column updated on the original: breaks append-only. Matching undo entries by
  value: ambiguous.
- A second read method in photoinfo: two ways to read one log.

## Consequences

- provenance's read-only page and `RoleMatrixTest` see the new rows; the matrix row for history
  is now `see` for a normal account (was `see*`).
- Entries stay when a photo is deleted, as before.
- No database is transferred to the remote (decision 0023), so it shows no history for changes
  made locally.
