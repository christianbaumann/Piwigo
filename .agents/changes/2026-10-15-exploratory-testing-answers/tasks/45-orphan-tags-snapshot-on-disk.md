---
id: 45
dependencies:
- 26
---

# Task 45: `OrphanTagsTest` keeps its snapshot on disk

Added 2026-10-17 from task 26's verification (user's answer a). The cleanup is gallery-wide; a run killed between `setUp` and `tearDown` loses the install's real unused tags.

## References

* Task 26, `plugins/galleryrules/tests/Integration/OrphanTagsTest.php`
* persons' `IndexRebuildTest` (snapshot kept and put back)

## Work

* [x] `setUp` writes the unused-tag snapshot to a git-ignored file before any cleanup runs; `setUp` first restores any snapshot a killed run left; `tearDown` restores and removes the file
  **Note:** New `OrphanTagSnapshot` (`tests/Support`, bootstrapped) writes `_data/orphan-tags-snapshot.json` (covered by the root `_data` ignore) in `take()`, after restoring a leftover; `restore()` puts the rows back and deletes the file. Failing test written first: red with `Class "OrphanTagSnapshot" not found`.
* [x] Same for provenance's `CoreOrphanTagsCharacterizationTest` if it sweeps real tags
  **Note:** It does (same global cleanup); same helper, same case.

## Verification

* [x] Integration: a run killed after the sweep (simulated: snapshot file left, tags deleted) gets its tags back at the next `setUp`
  **Note:** `testALeftoverSnapshotIsRestoredAtTheNextSetUp` in both suites (take, delete the tag, take again from a fresh instance).
* [x] galleryrules and provenance suites pass twice and in reverse order
  **Note:** 2026-10-17, under the suite lock: galleryrules integration OK (83) twice and with `--order-by=reverse`; provenance integration `--filter CoreOrphanTags` OK (6) twice. The install's two real unused tags (ids 13835, 28562) were identical after every run; no snapshot file left.
* [x] The snapshot never keeps the suites' own fixture tags (`<plain|coloured|person|leftover|used|young|old|listed>-<6 hex>`), which a killed run leaves unused
  **Note:** 2026-10-18 verify: `OrphanTagSnapshot::isFixtureName()`; `testFixtureTagsAreNotKept` in both suites, `testFixtureNamesAreRecognisedByPrefixAndSuffix` unit. Inverting the filter turns both integration cases red. A tag the user deleted on purpose after a killed run is still re-created (accepted: the file cannot tell it from a swept one). `OrphanTagSnapshotCopyTest` guards the two copies as identical.

## Deviations

* The helper exists twice (galleryrules and provenance `tests/Support`): plugins share no code. The file path is the same on purpose, so either suite restores the other's leftover.
