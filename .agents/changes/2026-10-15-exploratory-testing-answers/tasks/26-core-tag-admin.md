---
id: 26
dependencies:
- 01
---

# Task 26: Core tag admin page: counts, errors, Duplikat, cleanup

The tag admin page reports every failure and its cleanup never offers coloured or person tags.

## References

* `design.md#the-tag-menu-counts-1-foto--n-fotos-a-fork-local-core-edit`
* `design.md#tag-admin-page-errors-show`
* `design.md#the-unused-tag-cleanup-leaves-coloured-and-person-tags-alone`
* `admin/themes/default/js/tags.js`, `admin/themes/default/template/tags.tpl`, `admin/include/functions.php:430`

## Work

* [ ] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [ ] Menu count: `str_number_photo` / `str_number_photos` with `|@translate`; `tags.js:65`, `:91`, `:775` pick the singular for 1
* [ ] Rename failure calls `showError()`; Duplikat sets `raw_name`
* [ ] `get_orphan_tags()` leaves out coloured-group and person tags; confirm lists one name per line; fix the cleanup wording
* [ ] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [ ] E2E: "1 Foto" and "2 Fotos"; rename to an existing name shows the error; search and delete after Duplikat throw nothing
* [ ] Integration: orphan cleanup on all three paths skips coloured and person tags, deletes a plain unused tag
* [ ] provenance suites pass
