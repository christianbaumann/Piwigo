# 0016 — No history retention or purge in v1

Date: 2026-08-29
Status: accepted
Implements decision 6a of the provenance plan

## Context

`piwigo_provenance_history` records one row per **changed field per operation**, with the old and
new value inline (`old_value` / `new_value` are `TEXT`, deliberately, because
`piwigo_activity.details` is `varchar(255)` with nothing truncating before insert —
`include/functions.inc.php:648` — and provenance notes are unbounded free text).

It has no retention policy, no purge job, no row cap and no admin screen that deletes anything.
The table only ever grows.

The question is whether that is a defect worth fixing before the feature ships.

## Decision

**No purge in v1.** The table grows without bound and nothing trims it. The growth path is
recorded here and in `docs/agents/TESTING.md` rather than built.

The arithmetic is what makes this safe for this install. Rows are written only when a value
actually changes — an unchanged field writes nothing, which is a property the apply path is
tested for (`ApplyTest`: history rows exist for changed fields and not for unchanged ones), and
which the deterministic caption in `provenance_compose_caption()` exists to preserve for the
write-back path. So the worst realistic case is not "one row per operation" but "one row per
field per *edit*":

- a full apply over the largest album here (76 photos × 4 album-sourced columns) is 304 rows,
  and only on the first apply; a re-apply of unchanged values writes none;
- a write-back over the same album writes at most one truncation row per photo, and only for
  text over `PROVENANCE_IPTC_MAX_BYTES`;
- this is a private collection of scans of borrowed physical albums, edited by one person.

At that rate the table is thousands of rows over the life of the install, not millions. A purge
mechanism would be speculative machinery (`.claude/rules` — YAGNI) guarding a limit nothing is
approaching, and every purge is a chance to destroy the audit trail the feature exists to keep.

The audit trail is also the *point*. "Where did this scan come from, and who changed that answer"
is the question the feature was built to answer; a retention window is an expiry date on it.

## Consequences

- Nothing deletes provenance history. An administrator who wants a row gone deletes it in SQL,
  deliberately.
- `pwg.provenance.getHistory` pages rather than returning everything —
  `PROVENANCE_HISTORY_PER_PAGE_DEFAULT` (50) with `PROVENANCE_HISTORY_PER_PAGE_MAX` (500) as a
  clamp, not a refusal — so an unbounded table does not become an unbounded response.
- The `object_lookup` key is `(object, object_id, occured_on)`, so the per-object read stays
  indexed as the table grows; it is the whole-table scan that would degrade, and nothing does one.
- Deleting an album or a photo leaves its history rows behind, orphaned by `object_id`. That is
  deliberate for the same reason the table is not purged, and it is why `getHistory` is keyed on
  `(object, object_id)` rather than joined to the album or image tables.

## The growth path, if it is ever needed

In order of cost, so the cheapest sufficient step is taken rather than the whole list:

1. **Measure first.** `SELECT COUNT(*), SUM(LENGTH(old_value) + LENGTH(new_value))` — decide
   against a figure, not a feeling. As of 2026-08-29 the table holds 0 rows (`AUTO_INCREMENT=23`,
   the integration suite's rows having been rolled back).
2. **Cap the stored values, not the rows.** Store the first N bytes of `old_value` / `new_value`
   plus a length, keeping every row's *existence* and losing only the tail of long notes. The
   audit trail survives; the bytes are what grow.
3. **Archive by age**, never delete: `INSERT ... SELECT` into `piwigo_provenance_history_archive`
   and delete from the live table in one transaction, oldest first.
4. **Only then a retention window**, and it needs its own decision record superseding this one,
   because it is the first step that destroys evidence.

Not on the path: a row cap that drops the oldest row on insert. It makes the write path fail in
a way the writer cannot report, and it silently loses exactly the oldest — most historically
interesting — provenance.
