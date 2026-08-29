# 0014 — Provenance lives in its own plugin, not in typetags and not in core

Date: 2026-08-29
Status: accepted

## Context

The feature needed a home before Phase 1 could create a single file. Three were plausible:

- **Core.** The columns sit on `piwigo_categories` and `piwigo_images`, the two most central
  tables, and the album screen the entry UI extends is `admin/cat_modify.php`. Putting the
  feature in core would need no prefilter and no plugin lifecycle.
- **`plugins/typetags`.** Already vendored here, already carries all three test layers, already
  owns a `picture.php` prefilter — the provenance row lands next to the tag badges.
- **A new `plugins/provenance`.**

## Decision

**A new plugin, `plugins/provenance`**, carried as a plain tracked directory (not a submodule).

Against core: this checkout tracks upstream Piwigo. Every line added to `admin/`, `include/` or
`themes/` is a line that conflicts on the next merge from `Piwigo/Piwigo`, and this feature is a
private-collection concern upstream has no reason to take. The plan therefore held core changes
to the smallest possible surface — two `trigger_notify()` calls, four lines total (Phase 7) — and
put everything else behind the plugin API, which is a supported extension point rather than a
patch.

Against typetags: typetags is a **submodule** pointing at a separate public repository
(`christianbaumann/Piwigo-Colored-Tags`), and it is a fork of an upstream plugin with its own
identity — tag colours. Provenance shares no data, no table and no configuration key with it.
Merging them would mean pushing an unrelated private feature into a public tag-colouring
repository, and every provenance commit would become a submodule-pointer commit in the
superproject.

Against a submodule of its own: there is no second consumer and no upstream to track, so a
submodule buys nothing and costs a second `core.hooksPath` installation, a second checkout step
and a detached-HEAD trap on every clone.

## Consequences

- The two core `trigger_notify()` calls are the fork's entire core surface for this feature, and
  they are documented in CLAUDE.md as fork-local so a future merge conflict is recognised for
  what it is.
- `plugins/provenance` needs its own `!` entry in the root `.gitignore` (which excludes
  `plugins/*`), and `CleanCheckoutTest` in typetags is the pattern for asserting a runtime file
  did not silently fall through that rule.
- The superproject's `core.hooksPath` covers `plugins/provenance` because it is a plain
  directory — `tools/test-hooks.sh` asserts exactly that, and would have to change if the plugin
  ever became a submodule.
- Two plugins now carry unit suites, so the commit gate runs both. `.githooks/lib.sh` holds
  `UNIT_SUITES` as one entry per gated plugin rather than a hardcoded single command.
