# 0061 — The person list and the admin tagging screen respect album permissions

Date: 2026-10-12
Status: accepted
Closes the first two open findings of
[the role permissions research](../research/2026-10-11-role-permissions.md). Extends
[0019](0019-person-region-permission-model.md) and applies
[0044](0044-picture-page-tag-methods-respect-album-permissions.md)'s rule to two more places.

## Context

With guests locked out ([0055](0055-guests-are-locked-out.md)), two places still showed a
logged-in account persons data from photos it may not see:

- `pwg.persons.getList` returned every person in the gallery, including people whose only
  region is on a photo in a private album the caller has no grant for.
- The admin tagging screen (`admin/photo.php`) rendered a photo's regions without asking whether
  the administrator may see the photo, while the region methods do ask and answer 404.

## Decision

- `pwg.persons.getList` leaves out every person whose regions are all on photos the caller may
  not see, by core's own rule (`get_sql_condition_FandF()` over forbidden and visible albums and
  forbidden images). The condition is stated once, `persons_visible_images_sql()` in
  `include/index.inc.php`, and `persons_user_can_see_image()` (moved there from the ws file so the
  admin screen can call it) uses it as well.
- The filter applies **with and without** `image_id`. The design named only the call without it,
  but the picture-page picker is the only caller and always sends `image_id`; filtering the bare
  call alone would leave the same names one parameter away. A person the picker no longer offers
  can still be named by typing the full name: `addRegion` finds the existing person by name and
  creates no duplicate.
- The admin tagging screen asks `persons_user_can_see_image()` before reading any region. A photo
  that fails it renders only a message and no stage. That includes a photo in no album at all,
  which the region methods refuse too.
- As in 0044, administrators get no exception: a private album needs a grant.

## Consequences

- A person with no region left (after a region delete, before anyone deletes the person) is on
  no hidden photo and stays in the list. The task's wording, "at least one region on a photo the
  caller can see", would have dropped them; the picker's E2E specs make exactly that state to
  stand for "a person tagged elsewhere" (`knownButNotOnPhoto()` in `editor.spec.js`). The name of
  such a person may once have been on a hidden photo; the photo itself is no longer tied to it.
- Core reads an admin page's permissions from `piwigo_user_cache` without rebuilding it, and the
  same for a ws call whose Referer is an admin page (`include/user.inc.php`, `user_use_cache`).
  The tagging screen and the saves it makes therefore see the same, possibly older, album list:
  an administrator granted an album sees its photos there once a gallery request has rebuilt the
  cache. The integration suite makes that request explicitly.
- Witnessed by `tests/Integration/VisibilityTest.php`: the person on the hidden photo missing from
  `persons_normal`'s list and picker and present once the album is granted, a person with no
  region still listed, and `persons_admin` getting no regions on the tagging screen without a
  grant and the region with one.
