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

* [x] typetags: the cap cases force the account's count for today, not add to it (seed `N - existing` rows, or count the account's rows first and assert the starting count before the call). Submodule commit
  **Note:** `FixtureBuilder::forceTypedTagCreationsToday()` counts the account's rows with production's `typetags_typed_tags_condition()`, seeds the difference, refuses when the account already has more, and asserts the final count. All four at-cap/cap-1 cases of `CreationCapTest` use it. Deviation: the two "does not count" cases (older than a day, another account) now also start the normal account at `N - 1`, so they are boundary cases (one counted row would refuse) rather than passing at any low count. `TypedNameMatchTest`'s two at-cap seeds left as they are: they only need "at or over the cap". Submodule commit left to the main agent
* [x] persons: after task 16, re-run `IndexRebuildTest` with the leftover row in place. If it still fails, find why the rescan inserts a name the index already holds and fix it test-first (a `[NEG]` case with a quoted name already in `piwigo_persons`)
  **Note:** 2026-10-17, user's answer 1a: checked first that person 146 still matched (`Name`, `url_name` `name`, `tag_id` 13835, 0 regions), then deleted it under the suite lock with `DELETE FROM piwigo_persons WHERE id = 146 AND name = 'Name' AND NOT EXISTS (SELECT 1 FROM piwigo_person_region WHERE person_id = 146);` (1 row). `IndexRebuildTest` then passed alone (1 test, 52 assertions). No code change: the rescan no longer inserts a quoted name twice since task 16, so no `[NEG]` case was added. The test was not changed to skip region-less persons (answer 1a, not 1c)
* [x] Remove both entries from `docs/backlog.md`

## Verification

* [x] `CreationCapTest` passes with prior creations of the day present for `typetags_normal` (seed some first) and with none
  **Note:** Verified 2026-10-17: with 3 activity rows of the day for `typetags_normal` outside the fixture's marker (`session_idx` `task38-prior`, inserted under the suite lock), the old fixture failed `testTheLastTagOfTheDayIsCreated` with 429 (reproduced); with `forceTypedTagCreationsToday()` the class passed (6 tests) with those rows present, and again after deleting them (0 prior rows)
* [x] `IndexRebuildTest` passes with a person `D'Arcy "R" & Töchter` in the index before the run
  **Note:** Verified 2026-10-17: person 339 `D'Arcy "R" & Töchter` (4 regions) in the index; `IndexRebuildTest` OK alone (1 test, 52 assertions) after person 146 was deleted, and in each of the three full persons runs below
* [x] typetags and persons integration suites pass twice in a row, each under the suite lock, commands named
  **Note:** 2026-10-17 (task 38), each under the suite lock with `ddev exec flock /tmp/pwg-suite.lock bash -c 'set -a; . local/config/<p>-test.env; set +a; plugins/<p>/vendor/bin/phpunit --configuration plugins/<p>/phpunit.xml --testsuite integration [--order-by=reverse]'`: typetags 179 OK twice and once `--order-by=reverse`; persons 209 (1 skipped, `PluginActivationTest`'s recorded gap) OK in default order and then `--order-by=reverse`, after a first run with 2 failures in task 18's then-uncommitted `TagToolGuardTest` (a fatal in its new `include/tag_guard.inc.php`, mid-edit by that agent; `IndexRebuildTest` passed in all three); photoedit 109 OK; photoinfo 219 OK; provenance 353 OK (3 skipped)
