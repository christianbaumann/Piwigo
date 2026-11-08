---
id: 03
dependencies:
- 01
status: todo
owner:
branch:
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

* [ ] Compute the added and removed tags per photo from the before/after tag lists (pure function) and record one `tag` entry each, value `{"id","name"}`
* [ ] Cover the typetags badge methods, `addNewTag`, the wrapped `pwg.images.setInfo`, the admin photo screen and the Batch Manager
* [ ] No entry from `persons_tags_changed`, `pwg.photoinfo.rescan`, `pruneTags` or uploads
* [ ] Return the entry id(s) in the wrapped typetags answers (`history_ids`)
* [ ] Unit tests for the diff; integration tests per path and the person-tag `[NEG]`

## Verification

* [ ] Clicking „+ Kirche“ writes one entry „Kirche“ added, naming the account
* [ ] Removing two tags in the Batch Manager writes two entries per photo
* [ ] Naming a person region writes no tag entry
* [ ] photoinfo unit and integration suites pass
