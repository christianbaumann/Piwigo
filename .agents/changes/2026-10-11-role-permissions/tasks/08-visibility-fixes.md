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

## Work

* [ ] `pwg.persons.getList` without `image_id` lists only persons with at least one region on a photo the caller can see
* [ ] `admin/photo.php` applies the same visibility check as the region write methods
* [ ] Integration tests for both, using a private album and `persons_normal` / `persons_admin`
* [ ] Decision file: persons visibility fixes
* [ ] Decision file: `pwg.provenance.writeBack` keeps no webmaster check (admins may write back)

## Verification

* [ ] A person whose only region is on a photo in a private album is missing from `persons_normal`'s `getList`, and present for a user with access
* [ ] `persons_admin` without access to the private album gets no regions on the admin tagging screen for that photo
* [ ] The picture-page name picker still lists the expected names
* [ ] Persons unit, integration and E2E suites pass
