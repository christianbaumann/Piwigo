---
id: 05
dependencies:
- 01
status: done
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

* [x] In photoinfo's `photoedit_end` handler, record one `photo` entry when `$ok`, value describing the transform (turn direction, crop)
* [x] Mark `photo` entries as not undoable in one place the undo task reads
* [x] Integration test: a turn writes one entry; a failed edit writes none

## Verification

* [x] Turning a photo writes one entry naming the account **Note:** `HistoryPhotoEditTest::testATurnWritesOneEntryNamingTheAccount`.
* [x] A refused or failed edit writes nothing **Note:** `testADryRunWritesNothing`, `testARefusedEditWritesNothing`, and `testAFailedEditWritesNothing` over the real path (read-only `_data/photoedit/originals`, `pwg.photoedit.apply` over HTTP). The failed-edit case was not watched red by breaking the handler.
* [x] photoinfo and photoedit integration suites pass **Note:** Verifier on the rebased state: photoinfo unit `OK (313 tests, 587 assertions)`, integration `OK (263 tests, 1527 assertions)`; photoedit unit `OK (107 tests, 218 assertions)`, integration `OK (134 tests, 709 assertions)`. After the real-path test: `HistoryPhotoEditTest` `OK (5 tests, 19 assertions)`.

## Implementation notes

Value: `new_value` is JSON `{"turns": 0-3, "crop": {"w","h"}|null}`, `old_value` NULL. `photoinfo_history_photo_parse()` / `_value()` in `plugins/photoinfo/include/history_photo.inc.php`. For task 07: `photoinfo_history_is_undoable($field)` over `photoinfo_history_not_undoable_fields()`.

The source is always `PICTURE_PAGE`: photoedit's only entry is the picture page. A crop of the whole photo is accepted by photoedit and recorded as a crop.
