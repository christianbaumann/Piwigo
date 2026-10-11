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

* [ ] Research note `docs/agents/research/2026-10-11-persons-test-leak.md`: turn on MariaDB `general_log`, list `upload/persons-test/` and the persons-test rows before and after one green integration run and one E2E seed/restore cycle, name the test and code path that leave the file
* [ ] Write the reproducing test at the lowest layer that shows the leak; watch it fail
* [ ] Fix the cause found; no catch-all sweep in `--restore`
* [ ] Turn `general_log` off again; remove the backlog item

## Verification

* [ ] After a green integration run, `upload/persons-test/` holds the same files as before and no `persons-test-*` image row was added
* [ ] After a seed/restore cycle, no `Persons E2E` album remains
* [ ] Reverting the fix turns the reproducing test red
* [ ] persons integration and E2E suites pass twice in a row
