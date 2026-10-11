---
id: 07
dependencies: []
---

# Task 07: persons test runs leave nothing behind

A green persons integration run and `tests/e2e/support/seed.php --restore` leave no album, image row or file under `upload/persons-test/` behind, and a test proves it.

## References

* `design.md#persons-leak-diagnosis-before-fix`
* `docs/backlog.md` (the persons `--restore` leak item)
* `plugins/persons/tests/Support/FixtureBuilder.php`
* `plugins/persons/tests/e2e/support/seed.php`
* `.claude/rules/plugin-test-suites.md` (persons section)

## Work

* [x] Research note `docs/agents/research/2026-10-11-persons-test-leak.md`: turn on MariaDB `general_log`, list `upload/persons-test/` and the persons-test rows before and after one green integration run and one E2E seed/restore cycle, name the test and code path that leave the file
  **Note:** The green-run "leak" (png + `_original` + lock from `WriteRegionsTest::testConcurrentWritersEachLandTheirOwnFace`) was a host listing taken before Mutagen synced the teardown's deletes; container-side listing shows none. The real leaks are in `seed.php`: a seed overwrote an unrestored snapshot, and a seed failing after creation saved none.
* [x] Write the reproducing test at the lowest layer that shows the leak; watch it fail
  **Note:** `plugins/persons/tests/Integration/SeedRestoreTest.php` (runs `seed.php` as its own process). Both cases red before the fix, each with one orphan album, row and file.
* [x] Fix the cause found; no catch-all sweep in `--restore`
  **Note:** `save_test_objects()` right after each creation, and carry-forward of an unrestored snapshot. `seed_neighbours()` lost its now-redundant own cleanup. After review: the snapshot is written atomically, the test's `tearDown` restores only what it seeded, and test 1 gained an anti-vacuity guard. Two `FixtureBuilder` gaps left unfixed are named in the research note.
* [x] Turn `general_log` off again; remove the backlog item
  **Note:** `SHOW VARIABLES LIKE 'general_log'` → `OFF`. Also updated `.claude/rules/plugin-test-suites.md`.

## Verification

* [x] After a green integration run, `upload/persons-test/` holds the same files as before and no `persons-test-*` image row was added
  **Note:** Verified by listing `upload/`, `galleries/` and `_data/persons/` inside the container before and after two integration runs and two E2E runs: no difference; 105 photos and 5 albums before and after.
* [x] After a seed/restore cycle, no `Persons E2E` album remains
  **Note:** Verified via `SeedRestoreTest` (including two seeds without a restore in between, and a seed that fails halfway) and by 0 `Persons E2E%` albums after two full E2E runs.
* [x] Reverting the fix turns the reproducing test red
  **Note:** Two mutants, applied after host/container md5 matched: carry-forward removed → only `testOneRestoreRemovesTwoSeedsInARow` red; early saves removed → only `testRestoreRemovesWhatAFailedSeedCreated` red. Table in the research note.
* [x] persons integration and E2E suites pass twice in a row
  **Note:** Integration 115 tests (1 skip, already skipped before this change) twice; E2E 154 passed twice (9.9m, 9.8m), 2026-10-11.
