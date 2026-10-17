---
id: 34
dependencies: []
---

# Task 34: IPTC carries its character set

Umlauts and the en dash read correctly in every IPTC reader.

## References

* `design.md#iptc-carries-its-character-set`

## Work

* [x] Add `-IPTC:CodedCharacterSet=UTF8` in `photoinfo_build_argfile`, `photoinfo_build_date_argfile`, `photoinfo_build_tags_argfile`, `provenance_build_argfile`

## Verification

* [x] Unit: each argfile holds the line
  **Note:** Verified via `BuildArgfileTest` (provenance, photoinfo) and photoinfo `BuildTagsArgfileTest`; provenance unit 301 OK, photoinfo unit 288 OK.
* [x] Integration: an independent reader reads `Ä` and `–` correctly from caption and keywords
  **Note:** Verified via `WriteBackTest::testIptcDeclaresUtf8ForAReaderThatTrustsTheFile` and `TagWriteTest::testIptcKeywordsDeclareUtf8ForAReaderThatTrustsTheFile`; the provenance test failed first with `Ã„nne â€“ MÃ¼ller` (mojibake). See Deviations for the reader.
* [x] provenance and photoinfo suites pass
  **Note:** Verified via the unit and integration suites, run under `flock /tmp/pwg-suite.lock`: provenance integration 371 tests OK (3 skipped, as before), photoinfo integration 220 tests OK.

## Deviations

* The four builders already emitted `-charset iptc=UTF8`; the task adds the missing `-IPTC:CodedCharacterSet=UTF8`. One helper, `provenance_iptc_charset_lines()`, holds both lines; the four builders call it (photoinfo requires provenance).
* ImageMagick cannot be the reader: `identify` shows `Ä – x` with and without the marker, since it never decodes IPTC text. The reader is exiftool with no `-charset` option (it follows the file: without the marker it reads Latin-1 and prints mojibake), plus exiv2, which shows the `Iptc.Envelope.CharacterSet` field (`%G`). Both are checked.
* The integration tests cover the provenance caption and the photoinfo keywords; the photoinfo caption and date builders share the helper and are covered by unit tests only.
