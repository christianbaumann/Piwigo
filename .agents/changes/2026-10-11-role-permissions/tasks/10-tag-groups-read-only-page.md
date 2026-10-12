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

* [x] Register `index.php?/taggroups` as a public section in typetags; render groups with colour, stripes, emoji and their tags as badges
* [x] Reuse the existing group query and badge rendering instead of copying them
* [x] Only list tags the user can see (tags on visible photos), consistent with core's tags page
* [x] Link from the tags page
* [x] German strings
* [x] Integration and E2E as `typetags_normal`, using `createStripedGroup()`
* [x] Commit inside the submodule, push it, update the pointer in the fork
  **Note:** committed (submodule `6c917c9`, pointer `3ce388be8`); pushed by the owner 2026-10-12 (the push from this machine was refused, 403)

## Verification

* [x] `typetags_normal` sees every group with its colour, stripes and emoji, and its visible tags
  **Note:** Verified via `TagGroupsPageTest` (normal account, every group listed, striped group with swatch, emoji and badge) and `taggroups.spec.js` (painted stripes in the browser).
* [x] A tag only on a photo in a private album is not listed for `typetags_normal`
  **Note:** Verified via `TagGroupsPageTest::testATagOnlyInAPrivateAlbumIsNotListed` (and the granted case); a mutation to `get_all_tags()` turned it red.
* [x] The page has no form or action control
  **Note:** Verified via `TagGroupsPageTest::testThePageHasNoFormOrControl` and the E2E `offers no control to change anything`. Tags are plain links to their tag pages, as on core's tags page.
* [x] A guest is redirected to the login page
  **Note:** Verified via `TagGroupsPageTest::testAGuestIsSentToTheLoginPage` and curl (302 to `identification.php?redirect=...taggroups`).
* [x] Typetags unit, integration and E2E suites pass
  **Note:** unit 88 OK, integration 119 OK (1 pre-existing skip), E2E 46 passed, 2026-10-12.
* [x] A fresh `git submodule update` fetches the pushed commit
  **Note:** before the push a fresh clone failed with `not our ref 6c917c9`; after it, a fresh clone's `git submodule update --init plugins/typetags` checked out `6c917c9` from GitHub (2026-10-12)

## Implementation notes

* Route: `typetags_taggroups_section()` on `loc_end_section_init` claims the `taggroups` token, calls `check_status(ACCESS_CLASSIC)` and sets `$page['is_external']`, so `index.php` renders menubar, header and footer but no thumbnails; `loc_end_index` swaps the `index` template for `template/taggroups.tpl`. No core change.
* Reuse: the group query moved out of `typetags_admin()` into `typetags_groups()` (both callers use it); badges use `typetags_badge_style()` / `typetags_badge_label()`; visible tags come from core's `get_available_tags()` (same source as core's tags page), sorted with `tag_alpha_compare`.
* Every group is listed, also one with no visible tag ("Kein sichtbares Schlagwort"); tags outside any group are not on this page.
* Link: a `loc_end_tags` prefilter includes `template/tags_link.tpl` before `{include file='infos_errors.tpl'}` in core's `tags.tpl` (decision 0036); `TemplateContractTest` guards the anchor.
* No decision file of its own: decision 0064 covers both read-only pages (widened after review).
* Access: `is_a_guest()` + `access_denied()` instead of `check_status(ACCESS_CLASSIC)`, which refused `generic` accounts (review finding, fixed 2026-10-12; checked by hand, no suite has a generic account).
