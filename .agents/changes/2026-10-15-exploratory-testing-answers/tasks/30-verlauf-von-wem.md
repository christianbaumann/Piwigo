---
id: 30
dependencies: []
---

# Task 30: The Verlauf column reads "Von wem"

The Verlauf header matches the handbook.

## References

* `design.md#the-verlauf-column-reads-von-wem`

## Work

* [ ] `plugins/provenance/language/de_DE/plugin.lang.php:37` `$lang['By']` = `'Von wem'`
* [ ] Update tests that expect "Von"; re-shoot only screenshots showing it (`.claude/rules/handbook.md`)

## Verification

* [ ] Read-only page shows "Von wem"
* [ ] provenance suites pass; `check.php` passes
