---
id: 06
dependencies:
- 03
---

# Task 06: Core admin screens keep database and file in agreement

A date or description changed in core's photo edit screen or in the Batch Manager reaches the
plugin columns and the image file, so no screen can make them disagree.

## References

* `design.md#core-admin-screens-set-an-exact-date`
* `design.md#core-description-edits-rewrite-the-caption`
* `admin/picture_modify.php:23-181` (`date_creation`, `comment`)
* `admin/batch_manager_global.php:294-321` (`date_creation` action), and its description action if present
* `include/ws_functions/pwg.images.php` (`pwg.images.setInfo`, same fields over the API)
* `plugins/provenance/include/events_admin.inc.php` (`loc_begin_admin_page` hooks on these screens)

## Work

* [x] Characterize the save of `date_creation` and `comment` on each path as it is today (`[ERR]`), before hooking
  **Note:** the photo properties screen was already covered (`CorePhotoTextCharacterizationTest`). New: `plugins/provenance/tests/Integration/CoreDateAndCommentSaveCharacterizationTest.php`, 7 cases over `pwg.images.setInfo` (replace, fill_if_empty, stored values posted back, empty values, markup without a token) and the Batch Manager's global date action (set, remove), committed on its own (2608a9cac). Each went red under a core mutant: `date_creation` dropped from setInfo's columns, `fill_if_empty` always filling, the allowed-tags list emptied, `isset`→`!empty`, the time of day cut off on replace, the global remove keeping the posted date, the global set writing NULL. The first of these showed the empty-value case had no anti-vacuity guard; added. Found: an empty value **does** clear a field through setInfo, so the unit mode can remove a date
* [x] Find the event each path offers after saving (or the narrowest hook available); record it
  **Note:** [decision 0039](../../../../docs/agents/decisions/0039-core-screens-reach-photoinfo-through-their-own-events.md). Properties screen: `picture_modify_before_update` (snapshot) + `loc_end_picture_modify`. Batch Manager global: `element_set_global_action`. `pwg.images.setInfo` (the unit mode saves only through it) has no event after the save, so photoinfo re-registers the method around core's `ws_images_setInfo()` on `ws_add_methods` at priority neutral + 10. Handlers in `include/events_core_edit.inc.php`
* [x] Date saved there: precision `day`, no qualifier, no end date; file written as in task 03
  **Note:** pure decision `photoinfo_core_edit()` in `include/functions.inc.php`. Only a date that **differs** from the stored one counts: both screens post every field on every save, so a new title must not turn `ca. 1965` into an exact date. Core's `date_creation` is kept as stored, time included
* [x] Description saved there: composed caption and `XMP-pwginfo:Info` rewritten as in task 02
  **Note:** a core save writes everything in one exiftool run (`PHOTOINFO_WRITE_ALL`, `photoinfo_build_full_argfile()`): caption, `XMP-pwginfo:Info`, the three date tags
* [x] Removing the date there clears the plugin columns and the file's date tags
  **Note:** Verified via `CoreEditTest::testRemovingThePropertiesDateClearsColumnsAndFile` and `testABatchRemovalClearsEveryFile`
* [x] Tests: integration per save path
  **Note:** `tests/Integration/CoreEditTest.php` (11 cases: 7 properties screen, 2 Batch Manager global, 2 setInfo), `tests/Unit/CoreEditDecisionTest.php` (8-row decision table + 2), 2 cases in `BuildArgfileTest`. Mutants, all killed - unit: outright-set check removed, date compare flipped, write ignoring a date change, `date_creation` left among the columns, full argfile without date lines; integration: properties handler unregistered, global action check inverted, wrapper at core's priority (core's registration wins), properties snapshot ignored, `WRITE_ALL` without date lines, column update removed, setInfo snapshot ignored

## Verification

* [x] A date set in the photo edit screen shows as `14. März 1965` on the picture page and is in the file
  **Note:** Verified via `CoreEditTest::testAPropertiesDateIsAnExactDayInRowAndFile`: precision `day` with no qualifier in the row, `14. März 1965` heading every caption slot, the three date tags. The page renders the row through the same `photoinfo_dating_display()` (`PicturePageSourceTest::testTheDateShowsInTheDatumRow`), so no page fetch was added
* [x] A date set for several photos in the Batch Manager reaches each photo's file
  **Note:** Verified via `CoreEditTest::testABatchDateReachesEveryFile`
* [x] A description changed in the photo edit screen is first in each file caption
  **Note:** Verified via `CoreEditTest::testAPropertiesDescriptionLeadsTheCaption` (ahead of a real provenance write-back)
* [x] A photo with `ca. 1965` whose date is changed in core shows the new exact date, without `ca.`
  **Note:** Verified via `CoreEditTest::testAPropertiesDateReplacesAQualifiedOne`, `testABatchDateReachesEveryFile`, `testSetInfoWritesDateAndDescription`; the converse (unchanged date keeps `ca.`) via `testAnUnchangedPropertiesDateKeepsTheQualifier` and `testSetInfoWithTheStoredValuesKeepsTheQualifier`
* [x] A photo with the range `1965–1970` whose date is changed in core loses its end too (`photoinfo_date_end` and its precision NULL), never showing `2019–1970` (found in task 04's review)
  **Note:** Verified via `CoreEditTest::testAPropertiesDateEndsARange`
* [x] A photo saved as `1965` (precision `year`) whose date is set to 14.03.1965 in core shows `14. März 1965`, not `1965`: until this task hooks core's save paths, the plugin's precision outlives a core edit and silently drops the day (found in task 03's review)
  **Note:** Verified via `CoreEditTest::testAPropertiesDateRaisesAYearToADay`. Limit, recorded in decision 0039: setting such a photo to 01.01.1965 is no change (same `date_creation`), so it stays `1965`
* [x] photoinfo and provenance suites pass twice in a row
  **Note:** 2026-10-08, full `phpunit --configuration plugins/<p>/phpunit.xml` twice: photoinfo 257 tests OK, provenance 407 tests OK (4 skipped, pre-existing); photoinfo also `--order-by=reverse` OK. photoinfo E2E (`npx playwright test`) 30 passed, since the WS row loader moved into `photoinfo_image_row()`

## Observations outside this task

* `pwg.images.setInfo` now answers `image_id`, `written`, `message` instead of core's `null` whenever a file was written; the Batch Manager's unit mode checks only `stat`, so a failed write shows nowhere in that screen (the properties screen and the global action show it as an error message)
* The legacy form-POST save in `admin/batch_manager_unit.php` is not hooked: no button posts it (decision 0039)
