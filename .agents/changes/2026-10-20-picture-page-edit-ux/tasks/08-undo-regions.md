---
id: 08
dependencies:
- 04
- 07
status: done
owner: s-e4c1
branch: task/2026-10-20-picture-page-edit-ux-08
---

# Task 08: Undo for region entries

Undo of a region entry deletes an added box or restores a deleted one with its original drawer.

## References

* `design.md#undo-runs-on-the-server`
* `design.md#who-may-undo`
* `plugins/persons/include/index.inc.php` (`persons_apply_change($image_id, $add, $remove, $notify, $drawn_by)`)
* `plugins/persons/include/functions.inc.php` (`persons_may_delete_region()`)
* `docs/agents/decisions/0065-normal-users-delete-only-the-regions-they-drew.md`

## Work

* [x] persons answers a new `trigger_change('persons_undo_region', …)`: removes the region matching name and box, or re-adds it with the stored `drawn_by`; returns success or a refusal reason
* [x] `pwg.photoinfo.undo` dispatches `region` entries to that event; conflict = region no longer present (for an add) or already present again (for a delete)
* [x] Rights still follow decision 0065 on top of the undo rule
* [x] Integration tests: undo add, undo delete (drawer kept), conflict `[NEG]`, persons inactive `[NEG]`

## Verification

* [x] Undo of „„Anna Schmitz“ markiert“ removes the box from the file and the index **Note:** `UndoRegionTest::testUndoOfAnAddRemovesTheBox` (index count, and the file read back with exiftool).
* [x] Undo of a deletion restores the box, and its original drawer can delete it again **Note:** `UndoRegionTest::testUndoOfADeleteRestoresTheBoxAndItsDrawer` (drawer kept, then the drawer deletes it again).
* [x] With persons deactivated, undo of a region entry is refused with a clear message **Note:** `UndoRegionTest::testWithPersonsDeactivatedTheUndoIsRefused`: 409 „Personen ist ausgeschaltet, das lässt sich nicht rückgängig machen“; persons is reactivated in `tearDown`.
* [x] persons and photoinfo suites pass **Note:** Verifier on the rebased state: photoinfo unit `OK (350 tests, 628 assertions)`, integration `OK (320 tests, 1955 assertions)`; persons integration `OK (226 tests, 1 skipped)`, persons unit only `CleanCheckoutTest`'s two worktree cases; photoinfo `history-list` E2E 7 passed.

## Implementation notes

- persons answers `trigger_change('persons_undo_region', $result, $image_id, $action, $region, $user_id, $is_admin)` with actions `state`, `remove`, `restore` (`plugins/persons/include/events_undo.inc.php`); decision 0065 is checked by persons against the index row's drawer. photoinfo passes the session's user and `is_admin()`, nothing from the request.
- A region matches by name and box within `PERSONS_REGION_MATCH_EPSILON` (`persons_find_region_row()`, unit-tested in `UndoRegionLookupTest`). A renamed person or moved box is a conflict.
- The undo calls `persons_apply_change()`, which fires `persons_tags_changed` and not `persons_regions_changed`, so the undo writes exactly one entry.
- persons' own refusal text for a region the caller did not draw is replaced by photoinfo's „Das darfst du nicht rückgängig machen“.
- For task 13: a region undo answers `written: true` even when photoinfo's keyword write after it failed (the `tag_writes` are dropped). Like task 07's `written=false`, it is not shown anywhere yet.
