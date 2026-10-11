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

* [ ] Add a guard test in typetags modelled on persons' `InjectedTemplateIncludeTest`; watch it fail against the pasting prefilter
* [ ] Change `typetags_admin_prefilter()` to inject `{include file='<absolute path>'}`
* [ ] Commit in the submodule, push to `github.com/christianbaumann/Piwigo-Colored-Tags`, then commit the new submodule pointer in the superproject
* [ ] Remove the typetags exceptions from both rule files, the note on the first remote purge stays; remove the backlog item

## Verification

* [ ] After compiling the admin tags page once, an edit to `tags.tpl` shows up on the next request without clearing `_data/templates_c/`
* [ ] The admin tags page still shows the colour form and works (existing `admin-tags.spec.js`)
* [ ] typetags unit, integration and E2E suites pass
* [ ] `git submodule status` shows a commit that exists on the submodule's origin
