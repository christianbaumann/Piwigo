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

* [ ] `typetags.type.add`, `typetags.tags.setType`: `post_only` and `pwg_token`; cast `typetag_id` to int
* [ ] Group delete becomes a POST form with token; `edittypetag` POST checks the token
* [ ] Plugin `tags.js` sends POST with token
* [ ] Extend `RoleMatrixTest` where the levels are affected
* [ ] Submodule commit, pushed

## Verification

* [ ] Integration per method: GET refused, POST without token refused, nothing changed; POST with token works
* [ ] E2E: create, regroup, edit, delete a group through the plugin page still work
* [ ] typetags suites pass
