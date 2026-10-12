---
id: 05
dependencies: []
---

# Task 05: Lounge emptying from a page view inherits in mode keep

When `check_lounge()` empties the lounge during any request, provenance gives the released photos the album values only where they have none, ignores request parameters and writes no history row.

## References

* `design.md#a-lounge-emptying-from-a-page-view-inherits-in-mode-keep`
* `include/common.inc.php:202` (`check_lounge()` before `include/user.inc.php`)
* `include/functions.inc.php` `check_lounge()`, `admin/include/functions.php` `empty_lounge()`
* `plugins/provenance/include/events_inherit.inc.php` `provenance_inherit_associated()`, `provenance_inherit_into()`
* `plugins/provenance/include/history.inc.php`

## Work

* [x] Detect "user not loaded yet" in the inheritance handler and force mode `keep` without a history row in that case; keep the decision in a pure function with a unit test
  **Note:** `provenance_inherit_policy()` in `plugins/provenance/include/functions.inc.php`, used by `provenance_inherit_into()` when no mode is passed; `provenance_write_inherited()` takes a history flag. Unit: `tests/Unit/InheritPolicyTest.php`
* [x] Integration: a photo in the lounge older than `lounge_max_duration` is released by a plain page request carrying a `replace`/`clear` parameter; it gets the album values, keeps existing values, and no history row appears
  **Note:** `InheritTest::testAPageViewThatEmptiesTheLoungeOnlyFillsPhotosWithoutProvenance` (data set `replace`, `clear`; POST to `index.php` as webmaster; forces `lounge_active`, restores it in tearDown). The second photo is put into `piwigo_lounge` directly: a second upload of the same bytes returns the same photo id
* [x] Integration: `pwg.images.uploadCompleted` as admin still inherits with history as today
  **Note:** `InheritTest::testUploadCompletedStillRecordsTheInheritanceAgainstTheAdministrator` (four `inherit` rows, `performed_by` = the uploading account)
* [x] Decision file: lounge inheritance from a page view
  **Note:** `docs/agents/decisions/0060-a-page-view-that-empties-the-lounge-inherits-in-keep.md`

## Verification

* [x] A page request that empties the lounge never replaces or clears existing provenance
  **Note:** page-view case, both data sets; red with the policy disabled (`if (!isset($user['id']))` -> `if (false)`)
* [x] A released photo without provenance gets the album values
  **Note:** page-view case, `$fresh` photo
* [x] No history row is written for the page-view emptying
  **Note:** page-view case; red with the history skip removed and with the policy's history flag forced to true
* [x] Admin upload completion behaviour is unchanged
  **Note:** uploadCompleted case plus the existing `InheritTest`/`CoreUploadCharacterizationTest` lounge cases
* [x] Provenance unit and integration suites pass, including the core characterization tests
  **Note:** 2026-10-12: unit 242 tests OK; integration 227 tests OK, 3 skipped (none in `InheritTest`); `InheritTest|CoreUpload` also green in reverse order
