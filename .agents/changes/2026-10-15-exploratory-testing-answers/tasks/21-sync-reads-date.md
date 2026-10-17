---
id: 21
dependencies: []
---

# Task 21: The sync reads the photo's date from the file

A re-added file gets the date the photo page wrote into it.

## References

* `design.md#the-sync-reads-the-photos-date-from-the-file`
* decision 0043

## Work

* [x] On metadata sync, photoinfo reads `XMP-pwginfo:DateEDTF`, then `DateCreated`, into the photo's date
  **Note:** `photoinfo_format_exif_data()` (`include/events_sync.inc.php`) reads the photo's own file (the HEIC, not its representative) when the row has no photoinfo date; pure parts `photoinfo_sync_file_columns()`, `photoinfo_parse_sync_date_xml()`, `photoinfo_sync_mapping()` in `include/functions.inc.php`. Decision 0075.

## Verification

* [x] Integration: a dated file added by sync gets `date_creation` (and the partial date)
  **Note:** Verified via `SyncMetadataTest` (5 new cases: re-added file through site_update's metadata sync, DateCreated only, EDTF beats DateCreated and camera date, HEIC read from the original, simulated sync writes nothing) and 16 new `SyncExifTest` unit cases. Removing the mapping line from `main.inc.php` turned all 5 integration cases red.
* [x] photoinfo suites pass
  **Note:** Verified 2026-10-17: unit 288 OK, integration 219 OK, E2E 59 passed; provenance `CoreMetadataSync|CoreUpload` 9 OK.

## Design deviations

* photoinfo's four date columns are added to `$conf['use_exif_mapping']` (`main.inc.php`) so the
  sync's own row update writes them; writing them from inside the filter would ignore a
  simulated sync. Recorded in decision 0075.
* `XMP-photoshop:DateCreated` is taken as an exact date at its precision, time of day dropped. It
  is used only when the EDTF is missing or unreadable, and it comes before a camera's EXIF date.
