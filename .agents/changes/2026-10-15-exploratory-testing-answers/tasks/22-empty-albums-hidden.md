---
id: 22
dependencies:
- 02
---

# Task 22: Empty albums are hidden from visitors

Visitors never reach an empty album; "In Galerie öffnen" explains it.

## References

* `design.md#empty-albums-are-hidden-from-visitors`
* decision 0037

## Work

* [ ] In `galleryrules`, hide empty albums from non-admins in lists and by URL; "In Galerie öffnen" shows a note for an empty album

## Verification

* [ ] Integration as normal and generic: empty album not listed, URL refused; admin sees the note
* [ ] Album with photos unchanged
* [ ] galleryrules and provenance suites pass
