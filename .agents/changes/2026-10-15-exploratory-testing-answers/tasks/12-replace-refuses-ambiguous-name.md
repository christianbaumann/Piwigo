---
id: 12
dependencies:
- 02
---

# Task 12: Replace refuses an ambiguous file name

The replace option never overwrites a photo it had to guess.

## References

* `design.md#replace-refuses-when-a-file-name-is-ambiguous`

## Work

* [x] In `galleryrules`, refuse a replace upload when more than one photo in the album has the file name; the error names the matching photos
  * `include/events_replace.inc.php` re-registers `pwg.images.upload` around core's handler
    (`ws_add_methods`, priority neutral + 10, as photoinfo does for `setInfo`). With `update_mode`
    on and no `format_of`, it runs core's own update-mode query; more than one match is refused
    before core writes a byte, on every chunk, with HTTP 409 and a `WS_ERR_INVALID_PARAM` error.
    The 409 is what makes the upload page's plupload `Error` handler show the message; a 200
    would land in `FileUploaded`.
  * The pure decision is `galleryrules_replace_ambiguity()` in `include/replace.inc.php`.
  * German text in the plugin's new `language/de_DE/plugin.lang.php`:
    "Nichts ersetzt: Die Fotos #3, #7 dieses Albums haben alle den Dateinamen „x.png“."
  * **Deviation:** the photos are named by id (`#3, #7`), not by title. The upload page appends the
    message into its HTML unescaped (core's `photos_add_direct.js`), and a title is user text.

## Verification

* [x] Integration: two photos with one name, replace refused, both files unchanged, message names both
  **Note:** Verified via `ReplaceAmbiguousNameTest::testTwoPhotosWithTheNameAreNotReplaced` (exact German message, md5/path/file of both unchanged, no photo added) and E2E `replace-ambiguous.spec.js` (the refusal shows on Fotos hinzufügen, no thumbnail added). Flip `if ($named !== null)` -> `if (false)` turned both red; restored, checksums host = container.
* [x] One match still replaces
  **Note:** Verified via `testOnePhotoWithTheNameIsReplaced` (`add_status` update, file bytes are the upload's) and `testThePhotoInAnotherAlbumDoesNotCount`; unit `ReplaceAmbiguityTest` covers 0/1/2/many/duplicate-row.
* [x] galleryrules suites pass
  **Note:** Verified 2026-10-15: unit 22 tests OK; integration 35 tests OK forward and `--order-by=reverse`; Playwright 9 passed (all under `flock /tmp/pwg-suite.lock`).
