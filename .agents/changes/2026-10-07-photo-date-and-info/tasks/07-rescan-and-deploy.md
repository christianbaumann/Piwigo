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

* [ ] Pure parser: EDTF string back to qualifier, precision, start and end; invalid strings rejected
* [ ] `pwg.photoinfo.rescan` (`admin_only`, `post_only`, `pwg_token`, chunked like `pwg.persons.rescan`): reads `XMP-pwginfo:DateEDTF` and `XMP-pwginfo:Info`; writes the database only, never the file
* [ ] A file without the tags leaves the photo's values untouched
* [ ] Add `photoinfo` to `PLUGINS_TO_ACTIVATE` after `provenance`; update the deploy tests
* [ ] Tests: parser round-trip with the task 04 formatter (unit); rescan restores values after they are cleared in the database (integration); deploy tool's pytest

## Verification

* [ ] Every EDTF form from the design's table parses back to the values that produced it
* [ ] The rescan reads `XMP-pwginfo:DateEDTF` with `plugins/photoinfo/exiftool/pwginfo.config`: without it exiftool reads `1965/1970` as a fraction (task 04's notes). An EDTF `1965/` (open end) is not a range the editor can save; decide and record what the rescan makes of it. Note that `../1965` is inclusive in EDTF while `vor` means strictly before; the design's table fixes `../1965`
* [ ] After clearing a photo's date and info in the database, a rescan restores both
* [ ] A rescan changes no image file (checksum before and after)
* [ ] `uv run pwg-deploy --dry-run` lists photoinfo for activation; `uv run pytest` in `tools/deploy` passes
* [ ] photoinfo suites pass twice in a row
