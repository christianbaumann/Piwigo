---
id: 09
dependencies: []
---

# Task 09: typetags writes need POST and a token

No typetags write can be triggered by a forged GET or a request without `pwg_token`.

## References

* `design.md#typetags-writes-need-post-and-a-token`
* `plugins/typetags/main.inc.php:76-124`, `admin.php:12-120`, `template/tags.js:207-248`

## Work

* [x] `typetags.type.add`, `typetags.tags.setType`: `post_only` and `pwg_token`; cast `typetag_id` to int
* [x] Group delete becomes a POST form with token; `edittypetag` POST checks the token
* [x] Plugin `tags.js` sends POST with token
* [x] Extend `RoleMatrixTest` where the levels are affected
  **Note:** No level changed (both methods stay `admin_only`); the matrix's `typetags.groups` probe
  already posts with the token. `RoleMatrixTest` left as it is.
* [x] Submodule commit, pushed
  **Note:** typetags `16f50cc`. Push refused: `git -C plugins/typetags push` answered 403
  (`Permission ... denied to dark-sith-lord`); the commit is local only and must be pushed by hand
  before the main repo's submodule pointer is pushed.

## Deviations

* The plugin page's other two POST forms, add (`addtypetag`) and configuration (`save_config`),
  check the token as well; the design named only edit and delete, the goal is "no typetags write".
* `tools/deploy` seeds the groups through `typetags.type.add` and `typetags.tags.setType`; its seed
  now sends `pwg_token` with both (`pwgdeploy/seed.py`), the fake gallery refuses them without it,
  and `test_seed.py::test_every_group_write_carries_the_token` covers it. pytest count 598 -> 599
  (`tools/deploy/README.md`, `.claude/rules/deployment.md`).
* `TypeAddPermissionTest::testAnAnonymousGetIsRefused` became `testAnAnonymousPostWithTokenIsRefused`:
  a GET is now refused by `post_only` before `admin_only` is reached, so the case moved to POST to
  keep witnessing the admin gate. GET and a missing token are `CsrfTest`'s.
* A missing token answers `1002 Missing parameters` (the token is a declared parameter), a wrong one
  `403`; `CsrfTest` asserts each.

## Verification

* [x] Integration per method: GET refused, POST without token refused, nothing changed; POST with token works
  **Note:** Verified via `plugins/typetags/tests/Integration/CsrfTest.php` (15 cases: type.add and
  setType GET 405 / no token 1002 / wrong token 403 / non-integer `typetag_id` 1003, each with the row
  unchanged, plus the token case; the plugin page's old delete link, delete/edit/add/configuration
  POSTs without or with a wrong token refused and nothing changed, the rendered delete form works).
  Against the pre-task code 13 of 15 went red (the two token-carrying happy cases pass both ways).
* [x] E2E: create, regroup, edit, delete a group through the plugin page still work
  **Note:** Verified via `tests/e2e/admin-groups.spec.js` (plugin page add/edit/delete; tags screen
  create + regroup through tags.js) and `admin-tags.spec.js`. With `template/tags.js` flipped back to
  the pre-task version, the regroup case and both `admin-tags` creation cases went red.
* [x] typetags suites pass
  **Note:** 2026-10-16: unit 91 OK; integration 179, all green except `CacheInvalidationTest`'s two
  cases, which were task 11's red-first tests (green after task 11); E2E 65 passed, 1 failed
  (`edge-cases` "server rejection", task 10's changed message; green after its update). Commands as in
  `.claude/rules/plugin-test-suites.md`, under `flock /tmp/pwg-suite.lock`.
