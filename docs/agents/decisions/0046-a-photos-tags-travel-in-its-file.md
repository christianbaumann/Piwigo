# 0046 — a photo's tags travel in its file, and a rescan only adds them

Date: 2026-10-09
Status: accepted

## Context

The remote never receives a database (decision 0023), so a tag assigned locally is lost there
unless the file carries it. photoinfo already rebuilds the date and info text on the remote from
the file (decision 0043). Core and the other plugins announce no tag change, and nothing wrote
keywords into a file before (plan 2026-10-09, Phases 4 and 6).

## Decision

- Every change to a photo's tags rewrites three fields of its file
  (`photoinfo_write_tags()` in `plugins/photoinfo/include/writer.inc.php`): `XMP-dc:Subject` and
  `IPTC:Keywords` with every tag name, `XMP-lr:HierarchicalSubject` with `Group|Tag` for every
  tag in a typetags group. A group whose name contains `|` gives no hierarchy entry. Each run
  replaces each field whole, and an empty list deletes it (`photoinfo_build_tags_argfile()`).
- Every tag write also sets `XMP-pwginfo:TagsWritten=1`, declared in `exiftool/pwginfo.config`.
  The marker separates "this file has no tags" from "no tag was ever written into this file".
- A tag named `Ausstellung` (`PHOTOINFO_LOCAL_ONLY_TAGS`), or any tag whose name contains `?`
  (`Kategorie ?`, `Name ?`), is never written, linked by a rescan, reported or pruned
  (`photoinfo_tag_is_local_only()`). The rule looks at the tag's own name, not its group.
- The paths that write are hooked in `include/events_tags.inc.php` and in the
  `pwg.images.setInfo` wrapper: the photo properties save, the Batch Manager's `add_tags` and
  `del_tags`, typetags' picture-page methods, the admin tags page's rename, duplicate, merge and
  delete, `typetags_tags_regrouped`, persons' `persons_tags_changed`, and `photoedit_end`.
- `pwg.photoinfo.rescan` reads the two XMP fields and the marker, never `IPTC:Keywords`, which
  exiftool cuts at 64 bytes. It skips a file without the marker, links the tags the file names
  that the photo lacks, creates a missing tag, and puts a tag that has no group into the group
  the hierarchy names, creating that group with `PHOTOINFO_RESCAN_GROUP_COLOR` if needed
  (`photoinfo_rescan_apply_tags()`). It never removes a link, writes no file and assigns no
  Freitext. It answers `tags_added` and, per photo, `not_in_file`: the tags the database has and
  the marked file does not name.
- Removal is `pwg.photoinfo.pruneTags` (admin only, POST, `pwg_token`, at most
  `PHOTOINFO_RESCAN_MAX_CHUNK` ids), which re-reads each file and deletes the `not_in_file`
  links (`photoinfo_prune_images()`). The deploy calls it only with `--prune-tags`, over the
  photos the rescan reported (`prune_photo_tags()` in `tools/deploy/pwgdeploy/bootstrap.py`).
  There is no admin button.
- Rescan and prune compare tags by id, never by name: `photoinfo_existing_tag_ids()` finds the
  tag `tag_id_from_tag_name()` would find, by name as MariaDB compares it, then by URL name. A
  byte comparison reported a tag renamed `Kirche` to `kirche` as missing, so every deploy with
  `--prune-tags` would have pruned and re-linked it.
- No one-time run writes the existing tags. A file gets its tags when that photo's tags are next
  changed.

## Consequences

- Until a photo's tags are changed once, its file has no marker and the remote keeps whatever
  tags it already holds for that photo.
- A tag removed locally stays on the remote until a deploy runs with `--prune-tags`. A plain
  deploy only names those photos in its report.
- Colours, the striped flag and emoji do not travel in the file; `tools/deploy/tag-groups.json`
  seeds them. A tag added later to the `Ausstellung` group under another name is written,
  because the rule checks the tag's name.
- A change touching many photos (a rename or a Batch Manager action over the gallery) runs one
  exiftool process per photo in one request. The rows are stored first, so a timeout leaves the
  database right and some files behind until those photos are edited again.
- Tag writes take both locks; see decision 0049. Uploads and core's IPTC import write nothing;
  see decision 0050.
- `TagWriteTest`, `RescanTest` and `PruneTagsTest` in `plugins/photoinfo/tests/Integration/`
  witness the paths, the marker and the merge-only rule.
