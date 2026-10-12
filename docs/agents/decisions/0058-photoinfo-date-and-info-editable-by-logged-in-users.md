# 0058 — Every logged-in account edits a photo's date and info text, on a photo it can see

Date: 2026-10-12
Status: accepted
Implements research decision 9 of
[the role permissions research](../research/2026-10-11-role-permissions.md). Applies
[0044](0044-picture-page-tag-methods-respect-album-permissions.md) to photoinfo's two save methods.

## Context

`pwg.photoinfo.setDate` and `pwg.photoinfo.setInfo` were `admin_only`, and the picture page gave
the Datum and Info editors to administrators only (`is_admin()`). The owner wants every logged-in
account to date and describe the photos it can see. Without a visibility check, opening the methods
would let any account write the file of a photo in a private album by its id.

## Decision

- Both methods lose `admin_only` (`main.inc.php`). Their shared gate, `photoinfo_ws_image()`
  (`include/ws_functions.inc.php`), refuses a guest with `401 Access denied` before the token
  check. Core refuses a guest first while `guest_access` is off
  ([0055](0055-guests-are-locked-out.md)); the plugin's check holds when it is on.
- A photo the caller cannot see is answered `404 Invalid image_id`, exactly like a missing one
  (`photoinfo_image_visible()`, core's `get_sql_condition_FandF()`, the same query as typetags').
  As in 0044 this holds for administrators too: an administrator without a grant to a private album
  can no longer save there through the API. The admin screens are not affected; they go through
  core's own methods.
- The picture page gives the editors to every account that is not a guest (`!is_a_guest()`,
  `include/events_public.inc.php`). Whoever reaches the page can see the photo, so the page needs
  no visibility check of its own.
- `pwg.photoinfo.rescan` and `pwg.photoinfo.pruneTags` stay `admin_only`.

## Consequences

- The read-only rows, including the date's calendar link, are a guest's alone, and a guest reaches
  the picture page only with `guest_access` on. `PicturePageSourceTest` covers them by writing a
  `guest_access` config row for the guest cases and deleting it in `tearDown`; a run killed in
  between leaves the gallery open to guests until the row is deleted by hand. `SetInfoTest`'s
  `testAGuestIsRefusedEvenWithGuestAccessOn` does the same around one request.
- `photoinfo_normal` changes from a refused account to a saving one: `SetInfoTest` and
  `SetDateTest` `testANormalUserMaySave`, `testAPhotoInAPrivateAlbumIsRefused` (normal user and
  administrator), `testAGrantOpensAPrivateAlbum`, and the normal-user specs in `info-edit.spec.js`
  and `date-edit.spec.js`.
- The save tests' photos now sit in a public album of their own (`FixtureBuilder::placeInPublicAlbum()`):
  a photo in no album is visible to nobody.
