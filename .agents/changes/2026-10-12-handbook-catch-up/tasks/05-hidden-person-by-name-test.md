---
id: 05
dependencies: []
---

# Task 05: Regression test for a hidden person attached by name

The persons integration suite proves that saving a region under the full name of a person who is
only on photos the account cannot see attaches that existing person instead of creating a second
one.

## References

* `design.md#follow-ups` (finding 6)
* [decision 0061](../../../../docs/agents/decisions/0061-persons-list-and-tagging-screen-respect-album-permissions.md)
* `plugins/persons/tests/Integration/VisibilityTest.php` (`seedJaneHiddenAndJohnVisible()`, `untaggedVisibleImage()`, `testThePickerDoesNotOfferAPersonOnlyOnAHiddenPhoto`)
* `plugins/persons/include/index.inc.php` (`persons_person_id_from_name()`)
* `docs/agents/TESTING.md` (hand-check ledger row of task 03, which records the ad hoc run)
* `.claude/rules/test-design.md` (anti-vacuity, `[ERR]` vs requirement), `.claude/rules/plugin-test-suites.md` (persons suite command, `persons-test.env`)

## Work

* [x] Add a case to `VisibilityTest`, next to the picker cases, tagged against decision 0061 (a requirement, not `[ERR]`): with Jane only on a hidden photo, `pwg.persons.addRegion` as `persons_normal` on a visible photo with Jane's full name succeeds; afterwards there is exactly one person row named Jane and the new region belongs to her existing id. Guard first that Jane is missing from that account's picker list, so the case cannot pass on a state where she is visible
  **Note:** `testSavingTheFullNameOfAHiddenPersonAttachesTheExistingPerson`, tagged `[HAPPY] [ECP]`; the region goes on a fresh untagged public photo (`untaggedVisibleImage()`), the same one the picker guard asks about. Besides the picker guard, a second guard asserts the seeded Jane has a person row
* [x] Clean up through the existing fixture teardown; no new fixture unless the case needs one
* [x] Update the task 03 ledger row in `docs/agents/TESTING.md`: name the new case as its successor
  **Note:** only that row edited; the task 04 row is the main agent's

## Verification

* [x] The new case passes inside the persons integration suite (`ddev exec flock /tmp/pwg-suite.lock bash -c 'set -a; . local/config/persons-test.env; set +a; plugins/persons/vendor/bin/phpunit --testsuite integration --configuration plugins/persons/phpunit.xml'`)
  **Note:** Verified via that command: OK, 180 tests, 823 assertions, 1 skipped (`PluginActivationTest::testAVersionBumpAddsAColumnAnOlderInstallIsMissing`, decision 0018, pre-existing); the case alone via `--filter` OK (1 test, 6 assertions)
* [x] Breaking the behaviour turns it red: with `persons_person_id_from_name()` mutated to find no one, the case fails on the duplicate row (revert afterwards; Mutagen and opcache delays per `.claude/rules/mutation-testing.md`)
  **Note:** Verified via a flip script (backup `/tmp/task05-flip-personid.bak`, restored by an `EXIT` trap, host `md5` vs `ddev exec md5sum` polled until equal, then 3 s for opcache, on apply and revert): the lookup's `WHERE name = ...` became `WHERE 1 = 0 AND name = ...` (pattern grepped first, one hit); `--filter` run of the new case red, but **not on the duplicate row**: `piwigo_persons.name` has a UNIQUE index, so the second insert throws and `addRegion` fails, and the case dies on its `stat === 'ok'` assertion. A duplicate row cannot arise in this schema; the index is what the count assertion backs up. Reverted, `git diff plugins/persons/include/index.inc.php` empty, the case green again in both later full suite runs
* [x] The suite passes twice in a row; no leftover fixture person or album
  **Note:** Verified via the same command, run four times after the mutant's revert, all green with the same counts (11 to 12.5 minutes each); `ddev mysql` count of `piwigo_persons` names `Persons Visibility%` and `piwigo_categories` names `Persons p%fixture` afterwards: 0 and 0
