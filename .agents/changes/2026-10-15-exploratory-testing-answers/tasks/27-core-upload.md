---
id: 27
dependencies:
- 01
---

# Task 27: Upload: chunks per user, Abbrechen cleans up, page reports failures

No upload ever stores bytes of another upload, Abbrechen leaves nothing behind, and the upload page reports what the server did.

## References

* `design.md#upload-chunks-never-leak-into-another-upload`
* `design.md#abbrechen-removes-what-was-already-sent`
* `design.md#the-upload-page-reports-what-the-server-did`
* `include/ws_functions/pwg.images.php:1770-1821`, `admin/themes/default/js/photos_add_direct.js`

## Work

* [x] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [x] Buffer keyed by user and file name, opened `wb` at chunk 0; `.part` older than one day removed
* [x] Abbrechen removes the sent chunks and any half-created photo
* [x] Upload page: guard `JSON.parse` in the Error handler; non-JSON answer is a failure with server text; `uploadCompleted` success/error callbacks; null guard on `uploadCategory`
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [x] Integration: cancelled upload, then same-name upload by another admin stores exactly the new bytes
  * **Note:** Verified via `CoreUploadChunkIsolationTest` (provenance integration, 12 cases green after /verify added the traversal and left-the-lounge cases; the server mutants were killed against the first 10)
* [x] Integration: after Abbrechen no `.part` and no photo row remain
  * **Note:** Verified via `CoreUploadChunkIsolationTest::testCancelRemovesTheSentChunks` and `...FromTheLounge`
* [x] E2E: disallowed file in a selection, valid files shown once; fake `.jpg` reported as failed; failed `uploadCompleted` reported; Abbrechen before the first file leaves a usable page
  * **Note:** Verified via galleryrules `upload-page.spec.js` (5 cases green; all 5 red on the old JS)
* [ ] provenance suites pass
  * **Note:** unit 307 green; integration 387 tests, 2 failures in `CoreDeleteCategoriesCharacterizationTest` (the last-album refusal of another task, not upload)

## Deviations

* Half-created photo: removed only when it already waits in the lounge for the album (lounge on) and was added by the caller under a cancelled file name. A request that finishes after the browser aborted it can still create a photo or chunk afterwards (decision 0079, "Not covered").
* New method `pwg.images.uploadCancel` (ws.php) instead of JS-only cleanup; the design named no endpoint.
* The characterization commit holds `CoreUploadChunkCharacterizationTest` as first written; its buffer-name, stale-append and old-.part cases were replaced by `CoreUploadChunkIsolationTest` in the change commit.
