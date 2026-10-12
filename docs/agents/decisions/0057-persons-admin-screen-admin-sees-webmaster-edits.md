# 0057 — The persons admin screen: administrators see, the webmaster edits

Date: 2026-10-12
Status: accepted
Implements research decision 8 of
[the role permissions research](../research/2026-10-11-role-permissions.md). Narrows
[0019](0019-person-region-permission-model.md) for the three methods that reach past one photo.

## Context

`pwg.persons.rename`, `pwg.persons.delete` and `pwg.persons.rescan` rewrite or re-read every file
a person (or a chunk of the gallery) touches. They were `admin_only`, so every administrator could
run them, and the persons screen (`admin/persons.php`) offered all three to every administrator.
The owner wants only the webmaster to change the index from there.

## Decision

- The three handlers refuse a caller who is not a webmaster with `401 Access denied`
  (`persons_webmaster_only_error()`, `include/ws_functions.inc.php`), before the token check.
  `admin_only` stays, so a normal user is still refused by core first.
- The screen stays open to every administrator. For a non-webmaster it renders the list, the
  counts and the search, and leaves out rename, delete and the rescan block
  (`PERSONS_CAN_EDIT`).
- The deploy's rescan is unaffected: it logs in with the account `install.php` created, which is a
  webmaster.

## Consequences

- A new test account, `persons_admin` (status `admin`), proves the gate is `is_webmaster()` and not
  `is_admin()`: `AdminOnlyTest::testAnAdministratorCannotRenameDeleteOrRescan`,
  `AdminPersonsScreenTest::testAnAdministratorSeesTheListButNoControls`, and the E2E
  `admin-persons.spec.js` case running as that account.
- A deploy whose credential file names a non-webmaster administrator gets the rescan refused.
