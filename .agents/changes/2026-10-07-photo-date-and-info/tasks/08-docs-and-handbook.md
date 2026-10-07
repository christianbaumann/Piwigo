---
id: 08
dependencies:
- 05
- 06
- 07
---

# Task 08: Documentation and handbook

The German handbook explains how to set a photo's date and info text, and the project docs
describe the new plugin, so the next person or agent finds both.

## References

* `design.md` (all key decisions)
* `.claude/rules/handbook.md` (seed, shoot, restore, `check.php`; never photograph the real gallery)
* `.claude/rules/backpressure.md` (decision log, length budgets, keep instructions honest)
* `CLAUDE.md`, `.claude/rules/plugin-test-suites.md`, `.claude/rules/piwigo-dev-environment.md`, `.claude/rules/deployment.md`
* `docs/agents/decisions/`, `docs/agents/TESTING.md`
* `local/language/de_DE.lang.php`, `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php`

## Work

* [ ] Decision records for the design's key decisions that later work must not re-argue (at least: date model, camera-metadata rule, caption composition, photoinfo requires provenance)
* [ ] `CLAUDE.md`: photoinfo in the fork overview (no core change, depends on provenance); keep under 100 lines
* [ ] `.claude/rules/plugin-test-suites.md`: photoinfo suite commands, test accounts, what the suites mutate
* [ ] `.claude/rules/piwigo-dev-environment.md`: the `.gitignore` entry, any `_data/` use
* [ ] `.claude/rules/deployment.md`: rescan after deploy
* [ ] `docs/agents/TESTING.md`: hand checks with no automated oracle
* [ ] Handbook: a page or section for Datum and Info; demo album extended if needed; screenshots via `seed.php`/`shoot.js`/`--restore`; `check.php`

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes
* [ ] Screenshots show only the demo album, and the install is back to its 5 albums / 105 photos after `--restore`
* [ ] `CLAUDE.md` is under 100 lines and every rules file under 500
* [ ] Every suite command written in the docs was run once as written
