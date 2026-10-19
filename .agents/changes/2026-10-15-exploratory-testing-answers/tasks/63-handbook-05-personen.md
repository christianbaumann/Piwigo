---
id: 63
dependencies: []
---

# Task 63: Chapter 05 describes the person-tag guard per screen and the marking-mode details

Added 2026-10-19. Chapter 05 matches the code for every 05 finding of the 2026-10-19 review, including the wrong Stapelverarbeitung sentence.

## References

* `design-handbook-gap-fix.md#the-findings-and-where-they-go` (rows for chapter 05)
* `handbuch/05-personen.html` (*Nach Personen suchen*, lines 180-195)
* `plugins/persons/include/tag_guard.inc.php` (`persons_guard_ws_invoke()`, `persons_batch_refuse_del_tags()`, `persons_properties_keep_marked_tags()`), `template/editor.js`, `editor.css`, `template/admin_photo_link.tpl`, `admin/photo.php`; decisions 0061, 0065

## Work

* [ ] Line 183: Eigenschaften puts the tag back and saves the rest; Stapelverarbeitung unit mode refuses that photo's whole save and shows the message in its badge; global action "Schlagworte entfernen" is refused for the whole selection
* [ ] Renaming a person's tag in the tag screen is refused; the person is renamed on the Personen page by the webmaster
* [ ] Marking mode: every name shows, shortened with "...", full name on hover; dragging inside a box draws a new one; "Markierung ist nicht mehr vorhanden" when the mark is already gone; stale boxes have a lighter border
* [ ] Admin tagging screen: the people icon on a photo's edit page, and its refusal for a photo in no album the admin can access
* [ ] The picker offers only persons on photos the account can see; typing the full name still finds the person
* [ ] Each new statement checked once in the browser on the demo album; result as a note here

## Verification

* [ ] `check.php` passes for 05
* [ ] Every quoted German string matches the language files
