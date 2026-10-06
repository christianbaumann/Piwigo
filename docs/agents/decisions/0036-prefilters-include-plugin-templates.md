# 0036 — Prefilters include a plugin's template, they never paste its content

Date: 2026-10-06
Status: accepted

## Context

Smarty decides whether a compiled template is current from the mtime of the template it compiled
(`picture.tpl`, `picture_modify.tpl`, ...). The persons and provenance prefilters pasted a plugin
template's *content* into that template with `file_get_contents()`, so a later change to the plugin
template never reached an install that had compiled the page before. Nothing reported it.

Found when the picture page stopped reloading after "Markieren beenden" on the remote only: the
2026-10-05 deploy delivered the new `editor.js` (combined assets are keyed on file mtime) but the
remote's compiled `picture.tpl` still held the `public_overlay.tpl` from before
`data-persons-reload-on-exit` existed. Confirmed 2026-10-06 by reading the remote page: editor
present, attribute absent. Reproduced locally by editing `public_overlay.tpl` after the page was
compiled: the change did not show up until `_data/templates_c/` was emptied.

## Decision

Every prefilter in `plugins/persons` and `plugins/provenance` injects
`{include file='<absolute path>'}`, built by `persons_template_include()` /
`provenance_template_include()`. An included template is compiled and checked on its own mtime.
Guarded by `tests/Unit/InjectedTemplateIncludeTest.php` in both plugins.

## Consequences

- A change to an injected `.tpl` reaches every install with no cache purge.
- A change to a prefilter's *code* still does not: the callback's name, not its source, goes into
  the `compile_id`. That still needs `_data/templates_c/` emptied, locally and on the remote.
- An install that compiled a page before this change still holds the pasted-in copy. It needs
  one purge (Werkzeuge → Wartung → *Kompilierte Vorlagen entfernen*) after this deploy.
- The included template is not inside the host template's `{strip}` block any more. Both suites'
  integration and E2E runs passed unchanged, 2026-10-06.
- `plugins/typetags` (a submodule) still pastes `template/tags.tpl` in; recorded in `docs/backlog.md`.
