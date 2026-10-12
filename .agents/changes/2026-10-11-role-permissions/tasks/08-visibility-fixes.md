---
id: 08
dependencies: []
---

# Task 08: Persons visibility fixes and the writeBack no-fix

Logged-in users no longer see names of people who appear only on photos hidden from them, and the admin tagging screen no longer shows regions it cannot save.

## References

* `design.md#the-two-visibility-findings-are-fixed`
* `design.md#pwgprovenancewriteback-keeps-no-webmaster-check`
* `plugins/persons/include/ws_functions.inc.php:179` `ws_persons_getList` (188-195)
* `plugins/persons/admin/photo.php`
* `docs/agents/decisions/0044-picture-page-tag-methods-respect-album-permissions.md`

## Deviation from the design

* The `getList` filter applies with **and** without `image_id`. The picture-page picker is the plugin's only caller and always sends `image_id`, so filtering the bare call alone would leave the same names one parameter away.
* The filter leaves out persons whose regions are **all** on photos the caller cannot see, rather than keeping only persons with a visible region: a person with no region left is on no hidden photo and stays listed. The picker E2E specs use exactly that state for "a person tagged elsewhere" (`knownButNotOnPhoto()`).
* The design's premise "a screen that shows regions and then answers 404 on save" holds only for API calls without an admin Referer. Core reads an admin page's permissions, and those of a ws call referred by one, from `piwigo_user_cache` without rebuilding it (`include/user.inc.php`, `user_use_cache`), so the screen and its saves see the same list. The check was added anyway: with a current cache, an administrator without a grant now gets a message instead of the regions. A photo in no album gets the same message.

All three are recorded in decision 0061.

## Work

* [x] `pwg.persons.getList` without `image_id` lists only persons with at least one region on a photo the caller can see
  **Note:** with and without `image_id`, regionless persons kept, see *Deviation*; condition `persons_visible_images_sql()` in `include/index.inc.php`
* [x] `admin/photo.php` applies the same visibility check as the region write methods
  **Note:** `persons_user_can_see_image()` moved from `ws_functions.inc.php` to `index.inc.php`; refused photo renders a message (new string in both language files) and no stage
* [x] Integration tests for both, using a private album and `persons_normal` / `persons_admin`
  **Note:** six cases in `tests/Integration/VisibilityTest.php`; `FixtureBuilder::grantAlbumTo()` added; `AdminPhotoScreenTest::testTheAdminEditorDoesNotReloadOnExit` now puts its photo in a public album (an album-less photo is refused now)
* [x] Decision file: persons visibility fixes (`0061-persons-list-and-tagging-screen-respect-album-permissions.md`)
* [x] Decision file: `pwg.provenance.writeBack` keeps no webmaster check (admins may write back) (`0062-provenance-writeback-keeps-no-webmaster-check.md`)
  **Note:** verified ad hoc 2026-10-12: `persons_admin` gets `{"written":0,"failed":[]}` for an empty `image_ids`, `persons_normal` gets `401`

## Verification

* [x] A person whose only region is on a photo in a private album is missing from `persons_normal`'s `getList`, and present for a user with access
  **Note:** `VisibilityTest::testAPersonOnlyOnAHiddenPhotoIsNotListed`, `testThePickerDoesNotOfferAPersonOnlyOnAHiddenPhoto`, `testAPersonOnAHiddenPhotoIsListedOnceTheAlbumIsGranted`; went red with the filter disabled (`OR 1=1`)
* [x] `persons_admin` without access to the private album gets no regions on the admin tagging screen for that photo
  **Note:** `VisibilityTest::testTheAdminTaggingScreenShowsNoRegionsOfAPhotoTheAdministratorCannotSee` (red with the check disabled) and `...ShowsTheRegionsOnceTheAlbumIsGranted`
* [x] The picture-page name picker still lists the expected names
  **Note:** `SearchTest` (picker with `image_id`), `VisibilityTest::testAPersonWithNoRegionLeftIsStillListed`, and the E2E editor specs using `knownButNotOnPhoto()` pass
* [x] Persons unit, integration and E2E suites pass
  **Note:** 2026-10-12: unit 163 OK; integration 125 OK (1 skipped, already skipped before this task); E2E 155 passed, 1 failed: `picture-fit.spec.js:285` ("their labels line up with the other entries in the action menu"). It fails because photoedit's *Drehen/Zuschneiden* entry now shows for `persons_normal` (task 04) and has no icon, so its label sits 40 px left of the others. No file of this task is involved; left to task 04 / the main agent
