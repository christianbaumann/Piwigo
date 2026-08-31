# 0020 — The persons tables are a derived index; the image file is the source of truth

Date: 2026-08-31
Status: accepted
Supersedes nothing. Records answer 1 of the *Open Questions* in
[`docs/agents/research/2026-08-29-person-face-tagging.md`](../research/2026-08-29-person-face-tagging.md).

## Context

`plugins/persons` had to put the regions somewhere. Two shapes were available, and the fork already
contains one of each:

- `plugins/provenance` keeps its fields in database columns and treats the file as an *export*
  target — the database is authoritative, the file is a copy.
- Both maintained Piwigo face extensions (Face Tag, Face Tag Editor), digiKam, Lightroom and every
  other reader that reads regions at all read them out of the file.

Storing regions in the database as well would mean two writable copies of one fact, and no rule for
which wins when a photo is edited in digiKam and then viewed in Piwigo.

## Decision

`XMP-mwg-rs:RegionInfo` in the image file is the single source of truth for who is in a photo and
where. `piwigo_persons` and `piwigo_person_region` are a **derived index**, rebuildable from the
files at any time, and are never read to answer "what does this file hold" — only to answer "which
files hold person N", which the files cannot answer without opening all of them.

Concretely:

- Every write goes to the file first (`persons_apply_change()`, under the per-image lock), and the
  index is refreshed from what the file holds afterwards — never from what the caller asked for.
- `pwg.persons.rescan` rebuilds the whole index by reading every file. Dropping both tables and
  rescanning is a supported repair, not a disaster recovery hack;
  `IndexRebuildTest::testDroppingTheTablesAndRescanningRebuildsTheIndex` exercises exactly that.
- The mirrored `piwigo_tags` row per person is part of the derived layer too, so core browsing
  works with no changes to core.
- Region rows are removed when a photo is deleted (`delete_elements`), and corrected on reindex
  when the file turns out to have been physically rotated.

## Consequences

**A drift window exists, and it is accepted.** Piwigo is notified of nothing that happens to a file
outside it. Between an external edit — digiKam adds a face, someone strips the XMP, a file is
replaced over FTP — and the next `persons_reindex_image()` for that photo, the index disagrees with
the file. Nothing detects it; the index simply stays as it was until a rescan or the next write to
that photo through the plugin.

This is the same class of gap already recorded for `plugins/provenance`
([decision 0015](0015-provenance-columns-stay-out-of-the-metadata-mappings.md)), and it has the
same candidate signal, `images.date_metadata_update`, carried in `docs/backlog.md`. The direction
if it is ever closed: compare that timestamp against the index's own, per photo, and reindex the
photos that moved — not a periodic full rescan, which reads every file to find the handful that
changed.

**Failure loses data outright.** With no second copy, a failed or partial merge cannot be repaired
from the database. That is why every write merges the existing `RegionList` rather than replacing
it ([answer 7 in the research](../research/2026-08-29-person-face-tagging.md)), holds the lock
across the whole read-merge-write, and leaves exiftool's `_original` sidecar beside the image — the
only pre-write bytes there are.

**An unwritable file simply cannot carry persons.** There is no database fallback to degrade into,
so the UI says so instead of failing on save.
