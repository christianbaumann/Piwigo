---
id: 18
dependencies:
- 01
- 17
---

# Task 18: Tag tools refuse to remove or delete a person's tag

No tag tool can separate a person's tag from its mark.

## References

* `design.md#tag-tools-refuse-to-remove-or-delete-a-persons-tag`
* decision 0031
* core `set_tags_of`, `del_tags`, `admin/tags.php`, `pwg.tags.delete`, typetags `removeTag`

## Work

* [ ] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [ ] Refuse removal of a person tag from a photo in typetags `x` (also hide `x` on person tags), Eigenschaften, Stapelverarbeitung, `pwg.images.setInfo`, `pwg.tags.*`
* [ ] Refuse deleting a person tag in Schlagwortverwaltung; message points to the mark or to Personen
* [ ] Lookup via `persons.tag_id`
* [ ] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [ ] Integration per path: refused, tag still on the photo, link 200
* [ ] E2E: no `x` on a person tag; Schlagwortverwaltung shows the message
* [ ] Ordinary tags still removable on every path
* [ ] persons, typetags, provenance suites pass
