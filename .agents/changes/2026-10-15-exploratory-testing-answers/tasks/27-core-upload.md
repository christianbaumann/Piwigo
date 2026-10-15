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

* [ ] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [ ] Buffer keyed by user and file name, opened `wb` at chunk 0; `.part` older than one day removed
* [ ] Abbrechen removes the sent chunks and any half-created photo
* [ ] Upload page: guard `JSON.parse` in the Error handler; non-JSON answer is a failure with server text; `uploadCompleted` success/error callbacks; null guard on `uploadCategory`
* [ ] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [ ] Integration: cancelled upload, then same-name upload by another admin stores exactly the new bytes
* [ ] Integration: after Abbrechen no `.part` and no photo row remain
* [ ] E2E: disallowed file in a selection, valid files shown once; fake `.jpg` reported as failed; failed `uploadCompleted` reported; Abbrechen before the first file leaves a usable page
* [ ] provenance suites pass
