---
date: 2026-10-11
git_commit: eb68a6c36
branch: master
topic: "Why persons test runs appeared to leave albums, photo rows and files behind"
tags: [research, persons, tests, e2e, fixtures, mutagen]
status: implemented 2026-10-11
---

# persons test leak

Task 07 of change `2026-10-11-backlog-batch`. `docs/backlog.md` recorded two observations:
five `Persons E2E <hex>` albums with their `persons-test-*` photo rows left by interrupted
E2E runs (2026-08-31), and one `upload/persons-test/*.png` plus its `_original`, with no row,
after a **green** integration run.

## Method

MariaDB `general_log` on (`SET GLOBAL general_log_file='/tmp/persons-leak.log'`, in the db
container), `upload/`, `galleries/` and `_data/persons/` listed and the persons-test rows counted
before and after one persons integration run. Log off again afterwards.

## Finding 1: the green integration run leaks nothing; the measurement did

First run, listing taken **on the host** right after the suite returned: one
`persons-test-<hex>.png`, its `.png_original` and one `_data/persons/locks/*.lock` were new, no
image row. Exactly the 2026-08-31 shape.

The log names the creator: `WriteRegionsTest::testConcurrentWritersEachLandTheirOwnFace` (the
`Persons Write Racer` names), image 37285, inserted at 19:25:16. Its teardown, on the same
connection, deletes row 37285 at 19:25:28 and `destroyTestImages()` unlinks the file, the sidecar
and the lock. A minute later all three were gone on the host as well as in the container.

The host listing raced Mutagen: the race test is the suite's last, and its deletions had not yet
reached the host when `find` ran. A second run listed **inside the container**
(`ddev exec find ...`) and on the host only after `ddev mutagen sync`: no difference in either
(105 photos, 5 albums before and after).

So there is no integration leak to fix. A file listing taken to check for one has to come from
the container, or from the host after `ddev mutagen sync`, the same rule
`.claude/rules/mutation-testing.md` sets for the opposite direction.

## Finding 2: `seed.php` loses track of what it created, two ways

Read from `plugins/persons/tests/e2e/support/seed.php`, then reproduced by
`tests/Integration/SeedRestoreTest`:

1. **A seed overwrote an unrestored snapshot.** `save_snapshot()` wrote the new seed's objects over
   whatever `tests/e2e/.state/snapshot.json` held. A spec killed before its `afterEach` leaves a
   snapshot; the next run's first seed replaced it, and the killed run's album and photo were then
   named nowhere. One orphan album per interrupted run: the five of 2026-08-31.
   Reproduced by `testOneRestoreRemovesTwoSeedsInARow` (two seeds, one `--restore`, one album,
   row and file left).
2. **A seed that failed after creating saved no snapshot.** The only `save_snapshot()` call was the
   last statement. Every `fail()` or exception between `createTestImage()` and it (reindex failed,
   region count, rotation not taken, exiftool write failed) exited with the photo and album in
   place and nothing recording them; `--restore` answered `no snapshot`. Only `seed_neighbours()`
   cleaned up its own failures. Reproduced by `testRestoreRemovesWhatAFailedSeedCreated`: a
   `marked` seed (GD-drawn, so it needs no exiftool to create) with no exiftool on `PATH` fails in
   `writeRegionsWithExiftool()`.

## Fix

- `save_test_objects()` writes the snapshot as soon as the builder owns something: after the
  photo, after the album, after each neighbour. The snapshot is written aside and renamed, since it
  is now written several times per seed and a truncated one makes every later seed refuse. `seed_neighbours()` no longer needs its own
  cleanup and lost it.
- A seed that finds a snapshot imports its objects first, so the snapshot it writes still names the
  earlier seed's photo and album and one `--restore` removes both.

No sweep by name in `--restore`: everything removed is something a snapshot recorded.

Mutants, each run against `SeedRestoreTest` after the container's checksum matched the host's:

| Mutant | Expected killer | Result |
|---|---|---|
| carry-forward removed (`importTestObjects($leftover...)` not called) | `testOneRestoreRemovesTwoSeedsInARow` | killed, nothing else moved |
| early `save_test_objects()` after photo and album removed | `testRestoreRemovesWhatAFailedSeedCreated` | killed, nothing else moved |

## Not fixed

`FixtureBuilder::createTestImage()` copies the file before `stripCopiedMetadata()` and before
the row exists. If the strip throws, the copy stays with no row and no snapshot entry. No run has
been seen doing that, so it stays as it is. The same holds for `createTestAlbum()`: it inserts the
row and then checks `uppercats`, and a failed check throws before the id is recorded.
