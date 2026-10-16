---
id: 16
dependencies: []
---

# Task 16: persons: names unslashed once, escaped at every SQL use

Any person name, quotes included, saves once and never blocks a photo.

## References

* `design.md#person-names-are-unslashed-once-escaped-at-every-sql-use`
* `plugins/persons/include/index.inc.php:334-367`, `:105`, `:615`, `:732-746`
* `plugins/persons/include/ws_functions.inc.php`

## Work

* [x] `stripslashes` the name once in `pwg.persons.addRegion` and `pwg.persons.rename`
  **Note:** through the new pure `persons_name_from_request()` (`include/functions.inc.php`), which strips and then cleans.
* [x] Escape the name for the SELECT, `tag_id_from_tag_name()` and `single_insert()` in `persons_person_id_from_name()`
  **Note:** the copied `url_name` is escaped for `single_insert()` as well: in-process, with no `render_tag_url` handler, it is the raw name and broke the insert.
* [x] A reindex error becomes a ws error, not a fatal
  **Note:** `persons_reindex_image()` catches `Exception`, logs it through core's `$logger` and returns `ok => false` with `PERSONS_REINDEX_FAILED_MESSAGE`; addRegion turns that into its 500 ws error, rescan lists the photo under `failed`.
* [ ] Repair photos 100, 103, 104, 105 once through the fixed `pwg.persons.rename`; record the step (needs the user's approval)
  **Note:** not run: the agent's permission check refused writing the real gallery photos. Ready script: `.agent-tests/2026-10-16-task16-repair/repair.php` (git-ignored; backups of the four files beside it in `backup/`). Run with `ddev exec flock /tmp/pwg-suite.lock bash -c 'set -a; . local/config/persons-test.env; set +a; php .agent-tests/2026-10-16-task16-repair/repair.php'`. Steps: `pwg.persons.delete` the unslashed persons 157 and 152 (they hold no region the files name; the tag rows stay), `pwg.persons.rescan` 100,103,104,105 (creates the slashed-name persons, which reuse the old tags by `url_name`), then `pwg.persons.rename` each slashed person to its typed name, which rewrites the four files. A rename alone cannot do it: the unslashed name is already taken by 157/152.

## Verification

* [x] Unit: `D'Arcy "R" & Töchter` resolves to one row
  **Note:** Verified via `PersonNameTest` (unit: the slashed request form comes out as typed; cleaned; a real backslash is lost, `[ERR]`) and `ReindexTest::testANameWithQuotesOnTwoPhotosIsOnePersonRow` (integration, in-process: two files, one row). Deviation: the row count needs the database, so it sits at integration; the unit layer covers the unslashing.
* [x] Integration: two saves with that name, one person, file holds the name without backslashes
  **Note:** Verified via `AddRegionTest::testANameWithQuotesSavesTwiceAsOnePerson`, `PersonAdminApiTest::testRenamingToANameWithQuotesKeepsItAsTyped`, `ReindexTest::testANameThatCannotBeResolvedIsReportedNotFatal`; all four new cases failed before the fix for the expected reason (duplicate key / SQL syntax error / uncaught `InvalidArgumentException`, slashed name in the rename answer).
* [x] photoedit crop refusal shows the name without backslashes
  **Note:** Verified via photoedit `ApplyRegionsTest::testTheRefusalNamesAPersonWithQuotesAsTyped` (region saved through `pwg.persons.addRegion`, normal account's crop refused naming it as typed); red before the fix.
* [-] persons and photoedit suites pass
  **Note:** 2026-10-16: persons unit OK (206), photoedit unit OK (107), photoedit integration OK (109 tests). persons integration: 185 tests, 1 failure, `IndexRebuildTest` - no longer the duplicate key: the rescan now indexes the polluted files' slashed name as its own person, so 157 `D'Arcy "R" & Töchter` does not come back. Goes green once the repair above has run. E2E suites not run.
