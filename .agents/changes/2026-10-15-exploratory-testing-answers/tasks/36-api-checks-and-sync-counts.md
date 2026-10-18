---
id: 36
dependencies:
- 01
---

# Task 36: API checks and sync counts

Each API answer and sync counter agrees with what the server stored.

## References

* `design.md#api-checks-and-counts`

## Work

* [x] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [x] `pwg.categories.getList` returns the substitute's id as `representative_picture_id`
* [x] `pwg.images.setInfo` refuses a level outside `available_permission_levels`
* [x] `admin/site_update.php`: errors counted after the metadata pass; photos of removed folders counted as deleted
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [x] Integration: first `getList` after an admin change names no hidden photo
  * **Note:** Verified via provenance `CoreApiAnswersCharacterizationTest::testNoCallNamesTheHiddenRepresentative` and `testTheHiddenRepresentativesIdIsNotReturned` (full provenance integration suite, 408 tests OK, 3 skipped, 2026-10-17)
* [x] Integration: level 3 and 5 refused, row unchanged
  * **Note:** Verified via provenance `CoreApiAnswersCharacterizationTest::testSetInfoRefusesALevelOutsideTheAvailableOnes` (3 and 5) and photoinfo `CoreEditTest::testSetInfoRefusesALevelOutsideTheAvailableOnesThroughTheWrapper` (wrapper path)
* [x] Integration: counter equals listed errors; removed folders report their photos, simulation and real
  * **Note:** Verified via provenance `CoreSiteUpdateCountsCharacterizationTest` (`testAnErrorOfTheMetadataPassIsCounted`, `testARemovedFolderCountsItsPhotosAsDeleted`, `testASimulatedRemovedFolderCountsItsPhotos`, `testARemovedFolderAndARemovedFileAreBothCounted`)
* [x] provenance suites pass
  * **Note:** Verified via provenance unit (307 OK), provenance integration (408 tests, 2540 assertions, 3 skipped), photoinfo unit (288 OK) and photoinfo integration (228 OK), all under `flock /tmp/pwg-suite.lock`

## Deviations

* `level` above the maximum (9) is not refused by the new check: `ws.php` registers `pwg.images.setInfo`'s `level` with `maxValue` = the highest offered level, so the framework clamps it to 8 before the handler runs. Recorded as an `[ERR]` case (`testALevelAboveTheLastIsClampedByTheMethodRegistration`). Only values between offered levels (3, 5, 6, 7) reach the new check.
* Same `$info_columns` block (with `level`) also exists in the upload checker/`addSimple` area (`pwg.images.php` ~1618); not changed, out of the task.
* The removed-folder count is the photos stored (`storage_category_id`) in the removed folders; `delete_categories()` also deletes a photo stored elsewhere whose only links were in a removed album, which is not counted (rare, left).
* Verified (2026-10-17): the two pre-change test files pass (12 tests) against HEAD's three core files, so the characterized behaviour is the unchanged one.
* `getList` answers `representative_picture_id: null` when no visible substitute exists (previously the hidden id).
* The removed-folder fixture is a sub-folder of a throwaway album: a sync limited to an album (`cat`) always re-adds that album's own folder, so it never deletes it.
* The characterization and requirement cases share two files (`CoreApiAnswersCharacterizationTest`, `CoreSiteUpdateCountsCharacterizationTest`); [REQ] cases replaced the [ERR] defect cases. Pre-change versions are kept in the session scratchpad `task36-characterization/`.
* No decision file written (the design records the decision).

