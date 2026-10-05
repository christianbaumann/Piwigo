---
id: 06
dependencies:
- 05
---

# Task 06: Handbook, backlog and test-strength audit

The German handbook explains turning and cropping, the deferred work is in the backlog, and the unit suite's strength is recorded.

## References

* `design.md#backlog`
* `.claude/rules/handbook.md`, `handbuch/tools/seed.php`, `handbuch/tools/shoot.js`, `handbuch/tools/check.php`
* `.claude/rules/mutation-testing.md`, `docs/agents/TESTING.md`
* `.claude/rules/piwigo-architecture.md` (web services section)

## Work

* [ ] Handbook section "Foto drehen und zuschneiden" with element screenshots of the demo album only (extend the demo seed if needed); German strings added to `GermanOverrideKeyTest` if any core/plugin string is overridden
* [ ] `docs/backlog.md`: deskew, lossless JPEG via `jpegtran`, undo from the backup
* [ ] Correct `.claude/rules/piwigo-architecture.md`: a WS handler may `include_once` `admin/include/functions.php` itself (`pwg.categories.php:755`)
* [ ] Mutation table for photoedit's unit suite and persons' new region transform, recorded in `docs/agents/TESTING.md`
* [ ] Hand-check ledger entry: the edit mode looks right in the dark modus skins

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes; seed `--restore` returns the install to its album/photo count
* [ ] Every mutant in the table is killed, or its survival is recorded with the reason
* [ ] All photoedit, persons and provenance suites pass
