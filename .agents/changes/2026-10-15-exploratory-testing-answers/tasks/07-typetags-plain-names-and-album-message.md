---
id: 07
dependencies: []
---

# Task 07: typetags: plain tag names in ws answers; clear `addNewTag` refusal

API clients get plain tag names, and `addNewTag` on a photo in no visible album says why.

## References

* `design.md#the-api-returns-plain-tag-names`
* `design.md#addnewtag-keeps-its-album-check`
* `plugins/typetags/main.inc.php` (`typetags_image_visible()`)

## Work

* [ ] Colour markup only on rendered pages, never in a `ws.php` answer (`pwg.tags.getList`, `pwg.images.getInfo`, any other tag-returning method)
* [ ] `addNewTag` refusal text "Foto ist in keinem sichtbaren Album"; decision file for keeping the check
* [ ] Submodule commit, pushed

## Verification

* [ ] Integration: typed tag names in `pwg.tags.getList` and `pwg.images.getInfo` equal the stored names
* [ ] Picture page and tag pages still show colours (E2E)
* [ ] Integration: admin `addNewTag` on a photo in no album returns the new text
* [ ] typetags suites pass
