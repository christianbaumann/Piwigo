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

* [ ] Pure function: crop fractions of the turned view → integer pixel rect of the turned raw file (rounding, clamping)
* [ ] Extend the COI transform to crop (COI fully outside → `NULL`, partly outside → clipped)
* [ ] `pwg.photoedit.apply` accepts `crop`; pipeline crops after turning
* [ ] `editor.js`: Jcrop frame starting as the whole photo, kept over a turn of the preview (or reset, as decided while implementing — record which)

## Verification

* [ ] `[HAPPY]` Cropping a fixture to its left half gives a file of half the width, read back by ImageMagick
* [ ] `[DT]` turn × crop: turn 1 + crop gives the same pixels as turning then cropping by hand (pixel markers)
* [ ] `[BVA]` A frame covering the whole photo with `turns` = 0 is "nothing to do"; a frame of 1 px is refused below a named minimum; `l >= r` or values outside 0..1 are refused
* [ ] COI: inside the crop is moved, outside becomes `NULL`
* [ ] E2E: draw a frame, save, the shown photo has the cropped aspect ratio
