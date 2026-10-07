---
id: 04
dependencies:
- 03
---

# Task 04: Date qualifiers and ranges

An administrator can mark a date as `ca.`, `vor` or `nach`, or give a range with `zwischen`; the
page shows `ca. 1965`, `vor 1965`, `nach März 1965` or `1965–1970`, and the file carries the
matching EDTF string.

## References

* `design.md#date-model`
* `design.md#ui`
* `design.md#qualifier-and-range-as-one-select-with-no-genau-option`
* `design.md#year-bounds-1800-to-the-current-year`
* `design.md#german-display-format`
* `design.md#date-tags-in-the-file`

## Work

* [ ] Extend the pure functions: qualifier (`circa`, `before`, `after`, `between`, NULL for exact), range end with its own precision, end not before start, `ca.` never with a range; German text and EDTF for every combination
* [ ] Extend `pwg.photoinfo.setDate` with qualifier and end date; `DateCreated` keeps the start date
* [ ] Datum row: qualifier select with an empty first option (`— | ca. | vor | nach | zwischen`); the second date appears only with `zwischen`
* [ ] Tests: decision table over qualifier × precision × end (unit), WS validation (integration), select and second date (E2E)

## Verification

* [ ] Each row of the design's combination table produces its "Shown as" text on the page and its EDTF string in `XMP-pwginfo:DateEDTF`
* [ ] A range whose end is before its start is refused, by the UI and by the WS method
* [ ] The second date is hidden unless `zwischen` is chosen, and switching away clears it
* [ ] Choosing the empty option saves an exact date with no qualifier
* [ ] Task 03's exact and partial dates behave as before
* [ ] photoinfo suites pass twice in a row
