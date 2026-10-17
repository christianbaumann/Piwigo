---
id: 39
dependencies:
- 19
---

# Task 39: Deleting an album with "keep photos" leaves no photo in no album

Added 2026-10-17 from task 19's finding (status.md question 3, default answer a).

## References

* `design.md#a-photos-last-album-cannot-be-removed`
* Task 19 (Deviations: `pwg.categories.delete` with `photo_deletion_mode=no_delete` not covered)

## Work

* [ ] Refuse the delete while it would leave a photo in no album, naming the photos, as task 19 does (user's choice 2026-10-17, default answer a); record it as a decision
* [ ] Implement in `galleryrules` on every path that deletes an album keeping its photos (album properties page, album list, `pwg.categories.delete`)

## Verification

* [ ] Integration: deleting an album that is a photo's only album with "keep photos" leaves the photo in an album (or is refused, per the decision); a photo with another album is unaffected
* [ ] galleryrules suites pass
