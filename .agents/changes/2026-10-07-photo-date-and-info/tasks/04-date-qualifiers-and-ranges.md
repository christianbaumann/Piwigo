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

* [x] Extend the pure functions: qualifier (`circa`, `before`, `after`, `between`, NULL for exact), range end with its own precision, end not before start, `ca.` never with a range; German text and EDTF for every combination
* [x] Extend `pwg.photoinfo.setDate` with qualifier and end date; `DateCreated` keeps the start date
* [x] Datum row: qualifier select with an empty first option (`— | ca. | vor | nach | zwischen`); the second date appears only with `zwischen`
* [x] Tests: decision table over qualifier × precision × end (unit), WS validation (integration), select and second date (E2E)

### Implementation notes (2026-10-08)

Choices the design left open:

* **A "dating" wraps task 03's date:** `array('qualifier', 'start', 'end')`, built by `photoinfo_dating_from_input()` / `photoinfo_dating_from_row()`, stored by `photoinfo_dating_columns()`, shown by `photoinfo_dating_display()` / `photoinfo_dating_edtf()` (`include/date.inc.php`). Task 03's single-date functions are unchanged and are the building blocks; `DateTest` did not change.
* **"End not before start" compares at the coarser precision** (`photoinfo_date_compare()`): `1965–1965` and `14. März 1965–März 1965` pass, `1965–1964` and `14.–13. März 1965` are refused. Equal is allowed.
* **The WS method refuses rather than drops** what the model forbids: an end without `between`, `between` without an end, `ca.` with an end, a qualifier with no date, an unknown qualifier. The page never sends those: it clears the end when the select leaves `zwischen`, and sends every field empty when the year is empty.
* **The qualifier select is disabled while the year is empty**, like the month (dependent enabling); a disabled select hides the end and sends `''`.
* **Range text has no spaces around the en dash** in every precision (`März 1965–2. Mai 1966`); the design gives only the year example.
* **Qualifier labels are hardcoded German** (`photoinfo_qualifier_labels()`), like the month names, and the column's ENUM is built from the same list. No schema change: task 03 created all four columns, so no version bump.
* **New WS parameters** `qualifier`, `end_year`, `end_month`, `end_day`, all defaulting to `''` for the same reason as `year`.
* **Fixture reader fix:** `FixtureBuilder::readDateTags()` now reads `XMP-pwginfo:DateEDTF` from the raw XMP packet as well. Without photoinfo's exiftool config, exiftool guesses the unknown tag's type and reads `1965/1970` as `0.99746…` (exiftool 13.25, measured 2026-10-08); the file holds the right string, and exiftool with `-config plugins/photoinfo/exiftool/pwginfo.config` reads it back correctly. **Task 07's `pwg.photoinfo.rescan` must read the EDTF tag with that config**, or every year range comes back as a fraction. `.claude/rules/plugin-test-suites.md` updated.

Review fixes (2026-10-08, after a review subagent): a `between` row with no end now reads as an exact date instead of showing `1965–` and writing EDTF `1965/` (`DatingTest::testARangeWithNoEndIsExact`, written first and seen red); the two one-line closures in `date.inc.php` became `photoinfo_dating_error()` and a defaulted row, matching core style; `FixtureBuilder::setQualifier()` asserts the end precision too; `date-qualifier.spec.js` "a range the file does not take reopens with its qualifier and end" covers `date.js`'s saved state after a failed write (red with `saved` mutated, then reverted). Not changed: the tests type `'circa'`/`'between'` rather than the constants, since those strings are the WS contract and ENUM values the design fixes. Interim stale columns after a core edit or sync went into tasks 05 and 06 as verification items; the EDTF read and `vor` inclusivity into task 07.

Strength check (hand mutants, each reverted, container sync verified by md5 before every run):

| Mutant | Expected killer | Result |
|---|---|---|
| end check `< 0` → `<= 0` | `DatingTest` [BVA] same year twice, end inside the start month | killed (2) |
| `photoinfo_date_compare()` `or` → `and` on a missing part | `DatingTest::testDatesCompareAtTheCoarserPrecision`, end inside start month | killed (3) |
| circa EDTF loses its `~` | `DatingTest` circa rows | killed (6) |
| `before` writes `1965/..` | `DatingTest` before rows | killed (4) |
| `date.js`: switching away from zwischen keeps the end | `date-qualifier.spec.js` [ST] switching away clears the second date | killed |
| `date.js`: no end-before-start check | `date-qualifier.spec.js` [NEG] range ending 1964 | killed |

## Verification

Approved 2026-10-08 after `/verify`: no manual steps remain.


* [x] Each row of the design's combination table produces its "Shown as" text on the page and its EDTF string in `XMP-pwginfo:DateEDTF` **Note:** `e2e/date-qualifier.spec.js` [HAPPY] ×4 (ca., vor, nach, zwischen) and `date-edit.spec.js` (exact), page text + EDTF read with plain exiftool; `SetDateTest::testEachQualifierGoesIntoRowAndFile` (columns, both DateCreated slots keep the start, EDTF, caption); `DatingTest` [DT] 16 combinations
* [x] A range whose end is before its start is refused, by the UI and by the WS method **Note:** UI: `date-qualifier.spec.js` [NEG] end 1964 and missing end, no request sent; WS: `SetDateTest::testAnImpossibleRangeIsRefused` (row and file md5 unchanged); unit: `DatingTest::testRefusedInputs`
* [x] The second date is hidden unless `zwischen` is chosen, and switching away clears it **Note:** `date-qualifier.spec.js` [ST] ×2
* [x] Choosing the empty option saves an exact date with no qualifier **Note:** `date-qualifier.spec.js` [ST] the empty option turns a saved range back into an exact date; `SetDateTest::testAnExactDateClearsAFormerRange`
* [x] Task 03's exact and partial dates behave as before **Note:** `date-edit.spec.js`, `SetDateTest` precision/clear/refusal cases, `DateTest` all unchanged and green; `BuildArgfileTest` changed only to pass a dating instead of a date
* [x] photoinfo suites pass twice in a row **Note:** 2026-10-08: photoinfo PHPUnit (unit + integration) 200 OK twice, second run `--order-by=reverse`; E2E 29 passed twice; after the review fixes PHPUnit 201 OK and E2E 30 passed. Provenance integration 189 OK (4 skips) once, since its write-back calls photoinfo's caption handler
