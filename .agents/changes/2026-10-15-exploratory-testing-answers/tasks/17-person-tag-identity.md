---
id: 17
dependencies:
- 16
---

# Task 17: persons: a person's tag is exact, re-created when missing, removed on delete

A person always has exactly its own tag while it exists, and loses it on delete.

## References

* `design.md#a-persons-tag-is-found-by-exact-name-and-re-created-when-missing`
* `design.md#deleting-a-person-removes-the-persons-tag`
* `index.inc.php:334`, `:501`

## Work

* [ ] Resolve the tag by exact name, no `url_name` fallback
* [ ] Re-create the tag when `persons.tag_id` points at none; `persons_person_gallery_url()` checks the tag exists
* [ ] Person delete removes the tag from photos, the tag list, and the keywords from the files (through photoinfo, persons lock first)

## Verification

* [ ] Integration: person `Name` gets tag `Name`, not `Name ?`
* [ ] Integration: after its tag row is deleted, the next mark re-creates it and the link answers 200
* [ ] Integration: delete removes tag rows and keywords from every affected file
* [ ] persons and photoinfo suites pass
