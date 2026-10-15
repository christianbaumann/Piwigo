---
id: 15
dependencies: []
---

# Task 15: photoinfo writes tags only on a real change

A tag call that changes nothing leaves the image file untouched.

## References

* `design.md#tags-are-written-to-the-file-only-on-a-real-change`

## Work

* [ ] Skip the tag write when the photo's tag set did not change; answer `tags_written: false`

## Verification

* [ ] Integration: remove of an absent tag, file checksum unchanged, no `_original`, `tags_written: false`
* [ ] A real change still writes
* [ ] photoinfo suites pass
