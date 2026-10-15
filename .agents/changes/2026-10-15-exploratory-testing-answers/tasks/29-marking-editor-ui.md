---
id: 29
dependencies: []
---

# Task 29: Marking editor: delete, draw, labels, picker, search

Every control of the marking editor does what it shows.

## References

* `design.md#the-marking-editor-delete-draw-labels-picker`
* decisions 0019, 0065
* `plugins/persons/template/editor.js`, `editor.css`, `overlay.css`, `admin/persons.php`

## Work

* [ ] Delete control above every box in marking mode
* [ ] A drag starting inside a frame draws a new frame; `x` and label stay clickable
* [ ] Labels ellipsis; full name on hover on top, inside the photo edge
* [ ] Enter filters on the typed text at once, commits only an exact match, else Anlegen
* [ ] 404 on delete removes the box with "Markierung ist nicht mehr vorhanden"
* [ ] Admin search escapes `%`, `_` after `stripslashes`

## Verification

* [ ] E2E per item, as normal and admin where 0065 differs
* [ ] Integration: search for `%` lists only matching names
* [ ] persons suites pass
