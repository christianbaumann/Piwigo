---
id: 22
dependencies:
- 02
---

# Task 22: Empty albums are hidden from visitors

Visitors never reach an empty album; "In Galerie öffnen" explains it.

## References

* `design.md#empty-albums-are-hidden-from-visitors`
* decision 0037

## Work

* [x] In `galleryrules`, hide empty albums from non-admins in lists and by URL; "In Galerie öffnen" shows a note for an empty album
  * Hiding needed no new code: core already forbids every album without a photo in a non-admin's
    user cache (feature 1053, `get_computed_categories()` caller in `include/functions_user.inc.php:510`),
    so it is neither listed (album page, menu, `pwg.categories.getList`) nor opened by URL (401). A
    probe (2026-10-17) found no path leaving it visible: created through `pwg.categories.add`, emptied
    by a move through `pwg.images.setInfo`, both refused for `galleryrules_normal`. Pinned by `[ERR]`
    characterization cases instead of a second, redundant check.
  * The note: `include/events_empty_album.inc.php`, `loc_begin_index`. For an administrator on an
    album page (not combined) whose own photos and sub-albums' photos number 0, core's info box shows
    "Dieses Album enthält kein Foto. Besucher sehen es erst, wenn ein Foto darin ist." An album whose
    photos are only in a sub-album is not empty and gets no note.

## Verification

* [x] Integration as normal and generic: empty album not listed, URL refused; admin sees the note
  **Note:** Verified via `EmptyAlbumHiddenTest` (12 cases): normal and generic - not on the parent's album page, not in `pwg.categories.getList` recursive, URL answers 401 without name or description; admin and webmaster - empty album opens with the note once. E2E `empty-album.spec.js`: webmaster on the album properties page follows "In Galerie öffnen", the gallery page shows the note (text read from the lang file by `seed.php --scenario=empty`). Flips under the suite lock, EXIT-trap restore, checksums host = container: note never -> 2 red (admin, webmaster note); note always -> 2 red (no-note cases); sub-album clause dropped -> 2 red (the parent case); core feature 1053 off (`if (false)`) -> the 6 visitor cases red. Nothing else moved; `include/` clean afterwards.
* [x] Album with photos unchanged
  **Note:** Verified via `testTheFullAlbumOpens` (normal, generic: opens with its description, no note) and `testAnAdministratorSeesNoNoteOnAnAlbumWithPhotos` (one photo; photo only in a sub-album).
* [x] galleryrules and provenance suites pass
  **Note:** Verified 2026-10-17 under `flock /tmp/pwg-suite.lock`: galleryrules unit OK (38), integration OK (63) forward and `--order-by=reverse`, Playwright 11 passed; provenance unit OK (286), integration OK (353, 3 skipped, as before).

## Deviations from the design

- No hiding code: core's feature 1053 already hides empty albums from non-admins; the change pins it
  with `[ERR]` tests. An empty album that only a stale user cache would show was not reproduced.
- The note is on the gallery page "In Galerie öffnen" leads to (core's info box, administrators
  only), not on the album properties page itself.
- The E2E spec was not separately watched red; the note's flips turned the integration cases red.
