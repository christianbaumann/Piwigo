---
id: 02
dependencies:
- 01
status: in-progress
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-02
---

# Task 02: Record date and info changes

Every edit of a photo's date or info text writes one change-log entry with old value, new value, user
and source; consecutive info saves of one user within 10 minutes become one entry.

## References

* `design.md#photoinfo-writes-the-rows-persons-announces-region-changes`
* `design.md#what-gets-a-row`
* `design.md#several-info-saves-become-one-entry`
* `plugins/photoinfo/include/ws_functions.inc.php` (`ws_photoinfo_setDate()`, `ws_photoinfo_setInfo()`)
* `plugins/photoinfo/include/events_core_edit.inc.php` (wrapped `pwg.images.setInfo`, admin photo screen, Batch Manager)
* `plugins/photoinfo/include/rescan.inc.php`, `events_upload.inc.php` (must not record)
* `plugins/provenance/include/history.inc.php`

## Work

* [ ] Record `date` and `info` entries (`object` photo) from `pwg.photoinfo.setDate` / `setInfo`, the wrapped `pwg.images.setInfo`, the admin photo screen and the Batch Manager, each with its source
* [ ] Store the date as its display text and EDTF so undo can restore it exactly
* [ ] Merge rule as a pure function: a new `info` entry updates the previous one when same user, same photo, no other entry in between, within 10 minutes (named constant)
* [ ] Return the entry id in the ws answers of setDate / setInfo (`history_id`)
* [ ] No entry from `pwg.photoinfo.rescan`, uploads, or an unchanged value
* [ ] Unit tests for the merge rule (`[BVA]` at 10 minutes, other user, entry in between); integration tests per edit path and the rescan `[NEG]`

## Verification

* [ ] Saving a date on the picture page writes one `date` entry naming the account
* [ ] Two info saves 3 minutes apart by one account give one entry whose old value is the text before the first save
* [ ] An info save by another account in between gives two entries
* [ ] A rescan and an unchanged save write nothing
* [ ] photoinfo unit and integration suites pass
