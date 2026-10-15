# 0072 — A replace is copied through a core trigger; a deleted photo's copies move aside

Date: 2026-10-15
Status: accepted
Design: `.agents/changes/2026-10-15-exploratory-testing-answers/design.md` (*A restore through
replace keeps a copy of the edited state*, *Copies of a deleted photo move aside*, *`_original`
sidecars move aside on delete*)

## Context

A replace through Fotos hinzufügen overwrote an edited photo with no copy of the edited state
(SES-2026-10-14-24). Core fires no event before `add_uploaded_file()` deletes the file it replaces:
`delete_element_files()` fires none, and `begin_delete_elements` belongs to `delete_elements()`,
which a replace does not call. Wrapping `pwg.images.upload` would miss `pwg.images.addFile` and
`pwg.images.addSimple` with an `image_id`.

## Decision

1. **One fork-local `trigger_notify('add_uploaded_file_before_replace', $image_id, $file_path)`** in
   `add_uploaded_file()`, before `delete_element_files()`. `plugins/photoedit` copies the file to
   `_data/photoedit/originals/` there, named like an edit's copy. A failed copy is logged and the
   replace goes on: a notification cannot refuse, and core's upload has no refusal path there.
2. **On delete, `begin_delete_elements` remembers each photo's path and `delete_elements` moves**
   its copies to `originals/deleted/<id>-<deletion time>/`, and exiftool's `_original` sidecar too,
   but only once the photo's file is gone. A photo deleted without its file (filesystem sync,
   album delete) keeps the sidecar beside the file it belongs to.

## Consequences

- One more core line to carry through upstream pulls, listed in
  `.claude/rules/fork-local-core-edits.md`.
- `originals/` and `originals/deleted/` grow without limit; pruning is by hand.
- Rejected: wrapping each ws upload method (three wraps, and any new caller is missed); refusing the
  replace when the copy fails (would need a core change to the upload's error path).
