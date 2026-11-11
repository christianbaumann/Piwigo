---
id: 08
dependencies:
- 04
- 07
status: in-progress
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

* [ ] persons answers a new `trigger_change('persons_undo_region', …)`: removes the region matching name and box, or re-adds it with the stored `drawn_by`; returns success or a refusal reason
* [ ] `pwg.photoinfo.undo` dispatches `region` entries to that event; conflict = region no longer present (for an add) or already present again (for a delete)
* [ ] Rights still follow decision 0065 on top of the undo rule
* [ ] Integration tests: undo add, undo delete (drawer kept), conflict `[NEG]`, persons inactive `[NEG]`

## Verification

* [ ] Undo of „„Anna Schmitz“ markiert“ removes the box from the file and the index
* [ ] Undo of a deletion restores the box, and its original drawer can delete it again
* [ ] With persons deactivated, undo of a region entry is refused with a clear message
* [ ] persons and photoinfo suites pass
