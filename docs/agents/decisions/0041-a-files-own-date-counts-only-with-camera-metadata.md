# 0041 — A file's own date counts only with camera metadata, and a metadata sync keeps photoinfo's date

Date: 2026-10-08
Status: accepted

## Context

Core's metadata sync maps EXIF `DateTimeOriginal` into `date_creation`
(`$conf['use_exif_mapping']`). On a scan, every date the file carries is the scan date, wrong by
decades, and a sync would overwrite a `ca. 1965` set with photoinfo. Measured 2026-10-07: 0 of the
105 files in `galleries/` carry `Make`, `Model`, `Software` or `DateTimeOriginal`.

## Decision

- photoinfo's `format_exif_data` handler (`photoinfo_format_exif_data()`,
  `include/events_sync.inc.php`, deciding in `photoinfo_sync_exif()`, `include/functions.inc.php`)
  maps the file to its row and:
  - for a photo with a photoinfo date (`photoinfo_date_precision IS NOT NULL`) writes the stored
    date back into the mapped field, so neither "update" nor "empty overrides" can change it;
  - otherwise drops the date field unless the file carries `EXIF:Make` or `EXIF:Model`.
- A camera date that passes is taken as an exact day. The filesystem date is never used.
- The handler acts on whichever field `use_exif_mapping` maps to `date_creation`, not on a
  hardcoded `DateTimeOriginal`.

## Consequences

- A scanner that writes `Make`/`Model` passes the check; a camera image with stripped metadata gets
  no date.
- PHP's `exif_read_data()` returns `false` for PNG and HEIC (PHP 8.4.24, measured 2026-10-08), so no
  date is ever read from those; a HEIC's JPEG representative is read instead.
- Not handled: with `$conf['use_iptc']` enabled (it is `false` here), core maps `IPTC:DateCreated`
  after EXIF, outside this hook, and would turn a month-precision start into the first of January.
  Covering it needs a core change.
