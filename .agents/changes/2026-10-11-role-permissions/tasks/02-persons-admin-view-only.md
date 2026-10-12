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

* [x] Add a `persons_admin` role (administrator, not webmaster) to `TestUsers.php` and `create-test-users.php`; the script asserts the created status
  **Note:** level 8 like photoedit's admin (the script gave every non-webmaster level 0 before); `Config::adminUsername()/adminPassword()`
* [x] `ws_persons_rename`, `ws_persons_delete`, `ws_persons_rescan` refuse a caller who is not a webmaster
  **Note:** `401 Access denied` via `persons_webmaster_only_error()`, before the token check; `admin_only` kept
* [x] `admin/persons.php` hides rename, delete and rescan for a non-webmaster (`PERSONS_CAN_EDIT`)
* [x] Integration tests: admin refused on all three methods, webmaster allowed
  **Note:** `AdminOnlyTest::testAnAdministratorCannotRenameDeleteOrRescan`; webmaster paths already in `PersonAdminApiTest`; screen: `AdminPersonsScreenTest::testTheWebmasterGetsRenameAndDelete`, `testAnAdministratorSeesTheListButNoControls`
* [x] E2E: admin sees list and counts without the three controls; webmaster sees them (`admin-persons.spec.js`, new `auth-administrator.json` session)
* [x] Decision file: persons admin screen, admin sees, webmaster edits (`0057-...`)

## Verification

* [x] `persons_admin` calling `pwg.persons.rename` / `delete` / `rescan` gets an error and nothing changes in the DB or files
  **Note:** `AdminOnlyTest::testAnAdministratorCannotRenameDeleteOrRescan` asserts 401, person name, region count and file md5 unchanged; all three cases and the screen case went red with the `is_webmaster()` checks and `PERSONS_CAN_EDIT` mutated, green after revert
* [x] `persons_webmaster` can still rename, delete and rescan
  **Note:** `PersonAdminApiTest` (rename, delete, rescan) and the E2E rescan spec
* [x] `persons_admin` opens the persons screen and sees the same counts as `seed.php --person-counts`
  **Note:** E2E `admin-persons.spec.js` › an administrator who is not the webmaster, compared against `personCounts()`
* [x] The deploy's persons rescan still succeeds against the local DDEV install
  **Note:** `bootstrap.rescan_persons()` through `deploy.ddev.json` (login `typetags_webmaster`, a webmaster): `scanned=105, failed={}`; `galleries/` unchanged
* [x] Persons unit, integration and E2E suites pass
  **Note:** unit 163, integration 119, E2E 156 passed (2026-10-12); after the review fixes (distinct refusal message `PERSONS_WEBMASTER_ONLY_MESSAGE`, asserted so the test cannot pass on core's own `admin_only` 401) the unit suite, `AdminOnlyTest|AdminPersonsScreenTest|PersonAdminApiTest` and `admin-persons.spec.js` re-ran green
