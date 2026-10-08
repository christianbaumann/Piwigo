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

* [x] Characterize core's sync of `date_creation` as it is today (`[ERR]`), in provenance's core characterization tests, before adding the hook
  **Note:** `plugins/provenance/tests/Integration/CoreMetadataSyncCharacterizationTest.php`, 5 cases over `pwg.images.syncMetadata`, committed on its own (301ceb379). Each went red under one of three mutations of `admin/include/functions_metadata.php` (EXIF mapping emptied; `MASS_UPDATES_SKIP_EMPTY` dropped; file mtime written as `date_creation`). Every fixture carries `Make`/`Model`, so the hook leaves all five unchanged and none had to be replaced
* [x] `format_exif_data` handler: map the file name to its image row; if the photo has a photoinfo date, keep it; if the file has no `Make`/`Model`, drop `DateTimeOriginal`
  **Note:** `include/events_sync.inc.php` (`photoinfo_format_exif_data`, `photoinfo_file_has_date`), pure parts `photoinfo_sync_exif()` and `photoinfo_image_path()` in `include/functions.inc.php`. Drops whichever field `use_exif_mapping` maps to `date_creation`, not a hardcoded `DateTimeOriginal`. "Has a photoinfo date" = `photoinfo_date_precision IS NOT NULL`. A file no row points at (a video's representative) counts as having none. Dropping the field leaves the stored date alone, because `sync_metadata()` skips missing values (`MASS_UPDATES_SKIP_EMPTY`)
* [x] A date taken from a camera file sets precision `day` and no qualifier
  **Note:** nothing written: a `date_creation` with NULL precision already reads as an exact day (`photoinfo_date_from_row()`), and qualifier/end are NULL whenever precision is. Verified by `SyncMetadataTest::testACameraFilesDateIsTakenAsAnExactDay`
* [x] Find out and record what the hook sees for PNG and HEIC files (PHP cannot read their EXIF); cover the outcome with a test or a skipped test with its reason
  **Note:** measured 2026-10-08, PHP 8.4.24: `exif_read_data()` returns `false` for a PNG and for a HEIC (made with ImageMagick), both carrying `Make`/`Model`/`DateTimeOriginal` written by exiftool; JPEG reads fine. Core then calls the filter with `null`, which the handler passes on, so no date is taken. Covered by `SyncMetadataTest::testACameraDateInAFormatPhpCannotReadIsNotTaken` (PNG, HEIC; `[ERR]`, records PHP's behaviour) and provenance's `testAPngsDateTimeOriginalIsNotRead`
* [x] Tests: pure decision (photoinfo date / camera metadata / neither) as unit; sync over real files as integration
  **Note:** `tests/Unit/SyncExifTest.php` (decision table + path mapping, 15 cases); `tests/Integration/SyncMetadataTest.php` (7 cases). Integration watched red before the handler was registered (3 protection cases), and red again with the row lookup mutated (always false; `IS NOT NULL` → `IS NULL`). Unit mutants: `or`→`and`, no `trim`, prefix check removed, no trailing slash, `Model` ignored - all killed

## Verification

* [x] After a metadata sync, a photo with photoinfo date `ca. 1965` still shows `ca. 1965`
  **Note:** Verified via `SyncMetadataTest::testAQualifiedPhotoinfoDateSurvivesTheSync` (camera JPEG with its own date)
* [x] After a metadata sync, a photo with the range `1965–1970` keeps start, qualifier and end together: a sync that rewrote only `date_creation` would show an end before its start (found in task 04's review)
  **Note:** Verified via `SyncMetadataTest::testARangeSurvivesTheSync` (all five date columns unchanged)
* [x] A file with `DateTimeOriginal` but no `Make`/`Model` gets no date from a sync
  **Note:** Verified via `SyncMetadataTest::testAScansDateIsNotTaken`
* [x] A file with `Make`, `Model` and `DateTimeOriginal` gets that day as an exact date
  **Note:** Verified via `SyncMetadataTest::testACameraFilesDateIsTakenAsAnExactDay` (shows `4. Mai 2019`)
* [x] The filesystem date is never used
  **Note:** Verified via `SyncMetadataTest::testTheFileTimeIsNeverUsed` and provenance's `testTheFileTimeIsNeverUsed` (backdated mtime, no EXIF date → `date_creation` stays NULL)
* [x] Provenance (core characterization) and photoinfo suites pass twice in a row
  **Note:** 2026-10-08, full `phpunit --configuration plugins/<p>/phpunit.xml` twice: photoinfo 223 tests OK, provenance 400 tests OK (4 skipped, pre-existing); photoinfo also `--order-by=reverse` OK. E2E suites not run: no spec drives a sync

## Observations outside this task

* `$conf['use_iptc']` is `false` (core default, not overridden locally). If someone enables it, `get_sync_iptc_data()` maps IPTC `2#055` into `date_creation` **after** EXIF, and photoinfo writes `IPTC:DateCreated` itself (`1965:03:00`): core's `checkdate()` would then turn a month-precision start into `1965-01-01`. The `format_exif_data` hook does not see IPTC. Not handled; `get_iptc_data()` offers only `clean_iptc_value`, a per-value filter that is not told which field it cleans, so it would need a core change or a decision record
