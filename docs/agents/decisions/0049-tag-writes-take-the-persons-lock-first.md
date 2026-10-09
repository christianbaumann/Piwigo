# 0049 — photoinfo's file writes take the persons lock first, then provenance's

Date: 2026-10-09
Status: accepted

## Context

photoinfo writes through `provenance_exiftool_run()`, which takes provenance's per-image lock
(decision 0043). persons writes the same files under a lock of its own, a different file, held
across the whole read-merge-write in `persons_apply_change()`, and its writes do not take
provenance's lock. Two exiftool processes writing one file destroy it. With tags in the file
(decision 0046), a persons region change and a photoinfo tag write now meet on every photo with
a face. provenance's lock is not re-entrant (`provenance_lock_acquire()`); persons' is, within one
request (`persons_lock_acquire()`).

## Decision

- Every photoinfo file write runs inside `photoinfo_with_persons_lock()`
  (`plugins/photoinfo/include/writer.inc.php`): the tag write (`photoinfo_write_tags_file()`)
  and the date and info text write (`photoinfo_write_file()`, plan 2026-10-09, **Q7a**). It
  takes persons' lock when persons is active (`PERSONS_LOCK_DIR` defined), then calls
  `provenance_exiftool_run()`, which takes provenance's. A persons lock timeout is answered with
  `PERSONS_LOCK_TIMEOUT_MESSAGE` and nothing is written.
- The tags are read inside both locks, so of two changes racing each other the later state
  reaches the file.
- The order is persons, then provenance, because photoedit takes them in that order:
  `persons_photoedit_begin` is registered at `EVENT_HANDLER_PRIORITY_NEUTRAL - 1`,
  `provenance_photoedit_begin` at `NEUTRAL`. Opposite orders could leave two requests each
  waiting for the other's lock until the timeout.
- While photoedit holds provenance's lock in the same request, photoinfo writes nothing
  (`photoinfo_photoedit_holds()`, reading `provenance_photoedit_held_locks()`): the lock is not
  re-entrant, so the write would wait for itself. `photoinfo_photoedit_end` runs at
  `NEUTRAL + 10`, after provenance and persons gave their locks back, and writes the tags only
  when the edit changed them, which happens when a crop cuts a face away
  (`persons_photoedit_move_regions()`).
- provenance's own write-back (`provenance_write_back()`) keeps taking only provenance's lock,
  and persons' region writes keep taking only persons'. Neither plugin learns about the other.

## Consequences

- A photoinfo write and a persons region change never overlap on one file.
- A provenance write-back and a persons region change still can. That was so before this plan
  and is left as it is; this decision adds no lock to either.
- A tag write waits up to persons' timeout for a region change on the same photo, then fails and
  is reported like any failed write; the database keeps the change.
- `persons_tags_changed` fires from `persons_apply_change()` once the change is done, and from
  `persons_rename_person()` and `persons_delete_person()` once for all their photos. A rescan
  or reindex fires nothing, so `pwg.persons.rescan` after a deploy writes no file.
- `TagWriteTest::testATagWriteWaitsForAHeldPersonsLock` (child process holds the lock) and
  `testAPhotoeditCropThatCutsAFaceRewritesTheKeywords` in `plugins/photoinfo/tests/Integration/`
  witness the lock and the photoedit handoff.
