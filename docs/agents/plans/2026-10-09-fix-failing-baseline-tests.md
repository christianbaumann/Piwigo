---
date: 2026-10-09T10:30:00+02:00
git_commit: a7c7b2488897ab7b7778b6a8d0d8d6095c5ba572
branch: master
topic: "Fix the persons tests that fail on the current gallery"
tags: [plan, persons, photoedit, tests, fixtures]
status: done
---

# Fix the Failing Baseline Tests

Part of [2026-10-09-tags-in-file-and-new-tag-groups.md](2026-10-09-tags-in-file-and-new-tag-groups.md)
(its Phase 8). Done **before** that plan's Phase 5, and Phase 4 is committed only once this is green
(owner, 2026-10-09: every persons run touched a real person and could rewrite real scans).

## What fails

The persons integration suite: 20 of 112 tests, 2026-10-09. 6 of them were confirmed red **without**
the tags plan's Phase 4 code (`AddRegionTest`, `PersonAdminApiTest` run with that code stashed), so
these failures existed before Phase 4.

- `AddRegionTest`: `testAddingARegionCreatesThePersonTheRegionAndTheMirroredTag`,
  `testAddingARegionKeepsTheIdsOfThoseAlreadyThere`, `testASecondPersonDoesNotRemoveTheFirst`
- `DeleteRegionTest`: `testGetRegionsReturnsWhatWasWritten`, `testDeletingOneRegionLeavesTheOther`,
  `testARegionCanBeDeletedByTheIdItHadBeforeAnotherDelete`,
  `testRemovingOneOfTwoRegionsForTheSamePersonKeepsTheImageTagRow`, `testBadTokenIsRejected`
- `PersonAdminApiTest`: `testRenamingAPersonUpdatesTheTagTheIndexAndTheFile`,
  `testDeletingAPersonRemovesTheRegionsFromTheFileAndTheIndex`, `testRescanRebuildsTheIndexFromTheFile`
- `PhotoDeletionTest`: `testDeletingAPhotoRemovesItsRegionRows`,
  `testDeletingOnePhotoLeavesAnotherPhotosRegionsAlone`
- `ReindexTest`: `testAFileWithNoRegionsYieldsNoRows`
- `WriteRegionsTest`: `testAnIndependentReaderFindsTheWrittenRegion`, `testWritingASecondPersonKeepsTheFirst`,
  `testTheIndexMatchesTheFileAfterAWrite`, `testRemovingARegionTakesItOutOfTheFile`,
  `testRemovingTheLastRegionLeavesNoTagsBehind`, `testConcurrentWritersEachLandTheirOwnFace`

## Cause

The fixtures copy the gallery's first PNG (`persons/tests/Support/FixtureBuilder.php`, `ORDER BY …
LIMIT 1`; photoinfo's and photoedit's builders pick the same photo). Since 2026-10-09 08:35 that
photo, `galleries/1992_Rund_um_Sefferweich/1992_Rund_um_Sefferweich_01_0.png` (id 1), carries a
real region, "Willy Weinandy" (uncommitted working-tree change). Every copy brings that face along:

- counts are one higher than the test expects (`2 is identical to 1`)
- the suites create, rename and delete the **real** person "Willy Weinandy" and change real photo
  1's `image_tag` rows; a person delete removes the mirrored tag from every photo carrying it
- with the tags plan's Phase 4, such a change also writes the real photo's file. That happened
  once, 2026-10-09 09:32:52, while the persons suite ran: photo 1's file now holds
  `XMP-pwginfo:TagsWritten=1` and no keywords, while the database holds `Personen` and
  `Willy Weinandy`. A deploy with `--prune-tags` would remove both tags on the remote.

## Tasks

- [x] Photo 1 restored (owner, 2026-10-09): the 08:35 region and the 09:32 write discarded - kept in
  `git stash` as "owner's 08:35 region on photo 1 + phase-4 test write" - and its persons index
  rebuilt from the committed file with `pwg.persons.rescan` (region and `Willy Weinandy` tag gone,
  `Personen` kept, file unchanged). The person row "Willy Weinandy" is left, with no regions
- [x] persons `FixtureBuilder`: every copy is stripped of regions, `PersonInImage`, keywords and the
  marker, and asserts none are left before the test runs (photoinfo's
  `FixtureBuilder::stripRegionsAndKeywords()` is the shape; `-config` must come first)
- [x] Same for photoedit's `FixtureBuilder` (its suite passes today, but a crop of the copied photo
  now moves a real face)
- [x] Photo 1 written once more, 2026-10-09 10:39, by the **typetags** suites: their
  `FixtureBuilder::anyImageId()` and the E2E `seed()` (default `imageId = 1`) used the real first
  photo. Restored to HEAD each time. Now `testImageId()` makes a throwaway copy in an album of its
  own, removed by `restore()` and carried across the E2E seed's processes; `seed()` passes
  `--image` only when a spec names one, and `seed.php` reuses an earlier seed's copy. A spec run
  one file at a time found the four that wrote (assign, edge-cases, german-tooltips, remove).
  Typetags integration (reverse order) and E2E (40) green, photo 1 untouched. Submodule commit;
  the owner pushes it
- [x] The person row "Willy Weinandy" (id 164, created by the discarded 08:35 change, no regions,
  its tag on no photo) deleted with `pwg.persons.delete`: `IndexRebuildTest` expects every person
  row to come back from the files, and this one could not
- [x] Re-run persons, photoedit and photoinfo integration suites twice and in reverse order
  (2026-10-09: persons 112 with 1 skip, photoedit 82, photoinfo 143, each green three times;
  provenance 209 with 4 skips; the skips are the suites' own)
- [x] Check no suite touched a file under `galleries/` (mtimes of every file before and after the
  re-run above: unchanged)

- [x] photoedit: after `photoedit_end`, refresh the row's `md5sum` and the photo's version, since
  persons (regions) and photoinfo (keywords) may write the file in `photoedit_end`, after
  `photoedit_write()` stored both (owner, 2026-10-09). Test-first: a crop that cuts a face leaves
  `md5sum` and version equal to the file's
- [x] persons' web-service answers (addRegion, deleteRegion, rename, delete) carry `tags_written` and
  `tags_message` when a listener wrote files (owner, 2026-10-09, instead of logging only).
  `persons_tags_changed` becomes a `trigger_change` whose listeners return their write results, so
  persons stays unaware of photoinfo

## Review findings (2026-10-09)

Fixed, test-first: a rename to the name a person already has (`"Anna "` for `"Anna"`) crashed
`pwg.persons.rename` with a `TypeError` - the early return carried no `tag_writes`
(`PersonAdminApiTest::testRenamingToTheSameNameChangesNothing`). Also: photoedit gives its lock back
in a `finally` of its own around `photoedit_end` and the refresh, and photoinfo's persons listener
turns an exception into a failed write instead of a 500 after the regions were saved.

Known, not fixed (test support, low risk):
- typetags `seed.php`: a scenario failing after `testImageId()` and before `save_snapshot()` leaks
  the copy and its album; `--restore` then finds no snapshot
- typetags `FixtureBuilder::testImageId()` does not check `getimagesize()`; `restore()` leaves the
  album's `user_cache_categories` rows until the next cache rebuild
- the strip helper exists four times (persons, photoedit, typetags, photoinfo): each plugin's
  suite stands on its own
- provenance's and photoinfo's `createTestImage()` still copy the first PNG unstripped; neither
  suite drives persons on the copy (photoinfo's `TagWriteTest` strips its own)
- typetags `MalformedColorRenderingTest` fetches `/picture.php?/1/category/1`: read-only, but it
  assumes photo 1 is in album 1
- a person rename or delete notifies photos whose region write failed: their keywords get the new
  name while the file's regions keep the old one, until the next rescan

