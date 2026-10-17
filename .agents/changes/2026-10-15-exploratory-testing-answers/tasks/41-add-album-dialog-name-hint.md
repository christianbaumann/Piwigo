---
id: 41
dependencies:
- 25
---

# Task 41: The add-album dialog of Fotos hinzufügen and the Batch Manager shows "Name fehlt"

Added 2026-10-17 from task 25's Deviations (user's answer 4a).

## References

* `design.md#a-blank-album-name-shows-a-hint`
* Task 25 (`plugins/galleryrules/include/events_album_name.inc.php`, `template/album_name_hint.tpl`)
* core `admin/themes/default/template/include/add_album.inc.tpl`

## Work

* [ ] Show "Name fehlt" next to the name field of the shared add-album dialog, through the same prefilter-and-include pattern (decision 0036)

## Verification

* [ ] E2E: blank name shows the hint in Fotos hinzufügen and in the Batch Manager; no album created
* [ ] Unit: structural guard that the dialog's name field still occurs once
* [ ] galleryrules suites pass
