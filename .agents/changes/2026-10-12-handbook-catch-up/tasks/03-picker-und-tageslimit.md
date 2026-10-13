---
id: 03
dependencies: []
---

# Task 03: Picker and daily limit

Two text-only corrections: the person picker sentence matches what *Anlegen* does since decision
0061, and the handbook names the daily limit for tags typed in on the picture page.

## References

* `design.md#findings` (findings 3 and 4)
* `design.md#picker-sentence-in-05-personenhtml`
* `design.md#daily-limit-for-new-tags`
* `design.md#hand-check`
* [decision 0061](../../../../docs/agents/decisions/0061-persons-list-and-tagging-screen-respect-album-permissions.md)
* `handbuch/05-personen.html:75-76`
* `handbuch/04-schlagworte.html` section "Farbige Schlagworte auf der Fotoseite setzen", item "Eintippen"
* `plugins/typetags/include/functions.inc.php` (`TYPETAGS_NEW_TAGS_PER_DAY` = 255)
* `local/language/de_DE.lang.php:43` (*Heute wurden schon zu viele neue Schlagworte eingegeben*)

## Work

* [x] `05-personen.html:75-76`: *Anlegen "..."* creates the person, or attaches the existing one of that name. No mention of album permissions
* [x] `04-schlagworte.html` "Eintippen": one sentence: each account can type in at most 255 new tags per day; beyond that the field shows *Heute wurden schon zu viele neue Schlagworte eingegeben*. Assigning an existing tag by name does not count
  **Note:** written as "innerhalb von 24 Stunden", not "pro Tag": the window is `NOW() - INTERVAL 1 DAY`, a rolling 24 hours
* [x] Confirm the last claim against `typetags_typed_tags_condition()` before writing it (only `typetags.image.addNewTag` activity rows with action `add` count)
  **Note:** Verified in `plugins/typetags/main.inc.php` `ws_typetags_image_addNewTag()`: refused only when the count is reached and `!typetags_tag_exists($name)`; the `add` activity row is written only when a new tag id was created
* [x] Add a dated entry to the hand-check ledger in `docs/agents/TESTING.md`: both sentences checked against the code and the picker behaviour

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes
  **Note:** Verified via `ddev exec php handbuch/tools/check.php`: OK 8 pages, 66 references, 26 screenshots
* [x] The limit and the message in the text equal `TYPETAGS_NEW_TAGS_PER_DAY` and the German override at the time of writing
  **Note:** Verified via grep: `define('TYPETAGS_NEW_TAGS_PER_DAY', 255);` and `local/language/de_DE.lang.php:43`
* [x] In the picker, typing the full name of a person who exists only on a photo the account cannot see offers *Anlegen*, and saving attaches that existing person (no duplicate row in the persons table)
  **Note:** Verified via an ad hoc copy of `plugins/persons/tests/Integration/VisibilityTest.php` with one added case, run under the suite lock, then deleted: the person is absent from `persons_normal`'s picker list, `pwg.persons.addRegion` with the full name returns ok, one `piwigo_persons` row for the name, region on the existing id (OK, 22 tests). *Anlegen* offered: `template/editor.js` `renderOptions()` adds it whenever there is no exact match. No browser run
* [x] `git status` shows only the intended files
  **Note:** Verified via `git status`: this task changed only `handbuch/04-schlagworte.html`, `handbuch/05-personen.html`, `docs/agents/TESTING.md` and this file; `handbuch/tools/seed.php` is task 01's (concurrent)
