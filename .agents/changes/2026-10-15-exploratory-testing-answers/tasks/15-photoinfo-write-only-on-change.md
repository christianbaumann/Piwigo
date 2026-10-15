---
id: 15
dependencies: []
---

# Task 15: photoinfo writes tags only on a real change

A tag call that changes nothing leaves the image file untouched.

## References

* `design.md#tags-are-written-to-the-file-only-on-a-real-change`

## Work

* [x] Skip the tag write when the photo's tag set did not change; answer `tags_written: false`
  **Note:** `plugins/photoinfo/include/events_tags.inc.php`: typetags' `addTag`, `removeTag` and
  `addNewTag` wrappers compare the photo's tag ids before and after the call
  (`photoinfo_call_and_write_photo()`, `photoinfo_photo_tag_write_answer()`). Unchanged: no write,
  answer `tags_written: false, tags_message: ''` and no `tags_message_l10n`.

## Deviations

* Scope: the three typetags picture-page calls (the reported case). The Batch Manager's add/remove
  tags still writes every selected photo: core fires `loc_begin_element_set_global` before the
  selection is known, so no before-snapshot per photo is possible without a core edit. setInfo and
  the properties screen already compared before and after. Rename/duplicate/merge/delete always
  change the photos they write.
* typetags' script treats `tags_written === false` as a failure and unhides its warning span. With
  no `tags_message_l10n` it leaves the span's text as it was: empty (invisible) normally, or a
  previous failure's reason, which is still true since the file still lacks that change. Pinned by
  the new E2E case; typetags not edited.

## Verification

* [x] Integration: remove of an absent tag, file checksum unchanged, no `_original`, `tags_written: false`
  **Note:** Verified via `TagWriteTest::testRemovingAnAbsentTagLeavesTheFileUntouched` and
  `testAddingAPresentTagLeavesTheFileUntouched` (addTag and addNewTag of a present tag); red before
  the fix (`tags_written: true`). Flips under the suite lock, trap-restored: comparison forced false
  and unchanged answer emptied each killed both; `tags_message_l10n` added to the unchanged answer
  killed the E2E case.
* [x] A real change still writes
  **Note:** Verified via `TagWriteTest::testTheTypetagsBadgesWriteTheFile`, the first add in
  `testAddingAPresentTagLeavesTheFileUntouched`, and `tags-freitext.spec.js`'s written-change cases.
* [x] photoinfo suites pass
  **Note:** Verified via photoinfo unit (269 OK), integration (214 OK, 7:21), E2E (59 passed),
  2026-10-15. New E2E case: `tags-freitext.spec.js` "typing the name of a tag the photo carries shows
  no warning".
