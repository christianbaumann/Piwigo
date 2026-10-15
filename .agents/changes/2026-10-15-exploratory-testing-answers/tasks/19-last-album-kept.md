---
id: 19
dependencies:
- 02
---

# Task 19: A photo's last album cannot be removed

No path leaves a photo in no album.

## References

* `design.md#a-photos-last-album-cannot-be-removed`

## Work

* [ ] In `galleryrules`, refuse removing a photo's last album: Batch Manager "Von Album abtrennen", properties page, API; moving stays possible

## Verification

* [ ] Integration per path: refused, photo still in its album
* [ ] Move to another album works
* [ ] galleryrules suites pass
