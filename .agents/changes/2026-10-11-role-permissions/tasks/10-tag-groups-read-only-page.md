---
id: 10
dependencies: []
---

# Task 10: Tag groups read-only page

Every logged-in user can open a read-only page listing the colour groups with colour, stripes, emoji and member tags.

## References

* `design.md#normal-users-get-read-only-data-pages-not-copies-of-admin-screens`
* `plugins/typetags/include/events_admin.inc.php` `typetags_admin()` (group query), `events_public.inc.php` (badge rendering)
* `plugins/typetags/main.inc.php` (`loc_end_tags`)
* `docs/agents/decisions/0048-typetags-emoji-are-stored-as-code-points.md`
* `plugins/typetags` is a submodule: commit and push there (`github.com/christianbaumann/Piwigo-Colored-Tags`), then bump the pointer

## Work

* [ ] Register `index.php?/taggroups` as a public section in typetags; render groups with colour, stripes, emoji and their tags as badges
* [ ] Reuse the existing group query and badge rendering instead of copying them
* [ ] Only list tags the user can see (tags on visible photos), consistent with core's tags page
* [ ] Link from the tags page
* [ ] German strings
* [ ] Integration and E2E as `typetags_normal`, using `createStripedGroup()`
* [ ] Commit inside the submodule, push it, update the pointer in the fork

## Verification

* [ ] `typetags_normal` sees every group with its colour, stripes and emoji, and its visible tags
* [ ] A tag only on a photo in a private album is not listed for `typetags_normal`
* [ ] The page has no form or action control
* [ ] A guest is redirected to the login page
* [ ] Typetags unit, integration and E2E suites pass; a fresh `git submodule update` fetches the pushed commit
