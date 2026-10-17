# 0083 — Deleting an album with "keep photos" is refused when it would leave a photo in no album

Date: 2026-10-17
Status: settled

Follows task 19 (a photo's last album cannot be removed) and its finding that
`pwg.categories.delete` with `photo_deletion_mode=no_delete` still orphaned photos. Task 39 of
`.agents/changes/2026-10-15-exploratory-testing-answers`.

## Decision

- `plugins/galleryrules` wraps `pwg.categories.delete` (outside other wrappers, neutral + 20, like
  `setInfo` and `setCategory`). In mode `no_delete` it refuses the call with HTTP 409 and nothing
  deleted, naming the photos ("Nichts gelöscht: Das Foto #5 wäre danach in keinem Album mehr ...")
  when a photo linked to one of the albums, or to a sub-album that goes with them, has no link to an
  album outside that set.
- A photo whose storage album is among the deleted ones is not named: core deletes it with the album.
- `delete_orphans` and `force_delete` pass: the user chose to delete the photos. A wrong token is
  left to core (403).
- The album's properties page and Alben > Verwaltung both call this method, so no further hook.

## Consequences

- Both screens log the failed call to the browser console only and show nothing to the user; the
  refusal's message is not visible there. Showing it needs a change in `cat_modify.js` and
  `albums.js` (open, reported to the main agent).
- provenance's `CoreDeleteCategoriesCharacterizationTest` recorded the orphaning as core's
  behaviour. Two cases now give their photo a second album, so they still characterize core's own
  delete; the refusal is covered by galleryrules' `AlbumDeleteKeepsPhotosTest`.
