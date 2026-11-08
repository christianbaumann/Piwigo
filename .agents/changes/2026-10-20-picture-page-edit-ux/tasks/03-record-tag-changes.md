---
id: 03
dependencies:
- 01
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-03
---

# Task 03: Record tag changes

Every tag added to or removed from a photo on an edit path writes one change-log entry, except the
person tags persons keeps in step with regions.

## References

* `design.md#what-gets-a-row`
* `design.md#values-of-tag-and-region-entries`
* `plugins/photoinfo/include/events_tags.inc.php` (`ws_photoinfo_typetags_addTag()`, `removeTag()`, `addNewTag()`, `photoinfo_photo_tag_write_answer()`, Batch Manager and admin-photo handlers, `photoinfo_persons_tags_changed()`)
* `plugins/photoinfo/include/events_core_edit.inc.php` (wrapped `pwg.images.setInfo` with `tag_ids`)
* `plugins/provenance/include/history.inc.php`

## Work

* [x] Compute the added and removed tags per photo from the before/after tag lists (pure function) and record one `tag` entry each, value `{"id","name"}`
* [x] Cover the typetags badge methods, `addNewTag`, the wrapped `pwg.images.setInfo`, the admin photo screen and the Batch Manager
* [x] No entry from `persons_tags_changed`, `pwg.photoinfo.rescan`, `pruneTags` or uploads
* [x] Return the entry id(s) in the wrapped typetags answers (`history_ids`)
* [x] Unit tests for the diff; integration tests per path and the person-tag `[NEG]`

## Verification

* [x] Clicking „+ Kirche“ writes one entry „Kirche“ added, naming the account **Note:** `TagHistoryTest::testAddingABadgeWritesOneEntry` (value JSON, account, source, `history_ids`).
* [x] Removing two tags in the Batch Manager writes two entries per photo **Note:** `TagHistoryTest::testABatchRemovalWritesTwoEntriesPerPhoto`.
* [x] Naming a person region writes no tag entry **Note:** `TagHistoryTest::testPersonsTagsChangedWritesNoTagEntry`, with an anti-vacuity check that the region tagged the photo.
* [x] photoinfo unit and integration suites pass **Note:** Verifier on the rebased state: photoinfo unit `OK (309 tests, 576 assertions)`, integration `OK (257 tests, 1504 assertions)`. After the Referer cleanup: unit `OK (309 tests, 576 assertions)`, `TagHistoryTest|HistoryRecordingTest` `OK (25 tests, 143 assertions)`.

## Implementation notes

Code: `plugins/photoinfo/include/history_tags.inc.php` (`photoinfo_tag_changes`, `photoinfo_tag_history_changes`, `photoinfo_record_tag_changes`). `history_ids` is on the three wrapped typetags answers. `pwg.images.setInfo` shares task 02's `photoinfo_core_edit_source()`.

Entry ids are `pwg_db_insert_id()` plus a consecutive range of one multi-row INSERT; `mass_inserts()` only splits at `max_allowed_packet`.

Open design question: `pwg.tags.merge` and `pwg.tags.delete` change photo-tag links on the admin tag page and write no entry, so those removals are missing from a photo's list. Regrouping, renaming and photoedit's person-tag drops write none by design.
