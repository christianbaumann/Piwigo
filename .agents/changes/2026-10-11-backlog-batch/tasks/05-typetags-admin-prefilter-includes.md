---
id: 05
dependencies: []
---

# Task 05: typetags admin prefilter includes its template

A change to `plugins/typetags/template/tags.tpl` reaches the admin tags page without purging compiled templates, as decision 0036 already gives the other plugins.

## References

* `design.md#typetags-admin-prefilter-includes-its-template`
* `docs/agents/decisions/0036-prefilters-include-plugin-templates.md`
* `plugins/typetags/include/events_admin.inc.php` (`typetags_admin_prefilter()`)
* `plugins/persons/tests/*/InjectedTemplateIncludeTest.php` (guard shape)
* `.claude/rules/plugin-test-suites.md`, `.claude/rules/deployment.md` (the "typetags still pastes" exceptions)

## Work

* [x] Add a guard test in typetags modelled on persons' `InjectedTemplateIncludeTest`; watch it fail against the pasting prefilter
  **Note:** `plugins/typetags/tests/Unit/InjectedTemplateIncludeTest.php` (2 tests, feeds core's real `admin/.../tags.tpl` through the prefilter); red first: "does not include template/tags.tpl exactly once / 0 is identical to 1" and the pasted content found
* [x] Change `typetags_admin_prefilter()` to inject `{include file='<absolute path>'}`
* [x] Commit in the submodule, push to `github.com/christianbaumann/Piwigo-Colored-Tags`, then commit the new submodule pointer in the superproject
  **Note:** submodule `6407fe3` pushed (`19593eb..6407fe3`); superproject `71a02399a`. The keychain offered GitHub account `dark-sith-lord` (403); pushed once via `gh auth git-credential` as `christianbaumann`, no config change
* [x] Remove the typetags exceptions from both rule files, the note on the first remote purge stays; remove the backlog item
  **Note:** also: decision 0053 supersedes 0036's "typetags still pastes" line; `.claude/rules/handbook.md`'s "a plugin template embedded by a prefilter needs a purge" corrected

## Verification

* [x] After compiling the admin tags page once, an edit to `tags.tpl` shows up on the next request without clearing `_data/templates_c/`
  **Note:** Ad hoc 2026-10-11: logged in as `typetags_webmaster`, curled `admin.php?page=tags` (compiled), appended `<!-- ADHOC-MARKER-0511 -->` to `tags.tpl`, waited for Mutagen sync, requested again with no purge: marker present; reverted, marker gone
* [x] The admin tags page still shows the colour form and works (existing `admin-tags.spec.js`)
  **Note:** Verified via `admin-tags.spec.js` in the E2E run below
* [x] typetags unit, integration and E2E suites pass
  **Note:** 2026-10-11: unit OK (84 tests), integration OK (114), E2E 45 passed. Reverting the fix turned exactly the 2 guard tests red
* [x] `git submodule status` shows a commit that exists on the submodule's origin
  **Note:** `6407fe3 plugins/typetags (heads/master)`; `git ls-remote origin master` returns `6407fe3205958f281e951204b00c58961e40f4a7`
