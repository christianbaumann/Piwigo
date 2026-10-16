---
id: 38
dependencies:
  - 16
---

# Task 38: typetags and persons integration suites pass again

The two integration failures seen while verifying task 06 (2026-10-15) are fixed, and the suites pass twice in a row.

## References

* `docs/backlog.md` (*misc*, the two entries of 2026-10-15)
* `.claude/rules/test-design.md` (*Fixture provenance*: fixtures force their precondition)
* `.claude/rules/testing.md` (*Suite hygiene*)
* Task 16 (persons names unslashed, escaped at every SQL use)

## Findings

* typetags `CreationCapTest::testTheLastTagOfTheDayIsCreated` answers 429. `seedTypedTagCreations()` inserts `TYPETAGS_NEW_TAGS_PER_DAY - 1` (254) activity rows on top of the account's real creations of the day; `typetags_normal` had 3 from other suites' runs, so the cap was already reached. Reproduced alone 2026-10-15. Not a production defect: the fixture does not force its precondition.
* persons `IndexRebuildTest::testDroppingTheTablesAndRescanningRebuildsTheIndex` dies on a duplicate key in `pwg.persons.rescan` for the person `D'Arcy "R" & Töchter` (`piwigo_persons` id 157). No suite creates that name; it is left over from the exploratory sessions. A quoted name failing the rescan points at the escaping defect task 16 fixes.

* 2026-10-16, after task 16 and the repair of photos 100, 103-105 (files hold no backslashes any more): `IndexRebuildTest` no longer crashes on `D'Arcy "R" & Töchter` but fails with "a persons row the index held before the rebuild did not come back" for person 146 `Name` (`url_name` `name`, `tag_id` 13835, which is the marker tag `Name ?`). That row has no `piwigo_person_region` row and no file names it, so no rescan can restore it. Its link to `Name ?` is the `url_name` fallback task 17 removes. Decide: remove the region-less leftover, and/or have the test exclude persons with no region from "nothing the index held is missing", since only regions are derived from the files (decision 0020)

## Work

* [ ] typetags: the cap cases force the account's count for today, not add to it (seed `N - existing` rows, or count the account's rows first and assert the starting count before the call). Submodule commit
* [ ] persons: after task 16, re-run `IndexRebuildTest` with the leftover row in place. If it still fails, find why the rescan inserts a name the index already holds and fix it test-first (a `[NEG]` case with a quoted name already in `piwigo_persons`)
* [ ] Remove both entries from `docs/backlog.md`

## Verification

* [ ] `CreationCapTest` passes with prior creations of the day present for `typetags_normal` (seed some first) and with none
* [ ] `IndexRebuildTest` passes with a person `D'Arcy "R" & Töchter` in the index before the run
* [ ] typetags and persons integration suites pass twice in a row, each under the suite lock, commands named
