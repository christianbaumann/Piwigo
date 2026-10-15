---
id: 12
dependencies:
- 02
---

# Task 12: Replace refuses an ambiguous file name

The replace option never overwrites a photo it had to guess.

## References

* `design.md#replace-refuses-when-a-file-name-is-ambiguous`

## Work

* [ ] In `galleryrules`, refuse a replace upload when more than one photo in the album has the file name; the error names the matching photos

## Verification

* [ ] Integration: two photos with one name, replace refused, both files unchanged, message names both
* [ ] One match still replaces
* [ ] galleryrules suites pass
