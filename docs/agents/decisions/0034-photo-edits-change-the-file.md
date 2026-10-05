# 0034 — Turning and cropping a photo changes the file, in a plugin of its own, for the webmaster only

Date: 2026-10-05
Status: accepted
Design: `.agents/changes/2026-10-05-photo-rotate-crop/design.md`

## Context

The gallery's 105 photos are PNG scans, some of them scanned sideways or with a border. Core can
only *display* a photo turned: `images.rotation` is applied to the derivative by `i.php`, is set
only from EXIF Orientation, and core reads no orientation from a PNG at all. No screen crops a
photo. No database reaches the remote ([decision 0023](0023-no-database-transfer-to-the-remote.md)),
and the image file is the source of truth for the persons regions
([decision 0020](0020-persons-index-is-derived-the-file-is-the-source-of-truth.md)).

## Decision

1. **A turn or a crop is written into the image file.** `images.rotation` is not used to store it.
   The database follows the file (width, height, filesize, COI), never the other way round.
2. **It lives in a new fork-local plugin, `plugins/photoedit`**, with no change to core or the
   `modus` theme. It reaches the picture page through `loc_end_picture` and
   `add_picture_button()`, and talks to persons and provenance only through events it fires.
3. **Only a webmaster gets the edit button**, on the public picture page, gated by
   `is_webmaster()`. An administrator who is not a webmaster does not, and the suites prove that
   with a third account, `photoedit_admin`.

## Consequences

- An edit survives a deploy and a rescan, because both read the file.
- The pixels change, and each save of a JPEG is one more lossy generation. The original is kept
  under `_data/photoedit/originals/`; restoring it is a hand operation.
- One more plugin with its own three suites and test accounts.
- A view-only rotation, a database-only correction, an edit dialog over the page, an admin tab and
  a core edit were rejected; the reasons are in the design document's *Key Decisions*.
