# 0053 — typetags' admin prefilter includes its template too

Date: 2026-10-11
Status: accepted
Supersedes, in [0036](0036-prefilters-include-plugin-templates.md), the consequence
"`plugins/typetags` (a submodule) still pastes `template/tags.tpl` in".

## Context

Decision 0036 moved persons and provenance from pasting a plugin template into the host page to
injecting `{include file='<absolute path>'}`, because Smarty checks only the host template's mtime
and a pasted copy is frozen into the compiled page. typetags was left out: its fix is a commit in
the submodule that has to be pushed to `github.com/christianbaumann/Piwigo-Colored-Tags`.

## Decision

`typetags_admin_prefilter()` injects `{include file='<realpath of template/tags.tpl>'}`, the shape
of `persons_template_include()` (submodule commit `6407fe3`, pushed 2026-10-11). The guard is
`plugins/typetags/tests/Unit/InjectedTemplateIncludeTest.php`, which runs core's real
`admin/themes/default/template/tags.tpl` through the prefilter.

## Consequences

- A change to `plugins/typetags/template/tags.tpl` reaches every install with no cache purge.
- An install that compiled the admin tags page before this change still holds the pasted copy and
  needs one purge (Werkzeuge → Wartung → *Kompilierte Vorlagen entfernen*), as 0036 says for the
  other two plugins.
- Every prefilter in the fork now includes; only a change to a prefilter's *code* needs a purge.
