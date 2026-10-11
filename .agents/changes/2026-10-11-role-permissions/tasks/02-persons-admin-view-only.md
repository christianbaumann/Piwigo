---
id: 02
dependencies: []
---

# Task 02: Persons admin screen view-only for admins

An administrator who is not a webmaster sees the persons list and counts but cannot rename, delete or rescan; the webmaster keeps all three.

## References

* `design.md#the-persons-admin-screen-becomes-view-only-for-admins`
* `design.md#test-accounts-reuse-the-existing-roles-plus-one-for-persons`
* `plugins/persons/admin/persons.php`
* `plugins/persons/include/ws_functions.inc.php` `ws_persons_rename`, `ws_persons_delete`, `ws_persons_rescan`
* `plugins/persons/tests/Support/TestUsers.php`, `plugins/persons/tests/Support/create-test-users.php`
* `tools/deploy/pwgdeploy/bootstrap.py` (rescan runs as the install webmaster)

## Work

* [ ] Add a `persons_admin` role (administrator, not webmaster) to `TestUsers.php` and `create-test-users.php`; the script asserts the created status
* [ ] `ws_persons_rename`, `ws_persons_delete`, `ws_persons_rescan` refuse a caller who is not a webmaster
* [ ] `admin/persons.php` hides rename, delete and rescan for a non-webmaster
* [ ] Integration tests: admin refused on all three methods, webmaster allowed
* [ ] E2E: admin sees list and counts without the three controls; webmaster sees them
* [ ] Decision file: persons admin screen, admin sees, webmaster edits

## Verification

* [ ] `persons_admin` calling `pwg.persons.rename` / `delete` / `rescan` gets an error and nothing changes in the DB or files
* [ ] `persons_webmaster` can still rename, delete and rescan
* [ ] `persons_admin` opens the persons screen and sees the same counts as `seed.php --person-counts`
* [ ] The deploy's persons rescan still succeeds against the local DDEV install
* [ ] Persons unit, integration and E2E suites pass
