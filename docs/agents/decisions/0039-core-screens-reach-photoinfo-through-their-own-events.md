# 0039 — Core's photo screens reach photoinfo through their own events and a wrapped setInfo

Date: 2026-10-08
Status: accepted

## Context

A date or description saved through one of core's screens must reach photoinfo's columns and the
image file (`.agents/changes/2026-10-07-photo-date-and-info/design.md`, "Core admin screens set an
exact date", "Core description edits rewrite the caption"). Core has three writers of
`date_creation` and `comment` that a screen reaches, characterized in
`CorePhotoTextCharacterizationTest` and `CoreDateAndCommentSaveCharacterizationTest`:

| Writer | Reached from | Event core offers |
|---|---|---|
| `admin/picture_modify.php` | photo properties screen | `picture_modify_before_update` (filter, before), `loc_end_picture_modify` (after) |
| `admin/batch_manager_global.php`, action `date_creation` | Batch Manager, global mode | `element_set_global_action` (after) |
| `pwg.images.setInfo` | Batch Manager, unit mode (`batchManagerUnit.js`), the API | none after the save |

The properties screen and the unit mode post every field on every save, changed or not.

## Decision

- The properties screen: the `before_update` filter snapshots the stored date and description;
  `loc_end_picture_modify` compares them with the saved row.
- The global action: every selected photo counts as changed, since the action sets the date
  outright. All rows are brought in line before the first file is written, so a request that runs
  out of time mid-selection leaves files behind, never a qualifier beside a date it no longer fits.
- `pwg.images.setInfo`: photoinfo registers the method again on `ws_add_methods` after core
  (priority neutral + 10), with core's signature, description and options, around core's
  `ws_images_setInfo()`. No core change.
- A date whose **day** differs from the stored one becomes exact to the day (precision `day`, qualifier,
  end and end precision NULL); a removed date clears all four. Core's `date_creation` stays as core
  stored it, time of day included. A changed time of day alone is no change: photoinfo holds a date
  to the day at most, and core's date picker (`showSecond: false`) can post a stored time back
  without its seconds. A changed date or description rewrites the file: caption,
  `XMP-pwginfo:Info` and the three date tags in one exiftool write. Unchanged values write nothing.
  The decision is the pure `photoinfo_core_edit()`.
- The columns follow core's date even while provenance is off; only the file write needs it, and
  is then reported as failed.
- `pwg.images.setInfo` can save the row and still answer an error (an invalid
  `multiple_value_mode`, `tag_list` beside `tag_ids`); what it saved is synced, and core's error is
  the answer.
- A failed file write leaves the database saved. On an admin page it shows as one error message,
  however many photos it failed for; on `pwg.images.setInfo` the answer, `null` from core, becomes
  `image_id`, `written`, `message` whenever a file write was attempted.

## Consequences

- A "ca. 1965" survives a new title in either screen. A date posted unchanged is no change, so a
  photo stored as `1965` (1965-01-01, precision `year`) and set to 01.01.1965 in core stays `1965`.
- Another plugin that re-registers `pwg.images.setInfo` after photoinfo replaces the wrapper, and
  core's screens then drift from the file again; none does today.
- The unit mode's form-POST save in `admin/batch_manager_unit.php` (`$_POST['submit']`) is not
  hooked: no button posts it since the unit mode saves through `pwg.images.setInfo`.
- Upload and the filesystem sync set `date_creation` on new photos; the metadata sync is covered
  separately (`format_exif_data`, task 05). `pwg.images.add` and `pwg.images.addSimple` are not
  hooked - not even with `image_id`, where they replace an existing photo's file and can set its
  `comment` and `date_creation`. They are API-only; no screen of this install calls them that way.
- Neither plugin checks the file format: a changed description of a video or a PDF, or a batch date
  over a selection holding one, runs exiftool against that file. This install holds images only.
