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

* [x] Line 183: Eigenschaften puts the tag back and saves the rest; Stapelverarbeitung unit mode refuses that photo's whole save and shows the message in its badge; global action "Schlagworte entfernen" is refused for the whole selection
* [x] Renaming a person's tag in the tag screen is refused; the person is renamed on the Personen page by the webmaster
* [x] Marking mode: every name shows, shortened with "...", full name on hover; dragging inside a box draws a new one; "Markierung ist nicht mehr vorhanden" when the mark is already gone; stale boxes have a lighter border
* [x] Admin tagging screen: the people icon on a photo's edit page, and its refusal for a photo in no album the admin can access
* [x] The picker offers only persons on photos the account can see; typing the full name still finds the person
  **Note:** Verified by `VisibilityTest::testThePickerDoesNotOfferAPersonOnlyOnAHiddenPhoto` and `testSavingTheFullNameOfAHiddenPersonAttachesTheExistingPerson` (read, not re-run); not re-checked in the browser.
* [x] Each new statement checked once in the browser on the demo album; result as a note here
  **Note:** 2026-10-19, demo album seeded, Playwright (Chromium in the container, persons test accounts, under the suite lock), then `seed.php --restore`:
  * normal, tagging mode: both labels opacity 1 with `text-overflow: ellipsis`; pointer in a box: that box gets `person-box-active`, label `max-width: none` (full name); labels did not need cutting for these short names (the ellipsis is the CSS rule, not seen cut)
  * normal, drag started inside a box: a draft box and the picker open
  * stale border: CSS rule `#persons-stage.persons-tagging .person-box-stale { border-color: rgba(255,255,255,.45) }` read; no stale box in the demo, not seen
  * webmaster: region deleted by a second session, then the x clicked: message "Markierung ist nicht mehr vorhanden", box gone
  * tag rename of a person's tag via `pwg.tags.rename`: refused, "Personen-Schlagworte werden nur zusammen mit der Person umbenannt: Anna Beispiel. Die Person benennt der Webmaster auf der Seite Personen um."
  * admin photo page: `#persons-admin-photo-link` (`icon-users`, title "Personen markieren") links to `admin.php?page=plugin-persons&image_id=ID`, which shows the tagging screen; the no-album refusal not re-run (read in `admin/photo.php`)
  * Eigenschaften: title changed + Anna Beispiel's x removed, saved: title saved, tag still there, person-tag message shown
  * Stapelverarbeitung Einzelmodus: same edit: title not saved, tag kept, badge "Es ist ein Fehler aufgetreten" plus the person-tag message
  * global "Schlagworte entfernen" (POST of the form over all 6 demo photos): refused with the person-tag message; Anna Beispiel still on the photo afterwards (second seed->check->restore cycle under one lock, restored)

## Verification

* [x] `check.php` passes for 05
  **Note:** `ddev exec php handbuch/tools/check.php`: OK, 8 pages, 74 references, 28 screenshots, 8 admin routes.
* [x] Every quoted German string
  **Note:** Markierung ist nicht mehr vorhanden, Personen markieren, Auf diesem Foto können Sie keine Personen markieren: es liegt in keinem Album, auf das Sie Zugriff haben (plugin.lang.php), Schlagworte entfernen (admin.lang.php), Foto bearbeiten (admin.lang.php); Stapelverarbeitung and Eigenschaften in the same files. matches the language files

## Verifier (2026-10-19)

* Strings grepped in plugins/persons/language/de_DE/plugin.lang.php, language/de_DE/admin.lang.php (Es ist ein Fehler aufgetreten, Foto bearbeiten); badge text per `batch_manager_unit.tpl` + `showErrorLocalBadge(pictureId, data.message)` correct. Guard statements match `tag_guard.inc.php`. No-album refusal and condition read in `plugins/persons/admin/photo.php`; icon injected into `.picture-preview-actions` on the properties tab (`events_admin.inc.php`). Picker text matches decision 0061.
* `--filter VisibilityTest` (persons integration, under lock): OK (21 tests, 73 assertions).
* `check.php`: only FAILs are screenshots 29, 30, 31 not yet referenced (other tasks' pages); nothing from 05.
* APPROVED
