---
id: 62
dependencies:
- 55
---

# Task 62: Chapter 04 describes the person-tag rename refusal, stripes and emoji, and where tags reach the file

Added 2026-10-19. Chapter 04 matches the code for every 04 finding of the 2026-10-19 review.

## References

* `design-handbook-gap-fix.md#the-findings-and-where-they-go` (rows for chapter 04)
* `handbuch/04-schlagworte.html` (*bearbeiten*, *Farbige Schlagworte*, *Schlagworte in der Bilddatei* around line 340)
* `plugins/persons/include/tag_guard.inc.php` (`pwg.tags.rename` branch, `PERSONS_TAG_RENAME_REFUSED_MESSAGE`); `plugins/typetags/template/admin.tpl` (Gestreift, Emoji); decision 0050; task 55

## Work

* [ ] *bearbeiten*: renaming a person's tag is refused, with the German message; "Der Name ändert sich überall" qualified
* [ ] *Farbige Schlagworte*: "Gestreift" and the emoji are set on the plugin page (path to *Farben verwalten*), with the field labels
* [ ] Replace "auf welchem Weg auch immer" with the UI paths that write tags into the file
* [ ] *Schlagworte in der Bilddatei*: the failure message in the Stapelverarbeitung shows at the top in global mode and in the photo's badge in unit mode (true after task 55); link to the figure in 03
* [ ] Each new statement checked once in the browser on the demo album; result as a note here

## Verification

* [ ] `check.php` passes for 04
* [ ] Every quoted German string matches the language files
