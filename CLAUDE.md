# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this
repository. Keep it under 100 lines — anything longer moves into `.claude/rules/`, which is
where most of the detail already lives. See *Additional rules* below.

## Project Overview

Piwigo — open-source photo gallery web application. Procedural PHP, Smarty templates, MySQL/MariaDB.

- Version: `PHPWG_VERSION` in `include/constants.php` (currently `17.0.0beta1`)
- Upstream supports PHP 7.4+; this checkout runs PHP 8.4 locally
- This is a fork of Piwigo. It vendors the Colored Tags plugin (`plugins/typetags`) as a git submodule pointing at `github.com/christianbaumann/Piwigo-Colored-Tags`, and carries two fork-local plugins, `plugins/provenance` and `plugins/persons`, as plain tracked directories
- Two fork-local `trigger_notify()` calls have been added to core so the provenance plugin can hook the paths that create album links. Upstream has neither:
  - `associate_images_to_categories` in `admin/include/functions.php`, inside the `if (count($inserts))` block — the funnel every virtual link goes through (API, Batch Manager, upload). Payload: `image_ids`, `category_ids`
  - `site_update_associate_images` in `admin/site_update.php`, after the `$insert_links` `mass_inserts()` — the filesystem sync inserts its storage links directly without calling the helper. Payload: the `$insert_links` rows. This is the **only** trigger in that file; anything claiming it fires none is out of date
- `plugins/persons` needed **no** core change at all — don't assume symmetry with provenance. It reaches everything it needs through existing events (`ws_add_methods`, `loc_end_picture`, `loc_begin_admin_page`, `delete_elements`) and Smarty prefilters. The image file is the source of truth for regions; its two tables are a rebuildable index ([decision 0020](docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md))

## Development Environment

DDEV (Docker). Site: https://piwigo.ddev.site — nginx-fpm, PHP 8.4, MariaDB 11.8.

```bash
ddev start                 # also: stop, restart, status, launch
ddev exec php <script>     # run PHP inside the web container
ddev mysql                 # DB shell (database/user/password all `db`, host `db`)
ddev logs -f
```

No build step for the application itself — PHP is served directly from the repo root. The only dependency managers are the per-plugin `composer.json` / `package.json` files in `plugins/typetags`, `plugins/provenance` and `plugins/persons`, all dev-only (test runners; see [plugin-test-suites.md](.claude/rules/plugin-test-suites.md)).

`exiftool` is available in the web container via `webimage_extra_packages` in `.ddev/config.yaml`
(the provenance plugin's write-back needs it); production has it preinstalled.

ImageMagick is also used, but only by two integration suites, as an **independent** reader of what
a write-back produced — reading back with exiftool cannot tell data written to the standard slots
apart from data only exiftool knows about. `identify` in provenance's
`WriteBackTest::testAnIndependentReaderFindsTheCaption`; `convert <file> xmp:-` in persons'
`WriteRegionsTest::testAnIndependentLibraryFindsTheRegionInTheStandardXmpPacket`, which extracts
the raw XMP packet and reads the MWG region out of it as text. It comes from the DDEV web image
itself rather than `webimage_extra_packages`; if a future image drops it, both fail loudly naming it.

## Agent working conventions

- Research notes: `docs/agents/research/YYYY-MM-DD-topic.md`
- Implementation plans: `docs/agents/plans/YYYY-MM-DD-topic.md`
- Both carry YAML frontmatter: `date`, `git_commit`, `branch`, `topic`, `tags`, `status`
- Decisions: `docs/agents/decisions/NNNN-slug.md`, one per file, numbered. A decision *not* to fix something is as worth recording as a fix — cite the file instead of re-litigating it. A decision that later changes gets a new file superseding the old, never an edit that erases what was decided
- Browser verification reports and screenshots: `.agent-tests/YYYY-MM-DD-topic/` — git-ignored, local only. Write them there for the current task, but don't expect earlier runs to exist in a fresh clone

## Additional rules

Repository-specific, read on the task that needs them:

- [piwigo-architecture.md](.claude/rules/piwigo-architecture.md) — read before editing core, a
  theme, or a plugin's integration points (request lifecycle, entry points, admin routing, web
  services, DB layer, plugin system, i18n, derivatives, core code style, security patterns)
- [plugin-test-suites.md](.claude/rules/plugin-test-suites.md) — read before running or adding a
  test in `plugins/*`, and before claiming any check passed
- [piwigo-dev-environment.md](.claude/rules/piwigo-dev-environment.md) — read when a change does
  not show up, when touching `_data/`, or when adding a plugin or theme git must track
- [git-and-commits.md](.claude/rules/git-and-commits.md) — read before committing, branching, or
  pulling from upstream
- [deployment.md](.claude/rules/deployment.md) — read before changing `tools/deploy` or deploying
  this fork to the web space

Stack-independent, applied on every task that touches tests or gates:

- [testing.md](.claude/rules/testing.md) — read before placing a test at a layer or running a suite
- [test-design.md](.claude/rules/test-design.md) — read when choosing test cases or writing an assertion
- [e2e-tests.md](.claude/rules/e2e-tests.md) — read before writing or changing a Playwright spec
- [mutation-testing.md](.claude/rules/mutation-testing.md) — read at the end of a plan, when auditing test strength
- [backpressure.md](.claude/rules/backpressure.md) — read before adding a gate, recording a decision, or writing a doc
- [precommit-hooks.md](.claude/rules/precommit-hooks.md) — read before changing `.githooks/` or the commit gate
