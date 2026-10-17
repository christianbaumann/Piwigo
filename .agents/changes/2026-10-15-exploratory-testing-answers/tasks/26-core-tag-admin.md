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

* [x] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
  **Note:** New `CoreOrphanTagsCharacterizationTest` (provenance integration; 7 cases at the time): the cleanup on all three paths (tags page, maintenance actions tab, maintenance env tab) removes a plain unused tag, spares a tag changed within a day, keeps a tag on a photo, and (then) swept coloured and person tags up too; the tags page hands the unused names and the count and confirm strings to the dialog. The cleanup is global, so the test snapshots the install's unused tags and puts back what the run swept away. Each case watched red with core flipped in the container under the suite lock (restored, checksums equal, `git diff` of `admin/` showed only this task's edits): `delete_tags()` call removed (plain, BVA, coloured, person die), the one-day clause dropped (BVA dies), `id_typetags IS NULL` added to the query (coloured dies), persons' tags excluded in the query (person dies), `tags.tpl`'s `str_number_photos` given `|@translate` (strings test dies), `$orphan_tags = array()` in `admin/tags.php` (name-list test dies). The tag menu's count and `tags.js` had no test below the browser: they are witnessed by the new E2E spec. The coloured and person cases were replaced on purpose by galleryrules' `OrphanTagsTest` (their behaviour is what this task changes).
* [x] Menu count: `str_number_photo` / `str_number_photos` with `|@translate`; `tags.js:65`, `:91`, `:775` pick the singular for 1
  **Note:** `photoCountText()` in `tags.js` serves the three call sites. The server-side `tagContent` in `tags.tpl` had the same defect (`'%d photos'|@translate:$tag_count`) and now uses `translate_dec`; see Deviations.
* [x] Rename failure calls `showError()`; Duplikat sets `raw_name`
* [x] `get_orphan_tags()` leaves out coloured-group and person tags; confirm lists one name per line; fix the cleanup wording
  **Note:** Through a fork-local `get_orphan_tags` filter and `plugins/galleryrules/include/events_orphan_tags.inc.php` (decision 0076; see Deviations). Wording: `local/language/de_DE.lang.php` overrides `You have %s1 orphan : %s2` and `You have %d orphan tags %s` ("Nicht benutzte Schlagworte (n)"), with rows in `GermanOverrideKeyTest`; handbook ch4 updated, `check.php` OK.
* [x] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Deviations

* The design puts the exclusion into core `get_orphan_tags()`. That would make core name two plugins' tables, so core instead passes the rows through `trigger_change('get_orphan_tags', ...)` and `plugins/galleryrules` (always activated by the deploy) filters them. Without galleryrules active the cleanup deletes those tags as before. Recorded in decision 0076.
* Added beyond the design: the server-rendered menu count in `tags.tpl` (`tagContent`) also said "1 photos"; fixed with `translate_dec` and witnessed by `TagMenuCountTest` (page source). The design's list named only the three `tags.js` lines.
* The confirm's German wording is `Nicht benutzte Schlagworte (n):` (no plural form to get wrong for 1), the design only said "fixed".
* The merge path (`tags.js` `mergeGroups`) uses `photoCountText()` too but has no E2E case: merging in the selection mode was not driven.

## Verification

* [x] E2E: "1 Foto" and "2 Fotos"; rename to an existing name shows the error; search and delete after Duplikat throw nothing
  **Note:** Verified via galleryrules `tag-admin.spec.js` (4 specs: count as the server renders it and as `tags.js` re-fills boxes, refused rename, search and delete after a Duplikat with no page error, the review list one name per line). Each watched red with the fix reverted in the container: `photoCountText` always plural, `showError` removed, `raw_name` removed from the Duplikat's data, the `<br>` join reverted. A first version of the count spec passed with the plural mutant because it asserted the server-rendered text before `tags.js` had re-filled the box; it now waits for the search to leave one box.
* [x] Integration: orphan cleanup on all three paths skips coloured and person tags, deletes a plain unused tag
  **Note:** Verified via galleryrules `OrphanTagsTest` (plain tag deleted on each path; coloured and person tag kept on each path beside a plain one that goes; the confirm list names only the plain one). Watched red with the `trigger_change` removed from core, with the colour test inverted and with the person ids left out of the filter. `TagMenuCountTest` witnesses the server-rendered count, red with the old `translate:$tag_count` form.
* [x] provenance suites pass
  **Note:** 2026-10-17, run after the change: provenance unit OK (307), integration OK (369, 3 skipped), E2E 62 passed; galleryrules unit OK (42), integration OK (73), E2E 18 passed; persons `admin-tag-guard.spec.js` (uses `tags.js`) 6 passed; `ddev exec php handbuch/tools/check.php` OK.
