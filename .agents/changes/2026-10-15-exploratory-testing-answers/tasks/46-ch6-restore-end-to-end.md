---
id: 46
dependencies:
- 31
---

# Task 46: Handbook ch6's restore path, run end to end

Added 2026-10-17 (user's answer a). Task 31 checked ch6's restore path against the code only.

## References

* Task 31, `handbuch/06-drehen.html` (*Rückgängig machen*), decision 0072
* photoedit `tests/e2e/support/seed.php --scenario=marked`, `CopiesTest`

## Work

* [x] On a throwaway photo: edit it, take the kept copy from `_data/photoedit/originals/`, rename it like the photo's file, upload with *Fotos hinzufügen* update switch into the photo's album
* [x] Record the result (automated test if cheap, else a dated ledger row in `docs/agents/TESTING.md`); fix ch6 if a step is wrong

## Result

`CopiesTest::testRestoringTheKeptCopyThroughAReplaceBringsBackTheOldFile` (photoedit integration): edits a marked fixture through `pwg.photoedit.apply`, uploads its kept copy renamed to the photo's `file` through `pwg.images.upload` with `update_mode` into the photo's album, gives the photo an Info text first, asserts the restored file's pixel signature (`identify -format %#`) equals the pre-edit one and the Info text is in the file (task 32's merge, decision 0084, writes the gallery's Info text into a replaced file, so the whole-file md5 differs; checked 2026-10-18), columns id/file/path/name/comment/author/date_available/level unchanged, and a second copy kept. Passed first run; proven able to fail by uploading the edited file instead of the copy (red: "the file is not the pre-edit file"), then reverted (red: "the image is not the pre-edit image"). Ch6 prose needed no change. The browser page sends the same request; the page itself is covered by task 27.

## Verification

* [x] The photo's file equals the pre-edit bytes, the photo keeps its id and properties
* [x] `check.php` passes; photoedit suites pass

Notes: photoedit unit 107 tests OK, integration 110 tests OK (2026-10-17); re-run after the pixel-signature change 2026-10-18: integration 110 tests OK, `check.php` OK. Counts before 6 / 107, after 5 / 106 (the extra album and photo at the start were another agent's live fixture; the suite destroys its own and the install is back at 5 albums / 106 photos).

## Deviations

None. No Playwright case: the request is identical to the one the update switch sends.
