---
id: 03
dependencies: []
---

# Task 03: Date and info text editable by normal users

A logged-in normal user can edit a photo's date and info text on the picture page for any photo they can see; the value is written into the file as for admins.

## References

* `design.md#normal-users-edit-date-and-info-text-limited-by-album-visibility`
* `plugins/photoinfo/main.inc.php` (registration of `pwg.photoinfo.setDate` / `setInfo`)
* `plugins/photoinfo/include/` (picture-page rows and their edit gate)
* `docs/agents/decisions/0044-picture-page-tag-methods-respect-album-permissions.md`

## Work

* [x] Drop `admin_only` from `pwg.photoinfo.setDate` and `pwg.photoinfo.setInfo`; refuse guests
  **Note:** `photoinfo_ws_image()` refuses a guest with `401 Access denied` before the token check; core refuses first while `guest_access` is off
* [x] Add the 0044 album visibility check to both methods
  **Note:** `photoinfo_image_visible()`, same query as `typetags_image_visible()`; a hidden photo is `404 Invalid image_id` like a missing one. Holds for administrators too, as in 0044
* [x] Show the edit controls on the picture page to every logged-in non-guest (`$editable = !is_a_guest()`)
* [x] Integration: `photoinfo_normal` saves date and info and the file holds them; `photoinfo_normal` is refused on a photo in a private album it has no access to
  **Note:** `SetInfoTest`/`SetDateTest`: `testANormalUserMaySave`, `testAPhotoInAPrivateAlbumIsRefused` (normal and admin), `testAGrantOpensAPrivateAlbum`, `testAGuestIsRefused`; `SetInfoTest::testAGuestIsRefusedEvenWithGuestAccessOn`. `PicturePageSourceTest` read-only cases now run as a guest with a temporary `guest_access` config row. Save fixtures sit in a public album (`FixtureBuilder::placeInPublicAlbum()`), also in `RescanTest`
* [x] E2E: the normal account edits date and info on the picture page
  **Note:** the read-only normal-user specs in `info-edit.spec.js` and `date-edit.spec.js` became edit specs; `PicturePage.infoText`/`dateText` dropped (no user left)
* [x] Decision file: photoinfo editing for normal users (`0058-photoinfo-date-and-info-editable-by-logged-in-users.md`)

## Verification

* [x] `photoinfo_normal` sets a date and an info text; `--read-file` and `--read-date` show them in the file
  **Note:** E2E normal-user specs assert through `readFile()`/`readDate()` (`seed.php --read-file`/`--read-date`); integration `testANormalUserMaySave` in both classes
* [x] `photoinfo_normal` on a photo in a private album gets an error and the file is unchanged
  **Note:** `testAPhotoInAPrivateAlbumIsRefused` asserts 404, row unchanged, file md5 unchanged; red in all four cases with the visibility check removed, green after revert
* [x] Admin and webmaster behaviour is unchanged
  **Note:** on photos they can see: all existing webmaster and admin cases pass unchanged. One intended change from 0044: an administrator without a grant to a private album is now refused through the API (`testAPhotoInAPrivateAlbumIsRefused@administrator`); the picture page never showed them that photo
* [x] Photoinfo unit, integration and E2E suites pass
  **Note:** unit 268, integration 188, E2E 57 passed (2026-10-12). Mutants killed: visibility check removed (4 private-album cases), guest check removed (`testAGuestIsRefusedEvenWithGuestAccessOn`), `$editable = is_admin()` (4 normal-user page cases), `$editable = true` (7 guest page cases)
