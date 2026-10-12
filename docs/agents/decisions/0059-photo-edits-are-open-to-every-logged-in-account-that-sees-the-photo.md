# 0059 — Turning and cropping is open to every logged-in account that may see the photo

Date: 2026-10-12
Status: accepted
Implements research decision 10 of
[the role permissions research](../research/2026-10-11-role-permissions.md). Supersedes point 3
("only a webmaster gets the edit button") of [0034](0034-photo-edits-change-the-file.md); the rest
of 0034 stands.

## Context

`plugins/photoedit` gave the edit button and `pwg.photoedit.apply` to the webmaster only:
`is_webmaster()` in `photoedit_picture_button()` and in the handler, `admin_only` on the method.
The owner wants every logged-in user to straighten and crop the scans. Since
[0055](0055-guests-are-locked-out.md) a guest sees neither the picture page nor the API.

## Decision

- One gate, `photoedit_user_may_edit()` (`include/pipeline.inc.php`): not a guest, and the photo
  passes core's own visibility rule (`get_sql_condition_FandF()` over forbidden and visible albums
  and forbidden images), the check [0044](0044-picture-page-tag-methods-respect-album-permissions.md)
  uses for tagging. The button and the method both ask it; the lost-region warning, the re-encode
  note and the disabled reason sit behind the button, so they follow it.
- `admin_only` is dropped from the method; `post_only` and the `pwg_token` check stay.
- The handler answers a guest `401 Access denied` (core's guest lockout already answers first), and
  a photo the caller may not see `404 No photo with this id`, the same answer as a missing photo.
- As in 0044, the rule holds for administrators and the webmaster too: a photo in a private album
  they were not granted, or in no album at all, cannot be edited through the method.
- Photoedit stays out of `PLUGINS_TO_ACTIVATE` (`tools/deploy/pwgdeploy/bootstrap.py`): it remains
  inactive on the remote, for the reason in [deployment.md](../../../.claude/rules/deployment.md).

## Consequences

- Any logged-in account can rewrite an image file it can see. The backup under
  `_data/photoedit/originals/` and git (for `galleries/`) remain the only way back.
- `photoedit_admin` and `photoedit_normal` turn from negative into positive cases:
  `PicturePageButtonTest`, `ApplyTurnTest::testOtherLoggedInAccountsMayTurn`, the dry-run cases in
  `ApplyJpegTest` and `ApplyRegionsTest`, `button-visibility.spec.js` and
  `other-accounts-save.spec.js`. The negative cases are now a guest and a private album without a
  grant (`testAGuestIsRefused`, `testAPhotoInAPrivateAlbumIsNotFound`, `testAGuestGetsNoButton`,
  `testAPhotoInAPrivateAlbumGetsNoButton`).
- The integration fixtures put every photo they send to the method into a public album
  (`FixtureBuilder::showInTestAlbum()`); a photo in no album is now refused.
