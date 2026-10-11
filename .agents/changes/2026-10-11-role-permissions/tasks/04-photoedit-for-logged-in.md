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

## Work

* [ ] Replace `is_webmaster()` with "logged in and not a guest" plus the 0044 visibility check in the button handler and in `pwg.photoedit.apply`
* [ ] Drop `admin_only` from the method registration
* [ ] Turn the existing admin/normal negative cases into positive cases; add a guest negative case and a private-album negative case
* [ ] Decision file superseding the webmaster-only part of 0034

## Verification

* [ ] `photoedit_normal` and `photoedit_admin` see the edit button and can save a turn; the file is turned
* [ ] They see the lost-region warning and the re-encode note when those apply
* [ ] A normal user on a photo in a private album without access gets no button and an error from the method
* [ ] Photoedit is still absent from `PLUGINS_TO_ACTIVATE`
* [ ] Photoedit unit, integration and E2E suites pass
