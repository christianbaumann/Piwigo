# 0067 — Test fixtures reset the album id counter

Date: 2026-10-12
Status: accepted

## Context

Every plugin suite creates throwaway albums and deletes them again. Deleting a row does not lower
`piwigo_categories`' AUTO_INCREMENT, so the parallel role-matrix runs of 2026-10-12 pushed it past
32767 (33271 measured). Album ids are `smallint(5) unsigned`, but core's
`piwigo_history.category_id` is a **signed** `smallint(5)` (`install/piwigo_structure-mysql.sql:148`).
From then on every page view of a newer album died in `pwg_log()` with "Out of range value for
column 'category_id'", and rows of unrelated tests went red. A hand reset fixed it; the counter was
back at 7364 the same evening.

## Decision

- Every plugin's `FixtureBuilder` runs `ALTER TABLE piwigo_categories AUTO_INCREMENT = 1` after it
  deletes its albums (`resetAlbumIdCounter()`): `destroyTestAlbums()` in provenance, persons,
  photoedit and photoinfo, `destroyPhysicalAlbums()` in provenance, `restore()` in typetags.
  MariaDB clamps the value to max(id) + 1, so no live album is affected.
- Core's column is not widened. It is upstream schema, and only the suites ever create albums at
  that rate.

## Consequences

- Album ids are reused across runs. Nothing in the suites keys on an id across runs; a fixture
  album is found through the id its builder recorded in the same run.
- A run killed before its cleanup still leaves the counter raised; the next completed run lowers it.
- `ALTER TABLE` is DDL and commits implicitly. No suite wraps a fixture in a transaction.
- If the error comes back, the fix is the same statement by hand, under `/tmp/pwg-suite.lock`.
