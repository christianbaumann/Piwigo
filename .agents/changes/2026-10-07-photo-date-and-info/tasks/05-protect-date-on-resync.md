---
id: 05
dependencies:
- 03
---

# Task 05: Protect the date on metadata re-sync

A metadata sync never overwrites a date set with photoinfo, and never takes a date from a file
without camera metadata, so a scan date cannot replace "1965". A date from a camera or phone file
is still taken, as an exact day.

## References

* `design.md#never-write-datetimeoriginal-protect-the-date-on-re-sync`
* `design.md#a-files-own-date-counts-only-with-camera-metadata`
* `design.md#open-points-for-research-and-planning` (PNG and HEIC)
* `include/functions_metadata.inc.php:126-200` (`get_exif_data()`, `format_exif_data`)
* `admin/include/functions_metadata.php` (`sync_metadata()`, `date_creation` handling at :34 and :81)
* `include/config_default.inc.php:404` (`use_exif_mapping`)
* `docs/agents/research/2026-08-29-per-photo-freetext-field-and-metadata-writeback.md` (section B, Result 3)

## Work

* [ ] Characterize core's sync of `date_creation` as it is today (`[ERR]`), in provenance's core characterization tests, before adding the hook
* [ ] `format_exif_data` handler: map the file name to its image row; if the photo has a photoinfo date, keep it; if the file has no `Make`/`Model`, drop `DateTimeOriginal`
* [ ] A date taken from a camera file sets precision `day` and no qualifier
* [ ] Find out and record what the hook sees for PNG and HEIC files (PHP cannot read their EXIF); cover the outcome with a test or a skipped test with its reason
* [ ] Tests: pure decision (photoinfo date / camera metadata / neither) as unit; sync over real files as integration

## Verification

* [ ] After a metadata sync, a photo with photoinfo date `ca. 1965` still shows `ca. 1965`
* [ ] A file with `DateTimeOriginal` but no `Make`/`Model` gets no date from a sync
* [ ] A file with `Make`, `Model` and `DateTimeOriginal` gets that day as an exact date
* [ ] The filesystem date is never used
* [ ] Provenance (core characterization) and photoinfo suites pass twice in a row
