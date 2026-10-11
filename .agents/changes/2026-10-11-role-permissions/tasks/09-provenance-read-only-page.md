---
id: 09
dependencies: []
---

# Task 09: Provenance read-only page

Every logged-in user can open a read-only provenance page for an album or photo they can see, with the four fields, the photo note and the history.

## References

* `design.md#normal-users-get-read-only-data-pages-not-copies-of-admin-screens`
* `design.md#matrix-changes-against-the-research-note`
* `include/section_init.inc.php` (`loc_end_section_init`)
* `plugins/provenance/include/history.inc.php`, `ws_functions.inc.php` (`pwg.provenance.getHistory`, reuse its query)
* `plugins/provenance/include/events_public.inc.php`, `template/public_provenance.tpl` (Herkunft row)
* `docs/agents/research/2026-10-11-role-permissions.md` (decision 4)

## Work

* [ ] Register `index.php?/provenance/album/<id>` and `?/provenance/photo/<id>` as a public section; render through a plugin template
* [ ] Album page: four fields and history; photo page: effective values, photo note and history
* [ ] Refuse guests; refuse (404) an album or photo outside the user's visible categories
* [ ] Link from the album page and from the picture page's Herkunft row
* [ ] German strings for the page
* [ ] Integration: content for `provenance_normal`, refusal for a private album; E2E: link from picture page opens the page
* [ ] Decision file superseding research decision 4 (matrix changes as in the design)

## Verification

* [ ] `provenance_normal` opens the album and photo pages and sees the values and history the admin screen shows
* [ ] The page has no form, input or action button
* [ ] A private album without access returns not found
* [ ] A guest is redirected to the login page
* [ ] Provenance unit, integration and E2E suites pass
