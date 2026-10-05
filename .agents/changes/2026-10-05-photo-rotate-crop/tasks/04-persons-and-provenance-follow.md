---
id: 04
dependencies:
- 02
- 03
---

# Task 04: Person regions follow, and all three plugins exclude each other

After a turn or a crop the person boxes sit on the same faces; regions cut away are removed after a warning that names them; persons and provenance cannot write the file while photoedit does.

## References

* `design.md#events`
* `design.md#person-regions-follow-lost-ones-are-removed-after-a-warning`
* `design.md#one-exclusion-across-all-three-plugins-through-events`
* `plugins/persons/include/index.inc.php` (`persons_correct_for_rotation()`, `persons_apply_change()`, `persons_reindex_image_locked()`, `persons_sync_image_tags()`)
* `plugins/persons/include/functions.inc.php` (`persons_rotate_region()`, `persons_clip_region()`, `persons_minimum_box_ok()`)
* `plugins/persons/include/exiftool.inc.php` (`persons_lock_acquire()`, re-entrant)
* `plugins/provenance/include/functions.inc.php` (`provenance_lock_path()`)
* `.claude/rules/testing.md#cover-the-ground-before-you-move-it`

## Work

* [ ] Check persons' existing region-math and rotation tests cover what this task touches; add `[ERR]` characterization first where they do not, in its own commit
* [ ] persons: pure region transform for crop (shift + scale, clip, drop below minimum) in `functions.inc.php`, unit-tested
* [ ] persons: `photoedit_preview` handler adding lost regions with names
* [ ] persons: `photoedit_begin` takes its lock; `photoedit_end` transforms regions (turn and crop, pre-rotation frame), writes them, reindexes, syncs tags, then releases in `finally`
* [ ] provenance: `photoedit_begin`/`photoedit_end` take and release its lock
* [ ] photoedit: `dry_run` returns `lost_regions`; `editor.js` shows the warning with names and asks to confirm
* [ ] Extend `docs/backlog.md:46` note (coi and regions) to say what now holds

## Verification

* [ ] `[HAPPY]` After turn 1 the region read back from the file by ImageMagick sits where the turned face is (expected corners computed independently)
* [ ] `[ECP]` Crop keeps a region fully inside (moved/scaled), clips one partly inside, removes one fully outside, removes one clipped below the minimum
* [ ] The removed person's link to the photo is gone from the index and tags; the kept ones remain
* [ ] The dry run names exactly the regions the write later removes
* [ ] `[ST]` While photoedit holds `photoedit_begin`, a persons write and a provenance write-back on the same photo wait (or time out with their message) and do not interleave
* [ ] With persons deactivated, a turn still works and leaves the file's MWG regions as exiftool copied them
* [ ] persons and provenance suites pass unchanged
