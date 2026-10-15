---
id: 37
dependencies:
- 01
---

# Task 37: modus: long words wrap, zoom scroll bar in the window

No long word widens a page, and the zoomed photo's scroll bar is visible.

## References

* `design.md#album-description-keeps-lines-long-words-wrap`
* `design.md#the-zooms-scroll-bar-sits-inside-the-window`
* decision 0032

## Work

* [ ] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [ ] `overflow-wrap: anywhere` for tag names and `.additional_info` (`themes/modus/css/base.css.tpl`)
* [ ] `themes/modus/js/photo.zoom.js:98` subtracts `#theImage` padding-bottom from `max-height`
* [ ] Add the edit to `.claude/rules/fork-local-core-edits.md`

## Verification

* [ ] E2E: 255-character tag and long description word, no horizontal page scroll
* [ ] E2E: zoomed photo's horizontal scroll bar bottom within the window at 1080 and 900 high
* [ ] provenance E2E `roles.spec.js` zoom still passes
* [ ] Relevant suites pass, each named with the command that ran (`.claude/rules/plugin-test-suites.md`)
