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

* [x] Register `index.php?/provenance/album/<id>` and `?/provenance/photo/<id>` as a public section; render through a plugin template
* [x] Album page: four fields and history; photo page: effective values, photo note and history
* [x] Refuse guests; refuse (404) an album or photo outside the user's visible categories
* [x] Link from the album page and from the picture page's Herkunft row
* [x] German strings for the page
* [x] Integration: content for `provenance_normal`, refusal for a private album; E2E: link from picture page opens the page
* [x] Decision file superseding research decision 4 (matrix changes as in the design)

## Verification

* [x] `provenance_normal` opens the album and photo pages and sees the values and history the admin screen shows
  **Note:** Verified via `ReadOnlyPageTest` (values written through `pwg.provenance.setAlbumInfo`/`applyToPhotos`/`setPhotoInfo` as webmaster, read as `provenance_normal`).
* [x] The page has no form, input or action button
  **Note:** Verified via `ReadOnlyPageTest::testThePageHasNoControls` and `provenance-page.spec.js`.
* [x] A private album without access returns not found
  **Note:** Verified via `ReadOnlyPageTest::testAPrivateAlbumAndItsPhotoAreNotFound` (album and its photo 404; proven red with the permission condition removed).
* [x] A guest is redirected to the login page
  **Note:** Verified via `ReadOnlyPageTest::testAGuestIsSentToTheLoginPage` and curl (302 to `identification.php?redirect=...`).
* [x] Provenance unit, integration and E2E suites pass
  **Note:** 2026-10-12: unit 271 OK, integration 257 OK (3 skipped), E2E 55 passed. `WriteBackTest` operation-directory cases failed twice while other agents' suites ran writes through provenance's runner, and passed alone.

## Deviation from the design

* The picture page links to the page only from its Herkunft row, which core shows only when the
  photo carries a provenance value. A photo with history but no values has no link there; the
  album page's link is always shown. Recorded in decision 0064.
* The admin screens show no history today (only `pwg.provenance.getHistory` does), so the page's
  history is compared against what the API returns, not against an admin screen.

## Review fixes (2026-10-12)

* `generic` accounts were refused (`check_status(ACCESS_CLASSIC)`); now `is_a_guest()` + `access_denied()`. Checked by hand with the normal test account switched to `generic` and back (401 before, 200/404 as for a normal account after); no suite has a generic account.
* A `file_error` history row showed exiftool's raw message, with server paths, to normal users; now a fixed text below administrator. `ReadOnlyPageTest::testAFileErrorShowsNoServerDetailToANormalAccount` (watched red first).
* Decision 0064 widened to cover the tag groups page (task 10).
