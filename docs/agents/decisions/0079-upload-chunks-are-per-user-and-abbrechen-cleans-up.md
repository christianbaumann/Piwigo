# 0079 — Upload chunks are per user, Abbrechen cleans up, the upload page reports failures

Date: 2026-10-17
Status: settled

Raised by the exploratory testing sessions SES-2026-10-14-07, -15 and SES-2026-10-15-07. Design:
`.agents/changes/2026-10-15-exploratory-testing-answers/design.md`, *Upload chunks never leak into
another upload*, *Abbrechen removes what was already sent*, *The upload page reports what the
server did*.

## Decision

Fork-local edits to core, listed in `.claude/rules/fork-local-core-edits.md`:

- `ws_images_upload()` (`include/ws_functions/pwg.images.php`) names the buffer file
  `upload_buffer_name(user id, file name)` and opens it `wb` at chunk 0, `ab` for later chunks. A
  `.part` older than `UPLOAD_PART_MAX_AGE` (one day) is removed on the next upload.
- New `pwg.images.uploadCancel` (admin only, POST, token): removes the caller's `.part` for the
  given file names and the caller's photos of those names still waiting in the lounge for the given
  album. The Abbrechen button calls it for every unfinished file, then reports the finished ones.
- `admin/themes/default/js/photos_add_direct.js` guards `JSON.parse` in the Error handler, treats a
  non-JSON or `stat != ok` answer to a file as a failure naming the file with the server's text,
  reports a failed `uploadCompleted` instead of announcing success, and gives the page back when
  nothing was uploaded.

## Not covered

A request that finishes on the server after the browser aborted it can still create a photo or
write a chunk after `uploadCancel` ran. The cancel call cannot know that request; the one-day
cleanup removes the chunk, and a photo created that way waits in the lounge like any upload and is
listed in the album after the next `uploadCompleted`. With the lounge off, a photo is linked at
once and `uploadCancel` leaves it alone.

## Reason

The buffer was `md5(file name)`, one for all admins, and chunk 0 appended to a stale `.part`, so a
re-upload stored the cancelled bytes in front. Nothing ever removed a `.part`.
