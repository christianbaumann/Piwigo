---
id: 08
dependencies:
- 01
---

# Task 08: Typed tag names are NFC and match by name only

Typing a tag never makes an invisible duplicate or assigns a marker tag, and the user sees which stored tag the photo got.

## References

* `design.md#typed-tag-names-are-nfc-and-match-by-name-only`
* `plugins/typetags/main.inc.php:395`, `:475`
* core `tag_id_from_tag_name()` `admin/include/functions.php:1709`, `get_tag_ids`

## Work

* [ ] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [ ] NFC-normalize input in typetags `addNewTag` and core `get_tag_ids`
* [ ] typetags matches by name only, no `url_name` fallback; `typetags_tag_exists()` follows
* [ ] Answer and badge carry the stored tag name
* [ ] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [ ] Unit/integration: NFD `Häuser` assigns the NFC tag; `Name` creates `Name`, not `Name ?`; `Kategorie` creates `Kategorie`
* [ ] `Häuser Ortsansichten` assigns `hauser ortsansichten` and the answer names it
* [ ] Daily-limit count unchanged for an existing tag
* [ ] typetags and provenance suites pass
