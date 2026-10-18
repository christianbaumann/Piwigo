---
id: 49
dependencies:
- 16
---

# Task 49: Remove the test persons from photos 100, 103, 104, 105

Added 2026-10-18 (user's answer a, by default). The four real scans `galleries/Verschiedenes/Verschiedenes{005_0,007_1,007_2,009_0}.png` hold test-fixture person regions and keywords written on 2026-10-16 (`D'Arcy "R" & Töchter` 339, `O'Brien "Q" & Söhne` 340, `Fremd Person` 150, `Nach Fehler` 158; tags 28541, 28542, 28544, 28545). The committed versions (`af90ff316`) carry none of it; the pixels are identical.

## References

* Task 16 (repair note), `.agent-tests/2026-10-16-task16-repair/`
* decision 0020 (the file is the source of truth for regions)

## Work

* [ ] Script (git-ignored, under `.agent-tests/`): `git checkout` the four files, `pwg.persons.rescan` 100,103,104,105, then delete the four persons and their tags only if no other photo has a region or link to them; prints before/after
* [ ] Dry run on copies: prove on throwaway copies of the four files (in a fixture album) that the sequence leaves no region, no person tag and the pixels unchanged
* [ ] The user runs the script (auto mode refuses rewriting real photos)

## Verification

* [ ] After the run: `git status` clean for the four files; no region and no tag of the four persons on any photo; the four persons gone; persons `IndexRebuildTest` passes

## WIP (paused 2026-10-18)

* [x] Investigation (read-only, nothing modified, no fixture created, no script run):
  * Persons 339, 340, 150, 158 have regions only on photos 100/103/104/105 (7 regions in the whole index, all theirs: 339 on 100 twice, 103, 104; 340 on 105; 150 on 105; 158 on 104). Tags 28544 (photos 100, 103, 104), 28541 (105), 28542 (105), 28545 (104) link only to those photos. Nothing else to keep.
  * Scan of all 106 images under `galleries/` and `upload/` (exiftool: RegionName, Subject, HierarchicalSubject, PersonInImage, TagsWritten): only the four Verschiedenes files carry regions/person names. `upload/2026/10/15/20261015132903-22371e04.jpg` has genuine album-style keywords (Arbeiten, Gewerbe, ...), no test names; not polluted. No further polluted photos.
  * Files also carry `XMP-pwginfo:TagsWritten=1` and IPTC keywords; `git checkout` of the committed versions removes all of it (committed versions have none).
* [-] Script `.agent-tests/2026-10-18-task49-cleanup/cleanup.php` (git-ignored): NOT written yet. Next step: model on `.agent-tests/2026-10-16-task16-repair/repair.php` (WsClient + persons-test.env login), with `--target=real|fixture`; backup files and DB rows (persons, person_region, tags, image_tag) first; checkout HEAD bytes; `pwg.persons.rescan`; delete each person only if no region left, tag only if no image_tag link; before/after print; non-zero on surprise.
* [-] Dry run on fixture copies: not started (no fixture exists, nothing to restore).
* [ ] User run + verification: open.
