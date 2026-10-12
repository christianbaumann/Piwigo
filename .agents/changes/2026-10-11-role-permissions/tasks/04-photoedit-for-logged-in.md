---
id: 04
dependencies: []
---

# Task 04: Turn and crop for every logged-in user who can see the photo

Normal users, admins and webmasters can turn and crop a photo they can see, with the lost-region warning, the re-encode note and the disabled reason.

## References

* `design.md#turn-and-crop-open-to-every-logged-in-user-who-can-see-the-photo`
* `plugins/photoedit/include/events_public.inc.php:17`, `plugins/photoedit/include/ws_functions.inc.php:21`, `plugins/photoedit/main.inc.php:80`
* `docs/agents/decisions/0034-photo-edits-change-the-file.md`, `0044-picture-page-tag-methods-respect-album-permissions.md`
* `tools/deploy/pwgdeploy/bootstrap.py` `PLUGINS_TO_ACTIVATE` (photoedit stays inactive on the remote)

## Deviation from the design

A photo in no album, which only the API could reach, is now refused to every account, the webmaster included: core's visibility rule finds no album that shows it. 0044 already treats it the same. The integration fixtures that sent such photos to the method now put each one in a public album (`FixtureBuilder::showInTestAlbum()`). Recorded in decision 0059.

## Work

* [x] Replace `is_webmaster()` with "logged in and not a guest" plus the 0044 visibility check in the button handler and in `pwg.photoedit.apply`
  **Note:** one gate, `photoedit_user_may_edit()` in `include/pipeline.inc.php`; the handler answers a guest 401 and an unseen photo `404 No photo with this id` (`PHOTOEDIT_NOT_FOUND_MESSAGE`, same as a missing one)
* [x] Drop `admin_only` from the method registration
* [x] Turn the existing admin/normal negative cases into positive cases; add a guest negative case and a private-album negative case
  **Note:** `PicturePageButtonTest`, `ApplyTurnTest`, new dry-run cases in `ApplyJpegTest`/`ApplyRegionsTest`, `button-visibility.spec.js`, new `other-accounts-save.spec.js`; granted-album `[ECP]` cases prove the gate is the album, not the role
* [x] Decision file superseding the webmaster-only part of 0034 (`0059-photo-edits-are-open-to-every-logged-in-account-that-sees-the-photo.md`)
* [x] Handbook `06-drehen.html`: who may turn and crop; `check.php` OK

## Verification

* [x] `photoedit_normal` and `photoedit_admin` see the edit button and can save a turn; the file is turned
  **Note:** `PicturePageButtonTest::testEveryLoggedInAccountGetsTheButton`, `ApplyTurnTest::testOtherLoggedInAccountsMayTurn` (ImageMagick reads the turned size), `button-visibility.spec.js`, `other-accounts-save.spec.js`; all go red with the gates put back to `is_webmaster()`
* [x] They see the lost-region warning and the re-encode note when those apply
  **Note:** server side `ApplyRegionsTest::testAnotherAccountsDryRunNamesTheLostRegion`, `ApplyJpegTest::testAnotherAccountsDryRunReportsTheReencode`; browser side `other-accounts-save.spec.js` (both roles, both dialogs). The disabled reason is assigned after the gate in the same handler, so it follows it; its own case (`testAGifIsRefusedAndTheButtonSaysWhy`) still runs as webmaster
* [x] A normal user on a photo in a private album without access gets no button and an error from the method
  **Note:** `ApplyTurnTest::testAPhotoInAPrivateAlbumIsNotFound` (404, file unchanged; red with the visibility query forced true), `PicturePageButtonTest::testAPhotoInAPrivateAlbumGetsNoButton` (core's `picture.php` refuses the page before the button handler runs, so that case does not witness the plugin's own check)
* [x] Photoedit is still absent from `PLUGINS_TO_ACTIVATE`
  **Note:** Verified via grep: `("typetags", "provenance", "persons", "photoinfo")`, `tools/deploy/pwgdeploy/bootstrap.py:45`
* [x] Photoedit unit, integration and E2E suites pass
  **Note:** 2026-10-12: unit OK (95 tests), integration OK (91 tests, 500 assertions), E2E 31 passed
