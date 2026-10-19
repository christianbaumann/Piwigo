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

* [x] *bearbeiten*: renaming a person's tag is refused, with the German message; "Der Name ändert sich überall" qualified
  **Note:** Verified via browser (webmaster, demo album): `pwg.tags.rename` on `Anna Beispiel` answers err 1003 `Personen-Schlagworte werden nur zusammen mit der Person umbenannt: Anna Beispiel. Die Person benennt der Webmaster auf der Seite Personen um.`
* [x] *Farbige Schlagworte*: "Gestreift" and the emoji are set on the plugin page (path to *Farben verwalten*), with the field labels
  **Note:** Verified via browser: `admin.php?page=plugin-typetags` (Verwaltung » Plugins, `Einstellungen`) shows legends `Eine neue Farbe hinzufügen`, `Farben verwalten`; fields `Name`, `Farbe`, `Gestreift`, `Emoji`; the `bearbeiten` icon opens `Farbe editieren` with the same fields.
* [x] Replace "auf welchem Weg auch immer" with the UI paths that write tags into the file
* [x] *Schlagworte in der Bilddatei*: the failure message in the Stapelverarbeitung shows at the top in global mode and in the photo's badge in unit mode (true after task 55); link to the figure in 03
  **Note:** Unit mode not driven in the browser; covered by photoinfo `batch-unit-write-warning.spec.js` and `CoreEditTest` (task 55). Link goes to chapter 03 without an anchor (figure added by task 61). The list of tag-writing paths follows decision 0046/0050 (not browser-driven; photoinfo `TagWriteTest`).
* [x] Each new statement checked once in the browser on the demo album; result as notes above

## Verification

* [x] `check.php` passes for 04
  **Note:** `ddev exec php handbuch/tools/check.php`: OK, 8 pages
* [x] Every quoted German string matches the language files
  **Note:** Verifier 2026-10-19: all quoted strings grepped in persons/typetags/photoinfo language files; fixed a doubled word and added the replace case (decision 0084); check.php reports no problem for 04 (remaining FAILs are unreferenced screenshots 29-31 of other tasks).
