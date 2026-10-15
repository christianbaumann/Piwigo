---
id: 10
dependencies: []
---

# Task 10: typetags: failed saves are reported and undone

Every failed tag or colour save shows its reason and leaves the page in the saved state.

## References

* `design.md#-and-x-report-a-failed-save`
* `design.md#german-messages-give-the-reason`
* `design.md#schlagwort-farben-festlegen-reports-a-failure`
* `plugins/typetags/include/events_public.inc.php`, `template/tags.js:97-226`

## Work

* [ ] `+` and `x`: on failure show the typing path's message with the reason, revert the badge
* [ ] A 401 gives "Sitzung abgelaufen – bitte neu anmelden" (override or plugin string, row in `GermanOverrideKeyTest` if an override)
* [ ] festlegen: catch shows the error, removes `loading`, hides an old "Farbe gespeichert"; `stat:"fail"` counts as failure
* [ ] Submodule commit, pushed

## Verification

* [ ] E2E per role with a read-only photo: `+` and `x` show the message, badge reverted
* [ ] E2E: expired session shows the reason
* [ ] E2E: a forced festlegen failure shows an error, a second save goes through without reload
* [ ] typetags and provenance unit suites pass
