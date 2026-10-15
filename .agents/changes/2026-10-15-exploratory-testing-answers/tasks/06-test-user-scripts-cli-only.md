---
id: 06
dependencies: []
---

# Task 06: Test-user scripts run from the command line only

Nobody can create accounts or rotate passwords by opening a plugin's test-user script in a browser.

## References

* `design.md#test-user-scripts-run-from-the-command-line-only`

## Work

* [x] Each `plugins/{typetags,provenance,persons,photoedit,photoinfo}/tests/Support/create-test-users.php` exits unless `PHP_SAPI` is `cli` (typetags change is a submodule commit, pushed to `christianbaumann/Piwigo-Colored-Tags`)
  **Note:** typetags submodule commit `b0c63cc`, not yet pushed (main agent pushes)
* [x] One integration test per plugin: request the script over HTTP, assert no account row changed

## Verification

* [x] Each HTTP request gets no account change; each CLI run still works and asserts the roles
  **Note:** Verified via `CreateTestUsersOverHttpTest` in all five plugins (OK, 1 test, 7 assertions each; HTTP 403) and `ddev exec php plugins/<p>/tests/Support/create-test-users.php` (exit 0, four `ok` role lines each)
* [x] Each test goes red with its guard removed
  **Note:** Verified via flip (guard condition -> `if (false)`, container md5 checked, 3 s opcache wait): all five killed by the row-snapshot assertion, all five green after revert, `git diff` back to the guard only
* [ ] The five integration suites pass
  **Note:** provenance (328, 3 skipped), photoedit (107), photoinfo (212) pass. typetags 156/1 failure `CreationCapTest::testTheLastTagOfTheDayIsCreated` (429 cap), persons 181/1 failure `IndexRebuildTest` (duplicate person name `D'Arcy "R" & Töchter`, row id 157): both reproduce in isolation, unrelated to this change, not fixed. Main agent re-ran CreationCapTest alone: same 429; cause is 3 real creations by `typetags_normal` today on top of the seeded cap−1 rows (fixture does not force its precondition). Both recorded in `docs/backlog.md`. The typetags submodule commit `b0c63cc` could not be pushed (403, other GitHub account's credentials); recorded in `docs/backlog.md`
