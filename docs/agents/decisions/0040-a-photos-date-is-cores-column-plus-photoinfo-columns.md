# 0040 — A photo's date is core's `date_creation` plus four photoinfo columns, and EDTF in the file

Date: 2026-10-08
Status: accepted

## Context

Most photos in this install are recovered family scans whose exact day nobody knows. A date has to
say `1965`, `März 1965`, `ca. 1965`, `vor 1965`, `nach März 1965` or `1965–1970`, and core's
`images.date_creation` is a `DATETIME`. The calendar, sorting and date search all read that column
(`.agents/changes/2026-10-07-photo-date-and-info/design.md`, "Date model").

## Decision

- `date_creation` keeps the **start** date, unknown month or day set to `01`. Core's views keep
  working unchanged.
- `plugins/photoinfo` adds `photoinfo_date_qualifier` (`circa`, `before`, `after`, `between`; NULL =
  exact), `photoinfo_date_precision` (`year`, `month`, `day`), `photoinfo_date_end` and
  `photoinfo_date_end_precision` (only with `between`). A `date_creation` with NULL precision reads
  as an exact day, which is what a camera date or a date from core's screens is.
- One select `— | ca. | vor | nach | zwischen`; `ca.` never combines with a range. Years 1800 to the
  current year; a range end not before its start, compared at the coarser precision.
- In the file: `XMP-photoshop:DateCreated` and `IPTC:DateCreated` carry the start at its precision;
  `XMP-pwginfo:DateEDTF` carries the whole date as EDTF (`1965~`, `../1965`, `1965-03/..`,
  `1965/1970`); the readable German text leads the caption (decision 0042).
- photoinfo never writes EXIF `DateTimeOriginal` (decision 0041).

## Consequences

- Core screens and the calendar show `1965` as 1 January 1965.
- Standard photo programs see only the start date; the qualifier and end exist in the database and
  in photoinfo's own XMP tag.
- exiftool reads `XMP-pwginfo:DateEDTF` correctly only with `plugins/photoinfo/exiftool/pwginfo.config`;
  without it, `1965/1970` comes back as the fraction `0.997…` (exiftool 13.25, measured 2026-10-08).
- The rescan reads back only the five EDTF forms photoinfo writes; any other string is reported,
  never guessed (decision 0043).
