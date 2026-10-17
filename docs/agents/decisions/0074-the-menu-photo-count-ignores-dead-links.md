# 0074 — The menu photo count ignores links to deleted photos and albums

Date: 2026-10-17
Status: settled

Raised by the exploratory testing session SES-2026-10-13-01: the menu said 106 photos while the
API and the album counts gave 105. Design: `.agents/changes/2026-10-15-exploratory-testing-answers/design.md`,
*The menu photo count: research first*. Cause:
[research note](../research/2026-10-17-menu-photo-count.md).

## Decision

Core's menu count (`nb_total_images` in `getuserdata()`, `include/functions_user.inc.php`) counts a
photo only when its `image_category` link has an existing `images` row and an existing
`categories` row (two `INNER JOIN`s). A fork-local core fix under *Defects in core are fixed in
core*, listed in `.claude/rules/fork-local-core-edits.md`.

The leftover links themselves stay: the fix makes the count right whatever is left behind, rather
than cleaning the table. Five such rows were in the local install on 2026-10-17, none pointing at a
real photo.

## Reason

The count read `image_category` alone. The album counts (`get_computed_categories()`) and
`pwg.categories.getImages` join `images` and `categories`, so a link a deleted photo or a deleted
album left behind counted in the menu only. Photos in no album, in the lounge, above the viewer's
privacy level, in an ungranted private album or (for non-administrators) in a locked album were
already excluded, and still are (`CoreMenuPhotoCountCharacterizationTest`).

## Trade-offs

One more core query to carry through upstream pulls. Rejected: deleting the dead links (repairs
one install, not the next leftover; core's `delete_elements()` and `delete_categories()` remove
the links themselves, so the leftovers come from direct SQL such as a killed test run); a
`galleryrules` override (core offers no event between the query and the cache write).

Tested by provenance's `CoreMenuPhotoCountTest`: per role, the menu count equals the photos
`pwg.categories.getImages` lists, with a link to a deleted photo, a photo linked only to a deleted
album, a photo in no album and one in the lounge.
