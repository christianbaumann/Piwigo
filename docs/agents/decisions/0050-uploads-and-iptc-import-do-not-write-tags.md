# 0050 — uploads and core's IPTC keyword import write no tags into the file

Date: 2026-10-09
Status: accepted

## Context

Decision 0046 writes a photo's tags into its file on every path that changes them, with three
exceptions where core sets tags without any event a plugin could act on:

- Uploads. `pwg.images.add`, `pwg.images.addSimple` and `pwg.images.uploadAsync`
  (`include/ws_functions/pwg.images.php`) link the tags they were given after
  `add_uploaded_file()` has fired `loc_end_add_uploaded_file`. Core's upload form
  (`admin/themes/default/template/photos_add_direct.tpl`) sends no tags.
- Core's metadata sync with `$conf['use_iptc']` on: `sync_metadata()`
  (`admin/include/functions_metadata.php`) replaces all of a photo's tags with its IPTC
  keywords through `set_tags_of()`.
- `admin/site_update.php`'s metadata step, which reads the same keywords through
  `get_sync_metadata()` when `use_iptc` is on and calls `set_tags_of()` the same way.

`use_iptc` is `false` in `include/config_default.inc.php`, and neither `local/config/config.inc.php`
nor the config the deploy generates sets it.

## Decision

- No hook on any of the three. A photo whose tags were set there reaches its file the next time
  its tags change through a hooked path.
- `use_iptc` stays off. It is not guarded in code.

## Consequences

- A photo uploaded with tags through the API has them in the database and not in the file, and
  its file has no `XMP-pwginfo:TagsWritten` marker, so a rescan leaves it alone (decision 0046).
- Turning `use_iptc` on would make a metadata sync replace each photo's tags with its
  `IPTC:Keywords`: the tag `Ausstellung` and every tag with `?` in its name would be removed,
  because they are never written (`photoinfo_tag_is_local_only()`), keywords longer than 64 bytes would come
  back cut by exiftool, and new tags would arrive without a group. Revisit this decision before
  turning it on.
- No automated test covers these three paths; they are listed as deliberate non-coverage in
  `docs/agents/TESTING.md`.
