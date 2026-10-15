---
id: 24
dependencies: []
---

# Task 24: German overrides for named messages

Named messages read in clean German.

## References

* `design.md#german-messages-give-the-reason`
* `design.md#merge-messages-lose-their-braces`
* `design.md#pwg-update-1-names-digits`
* `design.md#album-properties-move-errors-show-sub-albums-are-not-offered`
* `.claude/rules/handbook.md` (German strings)

## Work

* [ ] `local/language/de_DE.lang.php`: "A person needs a name", both merge keys without braces, PWG-UPDATE-1 with digits, "Just now"
* [ ] One row each in `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php`

## Verification

* [ ] provenance unit suite passes with the new rows
* [ ] Each row goes red when its literal is renamed
* [ ] Spot check in the browser for the merge modal
