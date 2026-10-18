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

* [x] Script `.agent-tests/2026-10-18-task49-cleanup/cleanup.php` (git-ignored), `--target=real|fixture`. Backs up the files and the persons/region/tag/image_tag rows to `backup-<target>-<time>/`, writes each photo's committed bytes (`git show HEAD:<path>`, not `git checkout`), runs `pwg.persons.rescan`, deletes each person (339, 340, 150, 158; name checked against the id first) with `pwg.persons.delete` only if it has no region and its tag no link left, prints before/after, exits 1 on any surprise. **Note:** the final checks also demand: file bytes equal the committed ones (catches a photoinfo keyword rewrite), no persons metadata in the file, pixel signature unchanged, no `_original` sidecar newly created, persons/regions/tags/links of everything else unchanged (fingerprint).
* [x] Dry run on copies. **Note:** `--target=fixture` copies the four polluted real files (read only), renames the persons in the copies to `Task49 Fixture ...`, registers them in an album of their own under `upload/task49-fixture/`, indexes them (4 persons, 7 regions, 4 tags, 6 links: the same shape as the real pollution), then runs the identical steps. Result 2026-10-18: after the run no region, person, tag or link; files equal the committed bytes, no keywords, no marker; pixels equal; the real 339/340/150/158, their regions/links and the four real files untouched (sha1 compared before/after); teardown left no album, row, file or sidecar (checked by SQL and `ddev exec find`). Negative check: with the restore step disabled the script exits 1 (`differs from the committed bytes right after restore`).
* [x] The user runs the script against the real photos (auto mode refuses rewriting real photos):
  `! ddev exec flock /tmp/pwg-suite.lock php .agent-tests/2026-10-18-task49-cleanup/cleanup.php --target=real`
  It reads the logins from `local/config/persons-test.env` itself. Exit 0 and the last line `OK (real)` mean done; a non-zero exit names the surprise and leaves the backup under `.agent-tests/2026-10-18-task49-cleanup/backup-real-*/`.

## Verification

* [x] After the run: `git status` clean for the four files (the script writes the committed bytes, so git sees no change); no region and no tag of the four persons on any photo; the four persons gone; persons `IndexRebuildTest` passes
  **Note:** run by the user 2026-10-18 14:15, exit 0, `OK (real): every check held` (after: photos 100/103/104/105 no regions, 0 keywords, no pwginfo; persons 0, regions 0, tags 0, links 0 of the four). Host sha1 = container = `git show HEAD:` for all four; `git status` clean. `IndexRebuildTest` OK (1 test, 35 assertions) under the suite lock. Backup: `.agent-tests/2026-10-18-task49-cleanup/backup-real-20261018-141520/`.

## Notes

* Investigation (2026-10-18, read-only): persons 339, 340, 150, 158 have regions only on photos 100/103/104/105 (7 regions: 339 on 100 twice, 103, 104; 340 and 150 on 105; 158 on 104). Tags 28544 (100, 103, 104), 28541 and 28542 (105), 28545 (104) link only to those photos. A scan of all 106 images under `galleries/` and `upload/` found regions/person names only in these four files. Their committed versions (`af90ff316`) carry none and the pixels are identical.
* `pwg.persons.rescan` of a clean file removes the persons' tag links by itself (`persons_sync_image_tags()`), so `pwg.persons.delete` then rewrites no file.
* Independent review 2026-10-18: no path lets the fixture run write or delete a real object (`write_file()` refuses non-fixture paths in fixture mode and non-listed files in real mode; teardown deletes only by `Task49 Fixture ` name prefix and `upload/task49-fixture/` path). One defect fixed: teardown looked tags up by an `IN ('-')` fallback when no person had been indexed (would hit a tag named `-`) and left rows behind if the build failed midway; it now selects fixture rows by prefix and path. Re-ran `--target=fixture`: OK, no leftover by SQL or `ddev exec find`; real files, persons, regions and links unchanged. DB check: photos 100/103/104/105 carry only the four pollution tags, so the "no image_tag rows remain" check is right for the real run.
