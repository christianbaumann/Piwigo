---
id: 03
dependencies:
- 02
---

# Task 03: Exact or partial date, end to end

An administrator sets a photo's date to a year, a month and year, or a full day with the Datum row
in `#imageInfos`; the page shows it in German, and the file carries it in `DateCreated`, the EDTF
tag and the caption. Qualifiers and ranges follow in task 04.

## References

* `design.md#date-model`
* `design.md#ui`
* `design.md#store-the-date-in-cores-column-plus-plugin-columns`
* `design.md#year-as-a-numeric-field-month-and-day-as-dropdowns`
* `design.md#year-bounds-1800-to-the-current-year`
* `design.md#german-display-format`
* `design.md#date-tags-in-the-file`
* `design.md#never-write-datetimeoriginal-protect-the-date-on-re-sync`
* `design.md#replace-cores-rows-move-the-description-into-the-panel`
* `themes/default/template/picture.tpl` (`#datecreate` row), `picture.php:856` (`INFO_CREATION_DATE`)
* [EDTF, Library of Congress](https://www.loc.gov/standards/datetime/)

## Work

* [x] Add `photoinfo_date_precision` (and the qualifier/end columns from the date model, unused until task 04) in `maintain.class.php`; drop them on uninstall
* [x] Pure functions in a file with no Piwigo dependency: validate year/month/day (1800 to the current year, days per month, leap years), start date for `date_creation`, German display text, EDTF string, `XMP-photoshop:DateCreated` and `IPTC:DateCreated` values
* [x] `pwg.photoinfo.setDate` (`admin_only`, `post_only`, `pwg_token`): saves `date_creation` and the precision, then writes `DateCreated` (XMP, IPTC), `XMP-pwginfo:DateEDTF` and the caption (readable date part) under provenance's lock; never `DateTimeOriginal`
* [x] Picture page: the Datum row replaces core's "Erstellt am" row; click-to-edit with year field, month and day dropdowns, dependent enabling; empty clears the date
* [x] Tests: unit for every pure function (boundaries: 1799/1800, current year/next year, 28/29/30/31 days, leap years 1900/2000), integration for WS and file tags, E2E for the controls and save

### Implementation notes (2026-10-07)

Deviations and choices the design left open:

* **Caption shape:** photoinfo's caption block is the readable date, a line break, then the info text (`photoinfo_caption_blocks($blocks, $info, $date)`); provenance's block follows after a blank line, as before. Either line is left out when empty. A date save rewrites the caption with the current info text, and an info save with the current date.
* **Year bound only:** "no future dates" is enforced as year <= current year, as the year-bounds decision states. December of the current year is accepted in October.
* **A `date_creation` with no precision counts as exact to the day** (`photoinfo_date_from_row()`): that is a date set by core or by EXIF, which design "Core admin screens set an exact date" treats as exact. Task 06 still has to set the precision on those save paths. A consequence for provenance: such a date now leads every caption a provenance write-back or an info save composes, so a photo dated by core gets its date put in front of a caption provenance alone wrote before. That follows the design (the readable date is part of the composed caption). Measured 2026-10-08: no photo in this install has a `date_creation`, so no existing caption changes.
* **German month names are hardcoded** in `photoinfo_month_names()` (design, "German display format"), not taken from core's `$lang['month']`, so the page and the caption agree whatever the visitor's language.
* **The calendar link follows the precision:** a reader's date links to `created-monthly-list-1965`, `-1965-03` or `-1965-03-14`, never to a day the date does not name.
* **Core's "Created on" row is removed by pattern** (`PHOTOINFO_TPL_DATE_ROW_PATTERN`), not by literal: its lines keep their tabs after core's whitespace prefilter. The empty `{if}` left around it renders nothing. The Datum row does not honour core's `picture_informations` "created_on" switch, because it is the date editor too.
* **Schema arrives by version bump:** plugin version 1.0.0 → 1.1.0, so core's `autoupdate_plugin()` calls `update()` → `install()` on the next request (checked locally: the four columns appeared). `uninstall()` drops them. All four date-model columns are created now; qualifier and range end stay unused until task 04.
* **`year`/`month`/`day` default to `''`**, for the same reason as `info` in task 02: core's ws layer reads an empty string as a missing required parameter, and empty fields are how a date is cleared.
* **Prefilter callback name unchanged**, so `_data/templates_c/` must be purged after this change, locally (done) and on the remote after the deploy (task 07, see `.claude/rules/deployment.md`).
* **exiftool prints an XMP date with colons** (`1965:03`), while the packet holds `1965-03`. `FixtureBuilder::readDateTags()` therefore reads `XMP-photoshop:DateCreated` out of the raw packet. Measured 2026-10-07 with exiftool 13.25: `IPTC:DateCreated=1965:00:00` is accepted, and an empty value deletes every date tag.

Review fixes (2026-10-08, after a review subagent): when the file write fails after a clear, the Datum row now shows its placeholder again instead of going empty (`date.js`, `data-placeholder`), covered by `date-edit.spec.js` "a clear the file does not take keeps the row clickable", which went red with the fix reverted. In the caption handler, values the caller passes now win over stored ones (`array_merge($row, $image)`). The refusal tests in `SetDateTest` now assert the file's md5 is unchanged, rather than the absence of a tag that was never written. The stale-precision case after a core edit was added to task 06's verification.

Strength check: six unit mutants (leap-year rule, the current-year bound, IPTC `00`, month precision dropping the day, the prefilter keeping core's row, date-after-info in the caption) and one JS mutant (days in month fixed at 31). Each was killed by exactly the tests named for it and then reverted.

## Verification

* [x] Saving `1965`, `März 1965` and `14. März 1965` shows exactly that text on the page after a reload **Note:** `e2e/date-edit.spec.js` [HAPPY] ×3 (`toHaveText`); `SetDateTest::testEachPrecisionGoesIntoRowAndFile`
* [x] exiftool reads `XMP-photoshop:DateCreated` as `1965`, `1965-03`, `1965-03-14`, `IPTC:DateCreated` with `00` for unknown parts, the EDTF tag, and the date in the caption **Note:** `SetDateTest::testEachPrecisionGoesIntoRowAndFile` (plain exiftool, XMP from the raw packet), `::testTheDateLeadsTheCaptionAheadOfInfoAndProvenance`, `::testAProvenanceWriteBackKeepsTheDateFirst`
* [x] `DateTimeOriginal` in the file is unchanged after every save **Note:** `SetDateTest` writes a scan date into the copy first and asserts it after each precision and after clearing; `BuildArgfileTest` asserts no argfile line names it
* [x] The calendar view lists the photo under the start date **Note:** `SetDateTest::testTheCalendarListsThePhotoUnderTheStartDate` (guest list for 1965-03-01 has it, 1965-03-02 does not)
* [x] The day dropdown is disabled until a month is chosen and offers 29 days for February 1964 and 28 for February 1965 **Note:** `e2e/date-edit.spec.js` [ST] dependent enabling, [BVA] 29/28/30/31 days, [ST] 29 February dropped when the year changes
* [x] `1799`, a future year and 31 April are refused, by the UI and by the WS method **Note:** WS: `SetDateTest::testAnImpossibleDateIsRefused`, `DateTest::testRefusedInputs`; UI: `date-edit.spec.js` [NEG] 1799 and next year refused with no request sent; the UI cannot offer 31 April at all (30 options, [BVA])
* [x] Core's "Erstellt am" row no longer appears **Note:** `PicturePageSourceTest::testTheDateShowsInTheDatumRow` (all four accounts), `::testNoDateNoRowForAReader`, `PicturePrefilterTest`, `date-edit.spec.js`
* [x] photoinfo and provenance suites pass twice in a row **Note:** 2026-10-08, after the review fixes, on a quiet host (load ~4-9): provenance integration 189 OK (4 skips) twice, including `WriteBackTest::testConcurrentWritersNeverDestroyTheFile`; photoinfo PHPUnit 129 OK twice (second run `--order-by=reverse`); photoinfo E2E 20 passed twice. Provenance unit 206 OK twice on 2026-10-07 (no provenance code changed since). The earlier failures of the concurrency test came from a host load average of ~199 (a system `installer` and the Cisco endpoint scanner) and did not recur
