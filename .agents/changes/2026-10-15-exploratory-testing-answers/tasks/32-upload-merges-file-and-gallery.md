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

* [x] persons and photoinfo listen to `loc_end_add_uploaded_file`
* [x] Per field (regions, Info, date, keywords): file has it, reindex from file; file lacks it, write gallery value into file
* [x] New upload indexes regions already in the file
* [x] Decision superseding 0050 for replace

## Verification

* [x] Integration: restore of a pre-edit copy, no stale frame, regions from the file
  **Note:** Verified via `plugins/persons/tests/Integration/UploadMergeTest.php::testARestoredPreEditCopyIsIndexedFromItsFile`; watched red first (index stayed at the edited file's mark), green after.
* [x] Integration: replace with a bare scan keeps marks, Info, date, keywords and writes them into the file
  **Note:** Verified via `UploadMergeTest` in persons (`testABareReplaceKeepsTheMarksAndWritesThemIntoTheFile`, drawn_by kept) and photoinfo (`testABareReplaceWritesTheGalleryValuesIntoTheFile`, `testAPersonMarkedInTheGalleryReachesTheNewFileAsRegionAndKeyword`, file-wins and one-field-only cases); watched red first.
* [x] Integration: upload of a file with a region creates the mark
  **Note:** Verified via persons `UploadMergeTest::testAnUploadedFileWithAMarkCreatesIt` (through `pwg.images.addSimple`); a bare new upload gets none (`testAnUploadedFileWithoutAMarkGetsNone`).
* [x] persons and photoinfo suites pass
  **Note:** Verified: persons unit 218 OK, integration 218 tests OK (1 skipped, pre-existing); photoinfo unit 288 OK, integration 228 OK; also photoedit unit 107 / integration 109 and galleryrules unit 49 / integration 82 OK (2026-10-17).

* [x] Verification pass 2026-10-17: a marked-keyword replacement took a marked person's tag off the photo (bypassing task 18's guard); fixed in `photoinfo_merge_uploaded_tags()`, test `testAMarkedKeywordFileDoesNotTakeAMarkedPersonsTagOff` (watched red first); `testTheUploadStandsWhenExiftoolIsMissing` added for the failure path.

## Deviations

* Decision 0084 supersedes the upload part of 0050 for replace only; 0050's header status line carries a pointer, its text is unchanged.
* The merge applies to a new upload too (same code path), not only a replace: a new file's info text and date reach its row, as the rescan would read them. Keywords of a new upload with an API-linked tag set stay as 0050 says (tags are linked after the trigger).
* "File carries keywords" means the `XMP-pwginfo:TagsWritten` marker is present; then the file's tags are linked and the photo's other tags are taken off (same rule as rescan plus `--prune-tags`). An unmarked file gets the photo's tags written when it has any.
* A date is written into the file only when photoinfo set one (a stored precision); a camera date of the old file is not the photo's own.
* Characterization (passing against the old code, 2 cases each): `plugins/persons/tests/Integration/UploadReplaceCharacterizationTest.php`, `plugins/photoinfo/tests/Integration/UploadReplaceCharacterizationTest.php`, plus `upload()` in `plugins/photoinfo/tests/Support/WsClient.php`. They were run green, then removed from the tree (the new behaviour makes them red by design); copies are in the session scratchpad. Successors: persons `testABareReplace...`/`testAReplacementsOwnMark...` -> `UploadMergeTest` bare-replace and restored-copy cases; photoinfo -> `UploadMergeTest` bare-replace and file-wins cases.
