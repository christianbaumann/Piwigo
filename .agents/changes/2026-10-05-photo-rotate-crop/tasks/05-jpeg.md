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

* [ ] Pipeline: total turn = `images.rotation` + `turns`; after writing, EXIF Orientation = 1 and `images.rotation` = 0
* [ ] Re-encode with the quality read from the file, else 95 (named constant)
* [ ] `dry_run` returns `lossy = true` for JPEG; `editor.js` shows the quality note
* [ ] persons' `photoedit_end` uses `rotation_before` so regions stored pre-rotation land right, and `rotation_at_write` is updated
* [ ] Fixtures: a JPEG with Orientation 1 and one with Orientation 6

## Verification

* [ ] `[HAPPY]` A JPEG with Orientation 6 and `turns` = 0 + a crop is stored upright: Orientation 1, `images.rotation` 0, dimensions as shown before
* [ ] `[ECP]` Orientation 1 and 6, each with turns 0 and 1
* [ ] The re-encoded JPEG's quality equals the source's (Imagick read-back)
* [ ] The region of an Orientation-6 fixture sits on the same spot before and after (display coordinates)
* [ ] `[NEG]` A format that is neither PNG nor JPEG (e.g. a GIF fixture) is refused, the button disabled with the reason
