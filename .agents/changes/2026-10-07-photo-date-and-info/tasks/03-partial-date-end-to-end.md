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

* [ ] Add `photoinfo_date_precision` (and the qualifier/end columns from the date model, unused until task 04) in `maintain.class.php`; drop them on uninstall
* [ ] Pure functions in a file with no Piwigo dependency: validate year/month/day (1800 to the current year, days per month, leap years), start date for `date_creation`, German display text, EDTF string, `XMP-photoshop:DateCreated` and `IPTC:DateCreated` values
* [ ] `pwg.photoinfo.setDate` (`admin_only`, `post_only`, `pwg_token`): saves `date_creation` and the precision, then writes `DateCreated` (XMP, IPTC), `XMP-pwginfo:DateEDTF` and the caption (readable date part) under provenance's lock; never `DateTimeOriginal`
* [ ] Picture page: the Datum row replaces core's "Erstellt am" row; click-to-edit with year field, month and day dropdowns, dependent enabling; empty clears the date
* [ ] Tests: unit for every pure function (boundaries: 1799/1800, current year/next year, 28/29/30/31 days, leap years 1900/2000), integration for WS and file tags, E2E for the controls and save

## Verification

* [ ] Saving `1965`, `März 1965` and `14. März 1965` shows exactly that text on the page after a reload
* [ ] exiftool reads `XMP-photoshop:DateCreated` as `1965`, `1965-03`, `1965-03-14`, `IPTC:DateCreated` with `00` for unknown parts, the EDTF tag, and the date in the caption
* [ ] `DateTimeOriginal` in the file is unchanged after every save
* [ ] The calendar view lists the photo under the start date
* [ ] The day dropdown is disabled until a month is chosen and offers 29 days for February 1964 and 28 for February 1965
* [ ] `1799`, a future year and 31 April are refused, by the UI and by the WS method
* [ ] Core's "Erstellt am" row no longer appears
* [ ] photoinfo and provenance suites pass twice in a row
