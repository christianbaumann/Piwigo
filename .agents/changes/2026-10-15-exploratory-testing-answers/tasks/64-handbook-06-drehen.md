---
id: 64
dependencies:
- 56
---

# Task 64: Chapter 06 warns what a restore brings back and describes failed saves and keys

Added 2026-10-19. Chapter 06 matches the code for every 06 finding of the 2026-10-19 review.

## References

* `design-handbook-gap-fix.md#the-findings-and-where-they-go` (rows for chapter 06)
* `handbuch/06-drehen.html` (*Rückgängig machen*, lines 136-144)
* Decision 0084; task 56; `plugins/photoedit/template/editor.js` (`NAV_KEYS`, `CTRL_NAV_KEYS`)

## Work

* [ ] *Rückgängig machen*: the restored copy brings back its own Info, date, tags and person marks; changes made after the edit are lost
* [ ] A failed save shows a message with the reason (German after task 56); edit mode stays open
* [ ] In edit mode the arrow keys do not change the photo
* [ ] Each new statement checked once in the browser on the demo album; result as a note here

## Verification

* [ ] `check.php` passes for 06
* [ ] Every quoted German string matches the language files
