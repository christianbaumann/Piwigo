---
id: 07
dependencies:
- 04
---

# Task 07: Rebuild from files, and activate on the remote

`pwg.photoinfo.rescan` restores every photo's date and info text from its file, and a deploy
activates photoinfo, so the remote shows both fields although its database is never transferred.

## References

* `design.md#the-file-carries-photoinfos-values-separately-and-a-rescan-rebuilds-them`
* `design.md#photoinfo-is-activated-on-the-remote`
* `docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md`
* `docs/agents/decisions/0023-no-database-transfer-to-the-remote.md`
* `plugins/persons/` (`pwg.persons.rescan`, `IndexRebuildTest`)
* `tools/deploy/pwgdeploy/bootstrap.py:42` (`PLUGINS_TO_ACTIVATE`), `tools/deploy/tests/`
* `.claude/rules/deployment.md`

## Work

* [x] Pure parser: EDTF string back to qualifier, precision, start and end; invalid strings rejected
* [x] `pwg.photoinfo.rescan` (`admin_only`, `post_only`, `pwg_token`, chunked like `pwg.persons.rescan`): reads `XMP-pwginfo:DateEDTF` and `XMP-pwginfo:Info`; writes the database only, never the file
* [x] A file without the tags leaves the photo's values untouched
* [x] Add `photoinfo` to `PLUGINS_TO_ACTIVATE` after `provenance`; update the deploy tests
* [x] Tests: parser round-trip with the task 04 formatter (unit); rescan restores values after they are cleared in the database (integration); deploy tool's pytest

## Verification

* [x] Every EDTF form from the design's table parses back to the values that produced it **Note:** `tests/Unit/RescanParseTest::testEveryEdtfTheFormWritesParsesBack` runs over `DatingTest::combinations()` (16 rows, `DataProviderExternal`), so the parser and the formatter share one table
* [x] The rescan reads `XMP-pwginfo:DateEDTF` with `plugins/photoinfo/exiftool/pwginfo.config`: without it exiftool reads `1965/1970` as a fraction (task 04's notes). An EDTF `1965/` (open end) is not a range the editor can save; decide and record what the rescan makes of it. Note that `../1965` is inclusive in EDTF while `vor` means strictly before; the design's table fixes `../1965` **Note:** `-config` is passed in `photoinfo_read_file_tags()`; removing it fails `RescanTest`'s `range of years` case (mutant run 2026-10-08). **Decision:** the parser reads only the five forms `photoinfo_dating_edtf()` writes. `1965/`, `/1965`, `1965?`, `1965%`, `../..`, `196X`, `1965~/1970` are refused: the photo is reported in `failed` with the string, its date columns stay as they are, and its info text is still restored (`RescanParseTest::testRefusedEdtf`, `RescanTest::testAnUnreadableDateIsReportedAndLeftAlone`). `../X` reads back as `vor X`, the inverse of the writer, per the design's table
* [x] After clearing a photo's date and info in the database, a rescan restores both **Note:** `RescanTest::testARescanRestoresWhatTheDatabaseLost` (4 datings incl. a multi-line info text with markup and trailing spaces; all six columns compared)
* [x] A rescan changes no image file (checksum before and after) **Note:** same test: md5 before/after, and no new `_original` sidecar
* [x] `uv run pwg-deploy --dry-run` lists photoinfo for activation; `uv run pytest` in `tools/deploy` passes **Note:** `uv run pytest`: 437 passed. **Deviation:** `--dry-run` skips the bootstrap entirely and lists no plugins, so it cannot show this. Covered instead by `test_activation_installs_all_four_fork_plugins` (from a gallery with nothing active; the fake now refuses photoinfo before provenance, as `maintain.class.php` does) and `test_photoinfo_is_activated_after_provenance`. `--list-files` publishes `plugins/photoinfo/`; `rescan.inc.php` joins it once committed (the set is `git ls-files`)
* [x] photoinfo suites pass twice in a row **Note:** unit 212, integration 116 (twice, plus once `--order-by=reverse`), E2E 30 passed twice, 2026-10-08

## Notes

* **exiftool output format:** the rescan reads `exiftool -X` (RDF/XML), not `-j`. JSON prints a number-like value unquoted, so an info text `1.50` came back as the float `1.5` (exiftool 13.25, measured 2026-10-08); `-api StructFormat=JSONQ` would fix that but is newer than the remote's 12.76. The output goes to a file under `_data/provenance/args/<op>/` because `exec()`'s output array drops each line's trailing whitespace, and `exec()` is the only shell function the remote was probed for.
* **No caller drives the rescan on the remote yet.** `pwg.photoinfo.rescan` takes one chunk of at most 10 ids per request (`PHOTOINFO_RESCAN_MAX_CHUNK`), like `pwg.persons.rescan`, which has its admin screen's button as its caller. Task 08 documents "rescan after deploy"; it needs either a caller (a button, or a step in `tools/deploy`) or a documented manual loop.
* **Mutants (unit + integration), 2026-10-08:**

| Mutant | Killed by |
|---|---|
| rescan never stores the columns | `RescanTest::testARescanRestoresWhatTheDatabaseLost`, `testAnUnknownPhotoIsReportedAndTheRestRead`, `testAnUnreadableDateIsReportedAndLeftAlone` |
| `-config pwginfo.config` dropped | `RescanTest::testARescanRestoresWhatTheDatabaseLost` (range of years) |
| `admin_only` dropped | `RescanTest::testOthersAreRefused` |
| `../X` parsed as `after` | `RescanParseTest::testEveryEdtfTheFormWritesParsesBack` |
| a range's end year discarded | `RescanParseTest` round-trip, `testRefusedEdtf`, `testTheBoundariesThemselvesParse` |
| an info text empty after cleaning still stored | `RescanParseTest::testTheInfoTextIsCleanedLikeASave` |
| an unreadable date clears the date instead of reporting | `RescanParseTest::testAnUnreadableDateIsReportedAndTheInfoStillRestored` |
| a tag read by position instead of by namespace | `RescanParseTest` XML tests (3) |
| `trim()` on each parsed XML value | **survived**: unobservable, both consumers trim (`photoinfo_clean_info()`, `photoinfo_dating_from_edtf()`) |

## Review (2026-10-08, /verify)

A subagent reviewed the task's commit `4b7374f97`. These findings were fixed:

* **The rescan reset the time of day** in `date_creation` to `00:00:00`, even when the day already matched (a date from core's picker or a camera's EXIF). It now leaves `date_creation` alone when the stored value falls on the file's day, the same rule as `photoinfo_core_edit()`. The test was written first and failed for that reason: `RescanParseTest::testAStoredDateOnTheSameDayKeepsItsTime`, `RescanTest::testADateOnTheSameDayKeepsItsTime`.
* **A scratch directory that could not be created aborted the whole chunk.** `photoinfo_read_file_tags()` now catches the `RuntimeException` and reports it against that one photo. No test covers this: `_data/provenance/args/` cannot be made uncreatable without breaking provenance's other writers mid-run.
* **Failure branches without a test** now have one: a missing file (`testAMissingFileIsReportedAndTheRestRead`) and exiftool unavailable, through a `provenance_exiftool_path` config row (`testWithoutExiftoolEveryPhotoIsReported`). Still untested: exiftool exiting non-zero on a readable file, and exiftool output that is not XML. Neither can be provoked without a fake binary.
* The GET test asserts core's `post_only` code `405`, not just `fail`.
* The unit fixture builds its namespace from `PHOTOINFO_RDF_GROUP_URI` instead of a second copy of the URI.
* `exiftool/pwginfo.config`'s header no longer says the config is needed only for writing.
* `tools/deploy/README.md`: "four of the five fork-local plugins (photoedit stays inactive)".

Deferred to task 08: `.claude/rules/deployment.md` lines on rebuilding the remote name only `pwg.persons.rescan` (task 08: "deployment.md: rescan after deploy").

After the fixes: unit 216, integration 119, `tools/deploy` pytest 437 passed.
