# 0066 — The role matrix witnesses the administrator's write-back

Date: 2026-10-12
Status: accepted
Supersedes the "checked by hand" consequence of
[0062-provenance-writeback-keeps-no-webmaster-check.md](0062-provenance-writeback-keeps-no-webmaster-check.md);
its decision (no webmaster check on `pwg.provenance.writeBack`) stands. Task 01 of
`.agents/changes/2026-10-12-role-test-coverage`.

## Context

0062 left the write-back as a non-webmaster administrator to a hand check, because provenance had
no such test account: `persons_admin` called the method once on 2026-10-12. A hand check is done
once. A later change to the gate (`admin_only` → an `is_webmaster()` check) would fail no test.

## Decision

- provenance gets two more test accounts, `provenance_admin` (status `admin`, level 8, not a
  webmaster) and `provenance_generic` (status `generic`), created by
  `plugins/provenance/tests/Support/create-test-users.php`.
- `plugins/provenance/tests/Integration/RoleMatrixTest.php` holds one row per provenance function
  and role (guest, generic, normal, admin, webmaster). The row `provenance.writeback as admin`
  calls `pwg.provenance.writeBack` on a copied photo as `provenance_admin` and expects `edit`: the
  write-back button on the album screen and a write that reports one file written.
- The same holds for `apply`, `album`, `photo-note` and `move-prompt`, which had no
  non-webmaster administrator case either.

## Consequences

- The hand check of 0062 is replaced by that row; nobody repeats it.
- An install set up before this decision must re-run `create-test-users.php`; `Config` names the
  script when `PROVENANCE_TEST_ADMIN_*` or `PROVENANCE_TEST_GENERIC_*` is missing.
- Anyone who adds a webmaster check to the write-back now turns `provenance.writeback as admin`
  red, and has to supersede 0062 rather than edit it.
