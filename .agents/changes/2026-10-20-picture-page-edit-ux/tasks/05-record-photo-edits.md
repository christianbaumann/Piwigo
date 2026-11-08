---
id: 05
dependencies:
- 01
status: in-progress
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-05
---

# Task 05: Record turns and crops

A successful turn or crop with photoedit writes one change-log entry („Foto gedreht“ / „Foto
zugeschnitten“) that offers no undo.

## References

* `design.md#drehenzuschneiden-stays-its-own-tool`
* `plugins/photoedit/include/pipeline.inc.php` (`trigger_notify('photoedit_end', $image, $transform, $ok)`)
* `plugins/photoinfo/include/events_tags.inc.php` (`photoinfo_photoedit_end()`)

## Work

* [ ] In photoinfo's `photoedit_end` handler, record one `photo` entry when `$ok`, value describing the transform (turn direction, crop)
* [ ] Mark `photo` entries as not undoable in one place the undo task reads
* [ ] Integration test: a turn writes one entry; a failed edit writes none

## Verification

* [ ] Turning a photo writes one entry naming the account
* [ ] A refused or failed edit writes nothing
* [ ] photoinfo and photoedit integration suites pass
