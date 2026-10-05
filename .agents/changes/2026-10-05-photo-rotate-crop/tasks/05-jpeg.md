---
id: 05
dependencies:
- 04
---

# Task 05: JPEG support

A webmaster turns and crops a JPEG, including one shown rotated by EXIF Orientation; the result is upright in the file, the quality is kept, and the page warns about the re-encode first.

## References

* `design.md#png-and-jpeg`
* `design.md#events` (the `rotation_before` field of `transform`)
* `admin/include/image.class.php` (`get_rotation_angle()`, `get_rotation_code_from_angle()`, Imagick quality)
* `i.php:481` (lazy rotation), `include/derivative.inc.php:82`
* `plugins/persons/include/functions.inc.php` (`persons_rotation_delta()`; regions are pre-rotation)

## Work

* [x] Pipeline: total turn = `images.rotation` + `turns`; after writing, EXIF Orientation = 1 and `images.rotation` = 0
  **Note (design correction):** `images.rotation` counts quarter turns **counter-clockwise**, not clockwise as the design said. Measured 2026-10-05: an Orientation-6 JPEG gets code 3, and core shows it turned a quarter **clockwise**. So the raw file turns by `photoedit_raw_turns()` = `(4 - code) % 4 + turns` (pure, unit-tested), not `code + turns`. The transform carries this as a new field `raw_turns`, beside `rotation_before` and `turns`. Recorded in [decision 0035](../../../../docs/agents/decisions/0035-images-rotation-counts-counter-clockwise.md); the design's "Current state" line is corrected.
  **Note:** The crop is drawn on the shown view, so it is cut from the raw file turned by `raw_turns`. The centre of interest turns by `turns` only: it lives in display coordinates, since `i.php` rotates before it crops. "Nothing to do" asks whether the **page** changes (`turns` = 0 and no crop), not the raw file: turns that undo a stored rotation still rewrite the file upright. Orientation: `exiftool … -tagsFromFile backup -all:all -Orientation#=1`, JPEG only. The refusal of a stored rotation from task 02 is gone.
* [x] Re-encode with the quality read from the file, else 95 (named constant)
  **Note:** `photoedit_jpeg_quality()` reads `Imagick::getImageCompressionQuality()` from the backup, else `PHOTOEDIT_DEFAULT_JPEG_QUALITY` (95). The result goes to whichever library writes, through `pwg_image::set_compression_quality()`.
* [x] `dry_run` returns `lossy = true` for JPEG; `editor.js` shows the quality note
  **Note:** One `confirm` collects the lost-region line and the re-encode note, so a crop of a JPEG with regions asks once. New key: "Saving re-compresses this JPEG, which loses a little quality." (de_DE / en_UK).
* [x] persons' `photoedit_end` uses `rotation_before` so regions stored pre-rotation land right, and `rotation_at_write` is updated
  **Note (deviation, small):** persons uses `raw_turns`, not `rotation_before`, so the meaning of the code is worked out in one place (photoedit). `rotation_at_write` becomes 0 through the reindex, which reads the row photoedit has already updated.
* [x] Fixtures: a JPEG with Orientation 1 and one with Orientation 6
  **Note:** `FixtureBuilder::createMarkedJpeg($orientation)`: the 300x200 marked photo at quality 85 (`JPEG_QUALITY`, so a kept quality shows), XMP caption, the given Orientation. The row's `rotation` comes from core's own `pwg_image::get_rotation_angle()`, not a copy of its mapping. Also `createGif()` and `seed.php --scenario=jpeg`.

## Verification

* [x] `[HAPPY]` A JPEG with Orientation 6 and `turns` = 0 + a crop is stored upright: Orientation 1, `images.rotation` 0, dimensions as shown before
  **Note:** Verified by `ApplyJpegTest::testAnOrientation6JpegIsStoredUpright`: top half of the shown 200x300 view gives 200x150, `TopLeft`, row rotation 0, and the marker top-right where the page showed it.
* [x] `[ECP]` Orientation 1 and 6, each with turns 0 and 1
  **Note:** Verified by `ApplyJpegTest::testTheFileIsWhatThePageShowedTurnedAsAsked`: 4 cases × 3 libraries. Size, marker corner, Orientation 1, quality and row rotation are all checked. Unit: `TurnTest` (`photoedit_raw_turns`) and `CropTest` (stored rotation baked into the plan, turns that undo it, COI unaffected by the stored rotation).
* [x] The re-encoded JPEG's quality equals the source's (Imagick read-back)
  **Note:** Same test, all 12 cases: `identify -format %Q` = 85 after the save, with every library.
* [x] The region of an Orientation-6 fixture sits on the same spot before and after (display coordinates)
  **Note:** Verified by `ApplyJpegTest::testARegionStaysOnTheSpotThePageShowed`. The display position before the edit is computed in the test from core's real turn, not through persons. `rotation_at_write` goes 3 → 0.
  **Found:** persons' **overlay** places regions on an Orientation-6/8 photo half a turn away. It reads the code as clockwise; this predates this change. By your choice (2026-10-05) it is recorded, not fixed: the skipped reproducing test `plugins/persons/tests/Unit/DisplayRotationTest.php` (checked to fail for the right reason with the skip removed: expected 80 %, got 10 %), decision 0035, and a `docs/backlog.md` entry. Once photoedit saves such a photo (code 0), persons draws it correctly.
* [x] `[NEG]` A format that is neither PNG nor JPEG (e.g. a GIF fixture) is refused, the button disabled with the reason
  **Note:** Verified by `ApplyJpegTest::testAGifIsRefusedAndTheButtonSaysWhy` (`WS_ERR_INVALID_PARAM`, md5 unchanged, `photoedit-disabled` with a non-empty `data-unavailable`).
* [x] (added) E2E: the re-encode note
  **Note:** `jpeg-save.spec.js`: declining writes nothing; accepting saves the turn after exactly one confirm. A PNG save asking nothing is covered by `turn-save.spec.js`, which registers no dialog handler.
* [x] (added) Suites
  **Note:** 2026-10-05. photoedit: unit 90, integration 81 (default and reverse order), E2E 25. persons: unit 142 (1 skipped: the new known-bug test), integration 110 (1 existing skip), E2E 120. provenance: not re-run; no provenance file changed in this task.

Mutation testing skipped on request.

## Known limits, not fixed

* The copied EXIF `ExifImageWidth`/`ExifImageHeight` and the embedded EXIF thumbnail still describe the photo before the edit. Core reads neither (sizes come from `getimagesize()`); other viewers may show the old thumbnail.
* Without the Imagick extension, the quality cannot be read and falls back to 95.
