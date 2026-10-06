# 0037 — Album thumbnails count only the sub-albums the listing shows

Date: 2026-10-06

## Context

On the remote, the webmaster saw "Dusarts Monika — 22 Fotos, 8 Alben", and opening it listed 6
albums. The other two (ids 25 and 26) held no photos.

Upstream core does this for every administrator:

- `include/functions_user.inc.php` removes albums without photos from a user's cache only for
  non-admins (feature 1053), so an admin's `nb_categories` / `count_categories` include them.
- `include/category_cats.inc.php` lists only albums with `count_images > 0`, for admins too.

The thumbnail counts from the cache and the listing filters the cache, so the two disagree whenever
an admin owns an empty album. Upstream `master` (checked 2026-10-06 at `8b63248aa`) has both lines
unchanged.

## Decision

Fix it in core, fork-local: `category_cats.inc.php` fetches the admin's cached albums with
`count_images = 0` and subtracts them from each listed row through the pure
`discount_hidden_subalbums()` in `include/functions_category.inc.php`. The query runs only for
admins, whose cache is the only one that holds such albums.

Rejected: showing empty albums to admins in the listing. An album without a photo has no
representative, and the listing skips such an album outright ("listed in SQL but no image_id
found"), so that would need a thumbnail without an image, a larger change to a shared template.

The admin menu and `pwg.categories.getList` are left alone: both list the empty albums for admins
as well, so their counts already match what they show.

## Consequences

- One more query per album page for admins.
- A fork-local core change upstream has not got. An upstream merge that touches either file has to
  keep it; `plugins/provenance/tests/Integration/CoreHiddenSubalbumCountTest.php` goes red if it is
  lost, and the unit test of the same name pins the counting.
- Reported upstream as an issue (text drafted with the fix); drop this change once upstream fixes it.
