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

* [x] Check persons' existing region-math and rotation tests cover what this task touches; add `[ERR]` characterization first where they do not, in its own commit
  **Note:** Nothing to add. The new code reuses persons' existing pieces unchanged: `persons_rotate_region()`, `persons_clip_region()`, `persons_minimum_box_ok()` (all in `RegionGeometryTest`, with boundaries) and `persons_merge_regions()` with name-only removal and PersonInImage handling (`MergeRegionsTest`). The index rebuild and tag sync are covered by `ReindexTest` and `RotationTest`. No existing persons function was changed, so there was no ground to cover first and no separate commit.
* [x] persons: pure region transform for crop (shift + scale, clip, drop below minimum) in `functions.inc.php`, unit-tested
  **Note:** `persons_transform_region()` turns by `rotation_before + turns` (regions follow the raw file), then shifts and scales into the crop and runs the MWG rule `persons_clip_region()`. A region whose centre falls outside the crop is removed even when part of its box reaches in, as the design says ("after `persons_clip_region()`"). What is left must clear `persons_minimum_box_ok()`. `persons_transform_regions()` returns `kept` and `lost` (one name per removed region). Unit: `TransformRegionTest`.
* [x] persons: `photoedit_preview` handler adding lost regions with names
  **Note:** `include/events_photoedit.inc.php`. Reads the file, not the index: the file is the source of truth (decision 0020).
* [x] persons: `photoedit_begin` takes its lock; `photoedit_end` transforms regions (turn and crop, pre-rotation frame), writes them, reindexes, syncs tags, then releases in `finally`
  **Note:** Every region is removed by name and its moved copy added, through `persons_merge_regions()`, so a person cut away leaves PersonInImage as well. AppliedToDimensions becomes the new size. The reindex (re-entrant lock) syncs the tags. Handlers are registered outside `IN_ADMIN`, since photoedit fires them from `ws.php`.
  **Note (implementation detail):** a notify handler cannot answer, so `photoedit_begin` **throws** on a lock timeout. photoedit fires it inside its `try`, so the edit is abandoned before the file is touched, and `photoedit_end` still runs with `ok = false`.
  **Known limits, not fixed:** (1) region entries the parser could not read are written back verbatim and unmoved, as `persons_merge_regions()` always does. (2) If the region write fails after the file was edited, persons logs it and photoedit still reports success; a `pwg.persons.rescan` then indexes what the file says.
* [x] provenance: `photoedit_begin`/`photoedit_end` take and release its lock
  **Note:** `plugins/provenance/include/events_photoedit.inc.php`, using `provenance_lock_acquire()`. It throws on a timeout too.
* [x] photoedit: `dry_run` returns `lost_regions`; `editor.js` shows the warning with names and asks to confirm
  **Note:** Both were already in place from task 02 (`trigger_change('photoedit_preview')`, `window.confirm` with `data-confirm-lost`). They only had nothing to report until now. No code change; covered by the new tests below.
* [x] Extend `docs/backlog.md:46` note (coi and regions) to say what now holds

## Verification

* [x] `[HAPPY]` After turn 1 the region read back from the file by ImageMagick sits where the turned face is (expected corners computed independently)
  **Note:** Verified by `photoedit/tests/Integration/ApplyRegionsTest::testAfterAQuarterTurnTheRegionSitsOnTheTurnedFace`. The region is parsed from `convert xmp:-` with DOM/XPath, and the expected box is worked out in pixels in the test (x 70..130, y 45..105 of 200x300). AppliedToDimensions is 200x300.
* [x] `[ECP]` Crop keeps a region fully inside (moved/scaled), clips one partly inside, removes one fully outside, removes one clipped below the minimum
  **Note:** All four classes, plus "centre outside, box partly inside", are in persons' `TransformRegionTest` (unit). The file, over HTTP: `ApplyRegionsTest::testACropMovesClipsAndRemovesRegions` (inside, partly, outside; values checked in the file read by ImageMagick).
* [x] The removed person's link to the photo is gone from the index and tags; the kept ones remain
  **Note:** Verified by `testACropMovesClipsAndRemovesRegions` (`piwigo_person_region` and `piwigo_image_tag` list exactly the two kept names; anti-vacuity: the removed person's tag was there before).
* [x] The dry run names exactly the regions the write later removes
  **Note:** Verified by `ApplyRegionsTest::testTheDryRunNamesWhatTheWriteRemoves` (dry run's names = index before minus index after) and `testATurnLosesNobody`. In the browser: `lost-regions.spec.js`. The confirm names the cut person and not the kept one; declining writes nothing; accepting saves the crop.
* [x] `[ST]` While photoedit holds `photoedit_begin`, a persons write and a provenance write-back on the same photo wait (or time out with their message) and do not interleave
  **Note:** Verified by `photoedit/tests/Integration/ExclusionTest`. (1) In-process, per plugin: after its begin handler the plugin's lock file is held (a separate `flock -n` process gets status 9), and it is free again after end. Each plugin's writer takes that same lock. (2) Over HTTP, per plugin: a child process holds the plugin's lock for 2 s; when the edit's response arrives, the child has already finished. This also proves both handlers are registered and fire from `ws.php`.
* [x] With persons deactivated, a turn still works and leaves the file's MWG regions as exiftool copied them
  **Note:** Verified by `ApplyRegionsTest::testWithoutPersonsTheRegionsAreCopiedUnmoved` (turn written; region and AppliedToDimensions unchanged). Persons is reactivated in `tearDown`.
* [x] persons and provenance suites pass unchanged
  **Note:** 2026-10-05. persons: unit 141 (11 new), integration 110 (1 skipped), E2E 120. provenance: unit 183, integration 184 (3 skipped), E2E 53. The skips are existing, environment-dependent or recorded known gaps; none was added. photoedit: unit 81, integration 65 (default and reverse order), E2E 23.

Mutation testing skipped on request (2026-10-05).

## Problem along the way

* An early version of `ApplyRegionsTest` typed its WS helper `array`, but `pwg.plugins.performAction` returns `true`. The reactivation in `tearDown` then errored, and persons was left **inactive** on the dev install. Reactivated through the web service and fixed (`mixed`). The case is now documented in `.claude/rules/plugin-test-suites.md`.
