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

* [ ] Drop `admin_only` from `pwg.photoinfo.setDate` and `pwg.photoinfo.setInfo`; refuse guests
* [ ] Add the 0044 album visibility check to both methods
* [ ] Show the edit controls on the picture page to every logged-in non-guest
* [ ] Integration: `photoinfo_normal` saves date and info and the file holds them; `photoinfo_normal` is refused on a photo in a private album it has no access to
* [ ] E2E: the normal account edits date and info on the picture page
* [ ] Decision file: photoinfo editing for normal users

## Verification

* [ ] `photoinfo_normal` sets a date and an info text; `--read-file` and `--read-date` show them in the file
* [ ] `photoinfo_normal` on a photo in a private album gets an error and the file is unchanged
* [ ] Admin and webmaster behaviour is unchanged
* [ ] Photoinfo unit, integration and E2E suites pass
