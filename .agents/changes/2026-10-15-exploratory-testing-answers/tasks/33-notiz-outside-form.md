---
id: 33
dependencies: []
---

# Task 33: Saving the Notiz leaves the photo form clean

Leaving the photo properties page after a Notiz save raises no unsaved-changes warning.

## References

* `design.md#saving-the-notiz-leaves-the-photo-form-clean`
* `plugins/provenance/include/functions.inc.php:779`, `events_admin.inc.php:180`

## Work

* [ ] Place the Notiz block outside `<form id="pictureModify">`

## Verification

* [ ] E2E: Notiz save, navigate away, no `beforeunload` dialog
* [ ] Editing a core field still warns
* [ ] provenance suites pass
