# 0084 — An upload or replace merges file and gallery field by field

Date: 2026-10-17
Status: settled

Supersedes the upload part of [decision 0050](0050-uploads-and-iptc-import-do-not-write-tags.md) for a
replace. Task 32 of `.agents/changes/2026-10-15-exploratory-testing-answers`, design *An upload or
replace merges file and gallery field by field*. Raised by SES-2026-10-14-24 (a restore left the
index at the edited frame, a bare replace dropped the Info text, the mark and the keywords from the
file) and issue 14-22 (a mark in an uploaded file needed a manual rescan).

## Decision

`plugins/persons` and `plugins/photoinfo` listen to core's `loc_end_add_uploaded_file` (no core
edit; it fires for a new upload and a replace alike, after core's metadata sync). persons runs
first (`EVENT_HANDLER_PRIORITY_NEUTRAL`), photoinfo after it (`+10`), both under the photo's
persons lock, which photoinfo's writes take re-entrantly.

Per field, the new file wins when it carries the field, else the gallery's value is written into it:

| Field | File carries it | File lacks it |
|---|---|---|
| Regions | reindex from the file | the index's marks are written into the file (`persons_apply_change_quietly()`), `drawn_by` kept; an empty index changes nothing |
| Info text | `comment` is the file's | `XMP-pwginfo:Info` (and the caption) written from `comment`, when it is not empty |
| Date | the date columns are the file's (`XMP-pwginfo:DateEDTF`) | written from the columns, only when photoinfo set a precision |
| Keywords | file has the `TagsWritten` marker: its tags are linked and the photo's others are taken off | the photo's tags are written, when it has any |

A new upload goes through the same code: a file with marks, an info text, a date or marked
keywords is read in, and a bare file with nothing in the gallery to write is left alone.
Nothing fires `persons_tags_changed` from here; photoinfo decides the keywords after persons has
settled the person tags.

A tag the file's keywords do not name is taken off even though 0046 says a rescan never removes one: a
replacement is the photo's new truth, as `--prune-tags` is for a rescan. The exception is a person still
marked on the photo (a Face region): its tag stays and is written into the file's keywords, so task 18's
guard is not bypassed (`persons_sync_image_tags()` after the prune). Only an administrator reaches this
path: the upload methods are `admin_only`.

## Not changed

- Tags an API upload links after `add_uploaded_file()` returns (`pwg.images.add`, `addSimple`,
  `uploadAsync` with tags) still reach the file at the photo's next tag change, as 0050 says.
- Core's IPTC keyword import and `site_update`'s metadata step stay as 0050 says; `use_iptc` stays off.

## Trade-offs

A replacement that carries an outdated field overrides the gallery's current value: a restored copy
brings back its info text, date and tag set (including taking tags off). Always letting the file win
would make a plain scan replace lose its marks; always letting the gallery win would keep a
restored copy's stale frame. A failure in either handler is logged and the upload stands, since the
notification cannot refuse it.

## Tests

`plugins/persons/tests/Integration/UploadMergeTest.php`, `plugins/photoinfo/tests/Integration/UploadMergeTest.php`.
