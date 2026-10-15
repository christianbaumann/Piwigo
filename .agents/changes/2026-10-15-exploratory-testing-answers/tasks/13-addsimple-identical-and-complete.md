---
id: 13
dependencies:
- 02
---

# Task 13: `addSimple` refuses an identical file and completes the upload

An `addSimple` upload ends in its album with Herkunft, or is refused when the file already exists.

## References

* `design.md#addsimple-refuses-a-file-identical-to-an-existing-photo`
* `design.md#addsimple-completes-the-upload`

## Work

* [ ] In `galleryrules`, wrap `pwg.images.addSimple`: identical file refused with an error naming the existing photo, which stays untouched
* [ ] A successful `addSimple` empties the lounge for that photo, so it joins its album and inherits Herkunft at once

## Verification

* [ ] Integration: identical file refused, existing photo's name and file unchanged
* [ ] Integration as normal: the returned album URL answers 200 at once; Herkunft set without `uploadCompleted`
* [ ] galleryrules and provenance suites pass
