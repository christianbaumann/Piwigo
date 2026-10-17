---
id: 42
dependencies:
- 35
---

# Task 42: The provenance `album-tree` seed removes every album it created

Added 2026-10-17 (user's answer). After task 35's runs the install held 20 leftover
"provenance E2E tree ..." albums (ids 6-25, no photos); `seed.php --restore` did not remove them.

## References

* Task 35 (`plugins/provenance/tests/e2e/support/seed.php` `--scenario=album-tree`, `album-properties.spec.js`, commit `7b96192a7`)
* `plugins/persons/tests/Integration/SeedRestoreTest.php` (the pattern: run the seed as its own process)
* `.claude/rules/test-design.md#fixture-provenance`

## Work

* [x] Find why the albums leaked (snapshot overwritten by a second seed, written too late, restore not reached, spec's `afterEach` order, killed runs)
* [x] Reproducing test first: seed, (interrupt / seed again), `--restore`, no "provenance E2E tree" album left
* [x] Fix the seed so a seed that finds an unrestored snapshot carries its objects forward and `--restore` removes all of them

## Verification

* [x] Integration: the reproducing test passes, `piwigo_categories` count back to its value before
  **Note:** `SeedRestoreTest` (provenance integration), 2 tests OK; count 18 before and after (5 real + 13 leftovers the user is deleting).
* [x] provenance E2E `album-properties.spec.js` passes twice in a row with no album left
  **Note:** 9 passed twice; count 18 after each run.
* [x] provenance suites pass
  **Note:** unit 307 tests OK; integration 407 tests, 2523 assertions, 3 skipped (OK). Final count 19 = 18 + another agent's `photoinfo-tags-*` album, not a provenance album.

## Cause

Not provable after the fact (the leaking runs are gone); two defects found in `seed.php`, both leave albums no `--restore` can know:

* The snapshot was written once, at the very end. A seed that died between `createTestAlbum()` and that save (id 18 held a lone "parent") left albums and no snapshot. Reproduced by an unwritable `.state/` directory: `save_snapshot()` ignored `file_put_contents() === false`, the seed exited 0 with four albums and no snapshot (the new test went red for exactly that).
* Two seeds in a row were already carried forward correctly (that test passed before the fix); a race of two concurrent provenance seeds (read-modify-write of one file) remains possible and is not covered.

## Fix

`seed.php`: `save_snapshot()` fails loudly; a snapshot is written before anything is created; a shutdown function rewrites `test_objects` however the seed ends (also an uncaught exception or `fail()`). Hand check: a copy of the seed throwing after the parent album was created left a snapshot listing it, `--restore` removed it (count 19 to 18).

## Deviations

* The mid-creation failure has no automated test (no natural way to make `createTestSubAlbum` fail without a test hook in the seed); hand-checked as above.
* Concurrent seeds (flock on the snapshot) not added: nothing shown to need it.
