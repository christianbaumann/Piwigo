---
id: 32
dependencies: []
---

# Task 32: Upload or replace merges file and gallery field by field

After any upload or replace, the index matches the file and the file carries the gallery's data it lacked.

## References

* `design.md#an-upload-or-replace-merges-file-and-gallery-field-by-field`
* decisions 0020, 0050
* core `loc_end_add_uploaded_file` (`admin/include/functions_upload.inc.php:426`)

## Work

* [ ] persons and photoinfo listen to `loc_end_add_uploaded_file`
* [ ] Per field (regions, Info, date, keywords): file has it, reindex from file; file lacks it, write gallery value into file
* [ ] New upload indexes regions already in the file
* [ ] Decision superseding 0050 for replace

## Verification

* [ ] Integration: restore of a pre-edit copy, no stale frame, regions from the file
* [ ] Integration: replace with a bare scan keeps marks, Info, date, keywords and writes them into the file
* [ ] Integration: upload of a file with a region creates the mark
* [ ] persons and photoinfo suites pass
