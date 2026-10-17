# 0075 — A metadata sync reads photoinfo's date from the file, through `use_exif_mapping`

Date: 2026-10-17
Status: accepted
Extends decision 0041

## Context

A file the photo page had dated was added again by the filesystem sync and got no
`date_creation` (SES-2026-10-14-18). The date was in the file (`XMP-pwginfo:DateEDTF`,
`XMP-photoshop:DateCreated`, decision 0040), but the sync reads EXIF only, and photoinfo's
`format_exif_data` handler (decision 0041) only protected a date already in the row. Only the
deploy's `pwg.photoinfo.rescan` (decision 0043) read it back.

The sync's row update names only the columns in `$conf['use_exif_mapping']` and
`$conf['use_iptc_mapping']` (`get_sync_metadata_attributes()`). `date_creation` alone, without
`photoinfo_date_precision`, would show `1965` as 1 January 1965.

## Decision

- photoinfo's `format_exif_data` handler, for a photo whose row has no photoinfo date, reads the
  photo's own file (the HEIC, not the JPEG representative core reads) with one exiftool call:
  `XMP-pwginfo:DateEDTF` when it is one of the five forms photoinfo writes, else the day, month or
  year of `XMP-photoshop:DateCreated` as an exact date, its time of day dropped. Without either,
  decision 0041's camera rule applies unchanged. A photoinfo date in the row still wins over the
  file.
- `main.inc.php` adds photoinfo's four date columns to `$conf['use_exif_mapping']`, each read from
  an EXIF field of its own name that only the handler fills (`photoinfo_sync_mapping()`). So the
  sync writes them in its own row update, next to `date_creation`: a simulated sync writes
  nothing, and "meta_empty_overrides" gets back the stored values from the handler.
- Rejected: a `pwg.photoinfo.rescan` after each sync, and relying on the deploy's rescan (design
  "The sync reads the photo's date from the file"). Also rejected: writing the columns from inside
  the handler, which a simulated sync (`admin/site_update.php`, `$simulate`) would not stop.

## Consequences

- Every metadata sync of a photo without a photoinfo date runs exiftool once on its file,
  uploads included (`add_uploaded_file()` syncs). Without exiftool or provenance the read gives
  nothing and the sync behaves as before.
- Decision 0015 keeps provenance's columns out of the mappings because the file would revert the
  database. Here the row wins whenever it holds a photoinfo date; the file is read only when the
  row has none.
- An EDTF the plugin does not write (`1965?`) is not reported by a sync, unlike the rescan; the
  sync falls back to `DateCreated` or takes no date.
- A camera file that another program gave an `XMP-photoshop:DateCreated` with a time gets that
  day, without its time, at day precision, ahead of its EXIF date; from then on it counts as a
  photoinfo date. Accepted: the design puts `DateCreated` before the camera date.
- Measured 2026-10-17: none of the 105 files in `galleries/` carries either tag, so no existing
  photo changes on its next sync.
