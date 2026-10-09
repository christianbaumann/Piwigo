# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this
repository. Keep it under 100 lines — anything longer moves into `.claude/rules/`, which is
where most of the detail already lives. See *Additional rules* below.

## Project Overview

Piwigo — open-source photo gallery web application. Procedural PHP, Smarty templates, MySQL/MariaDB.

- Version: `PHPWG_VERSION` in `include/constants.php` (currently `17.0.0beta1`)
- Upstream supports PHP 7.4+; this checkout runs PHP 8.4 locally
- This is a fork of Piwigo. It vendors the Colored Tags plugin (`plugins/typetags`) as a git submodule pointing at `github.com/christianbaumann/Piwigo-Colored-Tags` (its colour groups carry fork-added `striped` and `emoji` columns, the emoji stored as code points, [decision 0048](docs/agents/decisions/0048-typetags-emoji-are-stored-as-code-points.md); it fires `typetags_tags_regrouped` when a tag's group changes), and carries four fork-local plugins, `plugins/provenance`, `plugins/persons`, `plugins/photoedit` and `plugins/photoinfo`, as plain tracked directories
- Two fork-local `trigger_notify()` calls have been added to core so the provenance plugin can hook the paths that create album links. Upstream has neither:
  - `associate_images_to_categories` in `admin/include/functions.php`, inside the `if (count($inserts))` block — the funnel every virtual link goes through (API, Batch Manager, upload). Payload: `image_ids`, `category_ids`
  - `site_update_associate_images` in `admin/site_update.php`, after the `$insert_links` `mass_inserts()` — the filesystem sync inserts its storage links directly without calling the helper. Payload: the `$insert_links` rows. This is the **only** trigger in that file; anything claiming it fires none is out of date
- One fork-local core fix: album thumbnails no longer count an admin's empty sub-albums, which the listing hides (`discount_hidden_subalbums()`, `include/category_cats.inc.php`), see [decision 0037](docs/agents/decisions/0037-album-thumbnails-count-only-listed-subalbums.md)
- The `modus` theme carries one fork-local change: fit-to-area and zoom on the picture page (`js/photo.zoom.js`, a changed `rvas_choose()`), see [decision 0032](docs/agents/decisions/0032-modus-picture-fit-and-zoom-is-a-fork-local-theme-edit.md)
- `plugins/persons` needed **no** core change at all — don't assume symmetry with provenance. It reaches everything it needs through existing events (`ws_add_methods`, `loc_end_picture`, `loc_begin_admin_page`, `delete_elements`), photoedit's `photoedit_preview`/`_begin`/`_end` (provenance hooks the last two for its lock) and Smarty prefilters. It fires one event of its own, `persons_tags_changed` (a `trigger_change`; listeners return a write result per photo), when a region change, rename or delete changes a photo's person tags, never from a rescan. The image file is the source of truth for regions; its two tables are a rebuildable index ([decision 0020](docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md))
- `plugins/photoedit` (turn and crop a photo, written into the file, webmaster only) needs no core change either: `loc_end_picture` and `add_picture_button()` ([decision 0034](docs/agents/decisions/0034-photo-edits-change-the-file.md))
- `plugins/photoinfo` (a photo's date, partial or qualified, and info text, i.e. core's `comment`, edited on the picture page and written into the file; no core change) requires provenance and writes through its exiftool runner and lock ([decisions 0040-0043](docs/agents/decisions/)); provenance fires `provenance_caption_parts` so the info text leads every caption it writes ([decision 0038](docs/agents/decisions/0038-multi-line-exiftool-values-travel-in-value-files.md)). It re-registers core's `pwg.images.setInfo` around core's own handler, so a date or description saved in core's screens reaches the file ([decision 0039](docs/agents/decisions/0039-core-screens-reach-photoinfo-through-their-own-events.md)). It also writes a photo's tags into the file as keywords (`XMP-dc:Subject`, `IPTC:Keywords`, `XMP-lr:HierarchicalSubject`, marker `XMP-pwginfo:TagsWritten`) whenever they change, by re-registering the tag ws methods and listening to core's, typetags' and persons' events, under persons' lock first ([decisions 0046](docs/agents/decisions/0046-a-photos-tags-travel-in-its-file.md), [0049](docs/agents/decisions/0049-tag-writes-take-the-persons-lock-first.md), [0050](docs/agents/decisions/0050-uploads-and-iptc-import-do-not-write-tags.md)); a tag typed in while tagging goes to the `Freitext` group ([decision 0047](docs/agents/decisions/0047-new-tags-typed-while-tagging-go-to-freitext.md))

## Development Environment

DDEV (Docker). Site: https://piwigo.ddev.site — nginx-fpm, PHP 8.4, MariaDB 11.8.

```bash
ddev start                 # also: stop, restart, status, launch
ddev exec php <script>     # run PHP inside the web container
ddev mysql                 # DB shell (database/user/password all `db`, host `db`)
ddev logs -f
```

No build step for the application itself — PHP is served directly from the repo root. The only dependency managers are the per-plugin `composer.json` / `package.json` files in `plugins/typetags`, `plugins/provenance`, `plugins/persons`, `plugins/photoedit` and `plugins/photoinfo`, all dev-only (test runners; see [plugin-test-suites.md](.claude/rules/plugin-test-suites.md)).

`exiftool` is available in the web container via `webimage_extra_packages` in `.ddev/config.yaml`
(the provenance plugin's write-back needs it); production has it preinstalled. `exiv2` comes the
same way, dev-only: an independent keyword reader in photoinfo's `TagWriteTest`.

ImageMagick is also used, but only by three integration suites, as an **independent** reader of what
a write-back produced — reading back with exiftool cannot tell data written to the standard slots
apart from data only exiftool knows about. `identify` in provenance's
`WriteBackTest::testAnIndependentReaderFindsTheCaption`; `convert <file> xmp:-` in persons'
`WriteRegionsTest::testAnIndependentLibraryFindsTheRegionInTheStandardXmpPacket`, which extracts
the raw XMP packet and reads the MWG region out of it as text, and the same in photoinfo's
`TagWriteTest::testAnIndependentReaderFindsTheKeywordsInTheStandardXmpSlots` for the keywords. It comes from the DDEV web image
itself rather than `webimage_extra_packages`; if a future image drops it, all three fail loudly naming it.
It is also a fixture tool: `createTestImageAs()` in provenance's and photoinfo's `FixtureBuilder`
`convert`s a gallery PNG to JPEG or HEIC for the metadata-sync tests: PHP reads EXIF from JPEG, never from the gallery's PNGs.

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
- [handbook.md](.claude/rules/handbook.md) — read before changing `handbuch/`, re-taking a
  screenshot, or changing a German string a screenshot shows
- [deployment.md](.claude/rules/deployment.md) — read before changing `tools/deploy` or deploying
  this fork to the web space

Stack-independent, applied on every task that touches tests or gates:

- [testing.md](.claude/rules/testing.md) — read before placing a test at a layer or running a suite
- [test-design.md](.claude/rules/test-design.md) — read when choosing test cases or writing an assertion
- [e2e-tests.md](.claude/rules/e2e-tests.md) — read before writing or changing a Playwright spec
- [mutation-testing.md](.claude/rules/mutation-testing.md) — read at the end of a plan, when auditing test strength
- [backpressure.md](.claude/rules/backpressure.md) — read before adding a gate, recording a decision, or writing a doc
- [precommit-hooks.md](.claude/rules/precommit-hooks.md) — read before changing `.githooks/` or the commit gate
