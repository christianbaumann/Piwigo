---
id: 03
dependencies:
- 02
---

# Task 03: Crop a PNG

A webmaster draws a free rectangle on the (optionally turned) photo and saves; the file holds exactly that part.

## References

* `design.md#target-behaviour`
* `design.md#90-turns-and-a-free-rectangle-only`
* `design.md#turn-first-then-crop`
* `design.md#the-centre-of-interest-is-transformed`
* `themes/default/js/plugins/jquery.Jcrop.min.js`, `admin/themes/default/template/picture_coi.tpl` (existing Jcrop use)
* `admin/include/image.class.php` (`pwg_image::crop()`)

## Work

* [x] Pure function: crop fractions of the turned view → integer pixel rect of the turned raw file (rounding, clamping)
  **Note:** `photoedit_crop_rect()` rounds each edge to the nearest pixel and refuses a side below `PHOTOEDIT_MIN_CROP_PX` (16). A frame that rounds to the whole photo is "no crop" (`rect` null). **No clamping:** the validation already keeps every fraction in 0..1, so a rounded edge cannot leave the photo, and a clamp would be dead code (its mutant could not die). `photoedit_plan_edit()` combines turn and crop into the transform (pure). It also refuses "nothing to do" in pixel space, which catches a frame that only rounds to the whole photo.
* [x] Extend the COI transform to crop (COI fully outside → `NULL`, partly outside → clipped)
  **Note:** `photoedit_turn_coi($coi, $turns)` replaced by `photoedit_transform_coi($coi, $transform)`, plus `photoedit_crop_box()`. A box that only touches the crop's edge counts as outside.
* [x] `pwg.photoedit.apply` accepts `crop`; pipeline crops after turning
  **Note:** A crop the photo's size refuses (too small, or nothing to do) comes back as `WS_ERR_INVALID_PARAM`. It is checked before the dry run and again after the lock, against the re-read row. With Imagick the pipeline resets the page geometry after the crop (`setImagePage(0,0,0,0)`). Otherwise the PNG keeps the old canvas as an offset (`150x200` showed as page `300x200+0+0`). External ImageMagick's `-crop …!` and GD need nothing.
* [x] `editor.js`: Jcrop frame starting as the whole photo, kept over a turn of the preview (or reset, as decided while implementing — record which)
  **Note (decided): kept.** On a turn the frame turns with the preview, using the same math as `photoedit_turn_box()`. The frame resets to the whole photo only on entering or leaving edit mode.
  **Note (implementation detail, not a design change):** Jcrop draws on an empty `<div>` laid over the turned preview (`#photoedit-frame`, appended to `<body>`), not on `#theMainImage`. On an `<img>`, Jcrop hides the photo and draws an unturned copy, which would break the CSS-rotate preview from task 02. On a non-image, Jcrop switches to its shade mode by itself. The box is worked out from the layout size and the centre, so it is right during the turn's transition too. It is redrawn on resize via `ResizeObserver`. The frame needs `z-index: 0` as its own stacking context: Jcrop's inline z-indexes (around 300) covered the collapsed action menu on a narrow screen, and `edit-mode.spec.js`'s narrow-screen case failed until it was fixed. `keySupport` is off, so Jcrop adds no hidden input that would take the arrow keys.

## Verification

* [x] `[HAPPY]` Cropping a fixture to its left half gives a file of half the width, read back by ImageMagick
  **Note:** Verified by `ApplyCropTest::testTheLeftHalfIsWrittenIntoTheFileAndTheRow` × 3 libraries: size, page geometry `150x200+0+0`, marker still top-left, row `width`/`height`/`filesize`/`md5sum`, and the response. Removing the Imagick repage turned exactly the Imagick case red.
* [x] `[DT]` turn × crop: turn 1 + crop gives the same pixels as turning then cropping by hand (pixel markers)
  **Note:** Verified by `ApplyCropTest::testTheFileHoldsTheTurnedThenCroppedPixels`: turns 0, 1 and 3 with a crop, × 3 libraries. The file is compared with `convert -rotate -crop +repage` by `compare -metric AE` (0 differing pixels). Unit: `CropTest::testARequestTurnsFirstThenCrops` (DT table). Swapping x and y in the crop call turned all 9 red.
* [x] `[BVA]` A frame covering the whole photo with `turns` = 0 is "nothing to do"; a frame of 1 px is refused below a named minimum; `l >= r` or values outside 0..1 are refused
  **Note:** Unit: `CropTest` (minimum ±1 on both sides, 1 px, sub-pixel frame, whole and rounds-to-whole frame) and the existing `ValidateRequestTest` (`l >= r`, outside 0..1). Wiring: `ApplyCropTest::testARefusedCropChangesNothing` (error code, message, md5 and row unchanged).
* [x] COI: inside the crop is moved, outside becomes `NULL`
  **Note:** Unit: `CropTest::testTheCentreOfInterestIsCutToTheCrop` (inside, clipped, outside, turned then cropped) and `testABoxIsCutToTheCrop`. Wiring: `ApplyCropTest::testTheCentreOfInterestFollowsTheCrop` (`fakj` → `kauj` / `NULL`).
* [x] E2E: draw a frame, save, the shown photo has the cropped aspect ratio
  **Note:** Verified by `crop-save.spec.js`. `[HAPPY]` the frame starts as the whole photo, its right edge is dragged to the middle, and after Save the ratio is 0.75. `[ST]` left half, then a quarter turn, gives the top half (ratio 1.33). `[ST]` Cancel removes the frame, and Save on a fresh frame sends no request. Mutants killed: crop never sent (both saving specs red), frame not turned on a turn (only the `[ST]` turn spec red).
* [x] (added) All three photoedit suites green, the integration suite in both orders
  **Note:** 2026-10-05: unit 81, integration 56 (`--order-by=default` and `reverse`), E2E 21, run twice. persons and provenance were not re-run: none of their files changed.

## Mutants run (by hand, per `.claude/rules/mutation-testing.md`)

All killed. Unit: minimum `<` → `<=`, minimum height check dropped, whole-photo `and` → `or`, `round` → `floor` on the right edge, nothing-to-do `and` → `or`, `crop_box` `>=` → `>`, `crop_box` left clip dropped, COI crop skipped, COI using the unturned size. Integration (Mutagen and opcache waits applied): no Imagick repage, crop x/y swapped. E2E: crop not sent, frame not turned.

## Refactoring done along the way

* `ApplyTurnTest`'s ImageMagick readers (`identify`, `pixel`, `redCorners`) moved, unchanged, into the trait `tests/Support/ReadsImageFiles.php`, which `ApplyCropTest` uses too. `pageGeometry()` is new.

**Status: approved 2026-10-06.**
