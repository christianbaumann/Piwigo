# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Piwigo — open-source photo gallery web application. Procedural PHP, Smarty templates, MySQL/MariaDB.

- Version: `PHPWG_VERSION` in `include/constants.php` (currently `17.0.0beta1`)
- Upstream supports PHP 7.4+; this checkout runs PHP 8.4 locally
- This is a fork of Piwigo. It vendors the Colored Tags plugin (`plugins/typetags`) as a git submodule pointing at `github.com/christianbaumann/Piwigo-Colored-Tags`, and carries a fork-local plugin `plugins/provenance` as a plain tracked directory
- Two fork-local `trigger_notify()` calls have been added to core so the provenance plugin can hook the paths that create album links. Upstream has neither:
  - `associate_images_to_categories` in `admin/include/functions.php`, inside the `if (count($inserts))` block — the funnel every virtual link goes through (API, Batch Manager, upload). Payload: `image_ids`, `category_ids`
  - `site_update_associate_images` in `admin/site_update.php`, after the `$insert_links` `mass_inserts()` — the filesystem sync inserts its storage links directly without calling the helper. Payload: the `$insert_links` rows. This is the **only** trigger in that file; anything claiming it fires none is out of date

### Git remotes

Only one remote is configured: `origin` → `github.com/christianbaumann/Piwigo` (the fork). There is **no** `upstream` remote, so pulling from Piwigo/Piwigo needs it added first:

```bash
git remote add upstream https://github.com/Piwigo/Piwigo.git
```

Trunk is `master`. Working branches so far are named `fix/<topic>` (`fix/css-not-loading`, `fix/colored-tag-badge-on-picture`) and branch off `master`. There is no `development` branch.

## Development Environment

DDEV (Docker). Site: https://piwigo.ddev.site — nginx-fpm, PHP 8.4, MariaDB 11.8.

```bash
ddev start                 # also: stop, restart, status, launch
ddev exec php <script>     # run PHP inside the web container
ddev mysql                 # DB shell (database/user/password all `db`, host `db`)
ddev logs -f
```

No build step for the application itself — PHP is served directly from the repo root. The only dependency managers are the per-plugin `composer.json` / `package.json` files in `plugins/typetags` and `plugins/provenance`, all dev-only (test runners; see Testing).

`exiftool` is available in the web container via `webimage_extra_packages` in `.ddev/config.yaml`
(the provenance plugin's write-back needs it); production has it preinstalled.

ImageMagick's `identify` is also used, but only by the provenance integration suite, as an
**independent** reader of what the write-back produced — reading back with exiftool cannot tell a
caption written to the standard slots apart from one only exiftool knows about. It comes from the
DDEV web image itself rather than `webimage_extra_packages`; if a future image drops it,
`WriteBackTest::testAnIndependentReaderFindsTheCaption` fails loudly naming it.

### Caches

Smarty compiles templates into `_data/templates_c/` and concatenates CSS/JS into `_data/combined/`.
`$conf['template_compile_check']` defaults to true, so `.tpl` edits normally recompile on their own — but stale combined assets are the usual cause of "my CSS/JS change didn't show up":

```bash
rm -rf _data/templates_c/* _data/combined/*
```

### `_data/provenance/` — the write-back working area

The provenance plugin keeps its own scratch space under `_data/`, defined once in
`plugins/provenance/include/functions.inc.php` and never spelled out anywhere else:

- `_data/provenance/locks/` — one `<sha1(image path)>.lock` file per image guarded against a
  concurrent exiftool write (`provenance_lock_path()`). A **separate** file, never the image
  itself: exiftool replaces the image by rename, so a lock held on the old inode would exclude
  nothing from the second writer onwards.
- `_data/provenance/args/<operation id>/` — the exiftool argfiles of one write-back operation
  (`provenance_operation_dir()`), removed whole in a `finally`, so a crashed run leaves at most
  one directory behind instead of orphan files nobody can attribute.

Both are created on demand and are safe to delete when nothing is writing. They are covered by
the root `.gitignore`'s `_data` entry, so nothing there is ever committed. Note that the
`_original` sidecars exiftool leaves next to a written image are **not** here — they sit beside
the image in `upload/` or `galleries/` and are the only copy of the pre-write bytes.

### Testing

General test-design, layering, and quality-gate rules (stack-independent) live in
`.claude/rules/testing.md`, `test-design.md`, `mutation-testing.md`, `e2e-tests.md`,
`backpressure.md`, and `precommit-hooks.md` — this section covers only what's
Piwigo/typetags-specific.

`docs/agents/TESTING.md` is the project-facing record: the technique legend, the deliberate
non-coverage table, the unit suite's mutant table, and the hand-check ledger of what has no
oracle. Check it before adding a test — an omission there may be a recorded decision rather
than a gap.

Piwigo core has no test suite. Both plugins carry a PHPUnit suite and a Playwright suite of their own (`plugins/typetags/`, `plugins/provenance/`). The typetags commands:

```bash
# Unit — pure functions, no DDEV, no DB, no HTTP
ddev exec plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml

# Integration — needs DDEV up; hits ws.php over curl and MariaDB directly
ddev exec bash -c 'TYPETAGS_TEST_USERNAME=<user> TYPETAGS_TEST_PASSWORD=<pass> \
  plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'

# E2E — needs DDEV up; drives Chromium in the container against http://localhost/
ddev exec bash -c 'cd plugins/typetags && TYPETAGS_TEST_USERNAME=<user> TYPETAGS_TEST_PASSWORD=<pass> \
  npx playwright test'
```

Typetags login credentials come from `TYPETAGS_TEST_USERNAME` / `TYPETAGS_TEST_PASSWORD` — deliberately not hardcoded; `tests/Support/Config.php` and `tests/e2e/auth.setup.js` each fail fast naming the missing variable. Everything else defaults to DDEV values. A fresh clone needs `ddev exec composer install -d plugins/typetags` and `ddev exec bash -c 'cd plugins/typetags && npm install'` first, and the same two for `plugins/provenance`.

The provenance suite does not take a human's login. It creates its own accounts — see
*Test accounts* in `.claude/rules/testing.md`:

```bash
# once per install (also rotates the passwords)
ddev exec php plugins/provenance/tests/Support/create-test-users.php

ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'

# E2E - drives Chromium in the container against the admin album screen
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  cd plugins/provenance && npx playwright test'
```

Both plugins share one pinned browser cache: `PLAYWRIGHT_BROWSERS_PATH` in `.ddev/config.yaml`
points at `plugins/typetags/.playwright-browsers`, so a fresh clone installs browsers once.

That script writes the git-ignored `local/config/provenance-test.env` and creates
`provenance_webmaster` and `provenance_normal`. It writes users directly and is never safe
against a production database.

Both the integration and the E2E suite mutate the database and restore it (`tests/Support/FixtureBuilder.php`; the E2E suite reaches the same builder through `tests/e2e/support/seed.php`, which persists the original state to the git-ignored `tests/e2e/.state/snapshot.json` so a later process can put it back). Neither is safe against a production install.

Two provenance E2E scenarios seed throwaway albums of their own rather than touching real scans:

- `seed.php --scenario=writeback` — one album of copied photos. The write-back writes **every**
  photo of the album it is started from, so it is never pointed at an album holding real scans.
- `seed.php --scenario=move` — a source and a destination album with one copied photo, for the
  Batch Manager move prompt. A move rearranges the gallery, so it never moves a real scan.

`--restore` deletes those albums, their photo rows, the copied files and exiftool's `_original`
sidecars. `seed.php --read-photo=<id>` reads one photo's provenance columns back, for outcomes
the browser cannot show.

E2E layout: `playwright.config.js` sits at the submodule root so the command above needs no `--config`, with `testDir: './tests/e2e'`. Every locator lives in a page object under `tests/e2e/support/` (`PicturePage.js`, `AlbumPropertiesPage.js`, `PhotoPropertiesPage.js`, `BatchManagerPage.js`) — specs orchestrate and assert, and a locator in a spec file is a bug. `retries: 0`, `workers: 1`: a flaky test gets fixed, never retried into green.

**Clear `_data/templates_c/` after editing a Smarty prefilter.** `Template::set_prefilter()` hashes only the filter's *callback name* into Smarty's `compile_id` (`include/template.class.php:1060-1070`), not the callback's source. Editing `typetags_picture_prefilter()` therefore leaves the previously compiled `picture.tpl` in place, and the page — and the integration suite reading it — keeps showing the old injection with no error.

Browser-level verification is done with `uvx rodney` (drive Chrome) and `uvx showboat` (report). The Chrome profile lands in the git-ignored `.rodney/`.

### No lint, no CI

Piwigo core has no `composer.json`, no `package.json`, no `.github/`, no CI pipeline, and no linter or static-analysis config (no PHP_CodeSniffer, PHPStan, or Psalm). The plugins are the exception: `plugins/typetags` and `plugins/provenance` each carry their own `composer.json` (PHPUnit) and `package.json` (Playwright), all dev-only, with `vendor/` and `node_modules/` git-ignored per plugin.

The mechanical checks available are:

```bash
php -l <file>                  # syntax check; use ddev exec php -l for 8.4 parity
ddev exec plugins/typetags/vendor/bin/phpunit --configuration plugins/typetags/phpunit.xml
bash tools/test-hooks.sh       # self-test for the commit gate below
```

Everything else is manual or browser-driven. Don't claim a lint or test pass that no command produced — say which of the above actually ran.

### Commit gate

`.githooks/pre-commit` is version-controlled and installed with `bash tools/install-hooks.sh`, which sets `core.hooksPath` on **both** the superproject and `plugins/typetags` — a superproject `core.hooksPath` does not apply to submodule commits, and every plugin commit is one. Run it after a fresh clone.

It runs `php -l` on staged PHP, blocks a newly *added* `|| true` in a staged test file (added lines only, so pre-existing code is grandfathered), and runs every plugin's unit suite. If DDEV is down the suites are skipped with a printed warning rather than a silent pass. `git commit --no-verify` bypasses it.

`.githooks/lib.sh` holds the three shared constants (test-path pattern, vacuous-assertion pattern, `UNIT_SUITES` — one command per gated plugin) so the hook and its self-test cannot drift apart.

## Architecture

Procedural PHP built around request-scoped globals: `$conf` (config), `$page` (per-request state), `$user`, `$lang`, `$template`. No MVC, no DI, no autoloader.

### Request lifecycle

1. Entry script defines `PHPWG_ROOT_PATH` and includes `include/common.inc.php` — loads `include/config_default.inc.php`, then `local/config/config.inc.php`, connects the DB, defines table constants, boots `Template`, session and `$user`, loads plugins.
2. `include/section_init.inc.php` parses the clean URL (`index.php?/category/12-foo/start-24`) into `$page['section']`, `$page['items']`, `$page['start']`.
3. Page-specific includes (`category_default.inc.php`, `menubar.inc.php`, …) fill `$page` and assign Smarty variables.
4. `include/page_header.php` → `$template->pparse('index')` → `include/page_tail.php`.

### Entry points

| File                 | Purpose                              |
|----------------------|--------------------------------------|
| `index.php`          | Public gallery browsing              |
| `picture.php`        | Single photo view                    |
| `admin.php`          | Admin panel dispatcher               |
| `ws.php`             | REST API endpoint                    |
| `i.php`              | Image derivative (thumbnail) serving |
| `install.php`        | Installation wizard                  |
| `identification.php` | Login/authentication                 |

### Admin routing

`admin.php?page=X` sets `$page['page']` and includes `admin/X.php` (`admin.php:407`). A new admin screen = one file in `admin/` plus a template under `admin/themes/`.

### Web services

`ws.php` bootstraps via `include/ws_init.inc.php` and registers methods with `$service->addMethod()`; implementations live in `include/ws_functions/pwg.*.php`. Both JSON and XML output are supported.

Two constraints that bite when adding methods:
- `ws.php` does **not** include `admin/include/functions.php`, so WS handlers cannot call admin helpers — use core functions or direct SQL.
- The `admin_only` option in the method's options array gates access; omitting it lets any authenticated user call it. Guest checks go through `is_a_guest()`.

### Database layer

- `include/dblayer/functions_mysqli.inc.php` — use `pwg_query()`, `pwg_db_fetch_assoc()`, `pwg_db_num_rows()`, `pwg_db_real_escape_string()`; never raw `mysqli_*`.
- Table names are constants defined in `include/constants.php` from `$prefixeTable` (`IMAGES_TABLE`, `IMAGE_TAG_TABLE`, `TAGS_TABLE`, …).
- Schema migrations: numbered PHP files in `install/db/`.

### Plugin system

- A plugin is `plugins/<name>/main.inc.php`. Metadata (`Plugin Name`, `Version`, `Description`, `Has Settings`) is a header comment block parsed out of the first 2048 bytes by `admin/include/plugins.class.php` — there is no `plugin.xml`. Lifecycle hooks go in a `PluginMaintain` subclass in `maintain.class.php` (`install()`, `activate()`, `deactivate()`, `uninstall()`, `update()`).
- Hooks: `add_event_handler($event, $callback, $priority, $include_path)` with default priority `EVENT_HANDLER_PRIORITY_NEUTRAL` (50). Core fires `trigger_notify($event)` for notifications and `trigger_change($event, $data)` for filters that return modified data (`include/functions_plugins.inc.php`).
- `tools/triggers_list.php` is a reference catalogue of core events with their signatures and originating files.
- Plugins alter core templates with Smarty prefilters rather than editing `.tpl` files.

### Themes

- `PHPWG_DEFAULT_TEMPLATE` is `modus`. `themes/modus/` is the active theme and declares `'parent' => 'default'`, so `themes/default/template/*.tpl` holds the shared templates modus inherits and selectively overrides. Editing a core page template usually means `themes/default/template/`.
- Per-install CSS overrides: `local/css/rules.css` and `local/css/<theme-id>-rules.css` are appended automatically (`include/template.class.php:1155`).

### Code style

Core is 2002-era PHP and consistent about it. Match it rather than modernising:

- Two-space indent, Allman braces (opening brace on its own line), including for `if`/`foreach` bodies
- Long `array()` syntax, not `[]`
- `and` / `or` rather than `&&` / `||` in conditions
- Every file opens with the `// +---…---+` Piwigo license banner
- Functions carry a phpdoc block with `@param` / `@return`

Most `admin/*.php` page files (52 of 62) open with a direct-request guard, then pull in admin helpers and check access:

```php
if( !defined("PHPWG_ROOT_PATH") )
{
  die ("Hacking attempt!");
}

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
check_status(ACCESS_ADMINISTRATOR);
```

The `include/functions_*.inc.php` library files do not use that guard.

### Internationalization

`l10n('key')` resolves against `language/<locale>/*.lang.php` (75+ locales). `local/language/` overrides core strings. The local dev install runs in German, so page titles in browser test output are German.

### Image derivatives

Generated on demand by `i.php`, cached in `_data/i/`. Size definitions and defaults are in `include/derivative_std_params.inc.php` (`IMG_SQUARE` … `IMG_4XLARGE`).

### Git-ignored working state

`.gitignore` excludes `plugins/*`, `themes/*`, `local/*`, `_data`, `upload`, `galleries/*`, then re-includes the tracked ones with `!` (`themes/default`, `themes/modus`, `themes/standard_pages`, `plugins/typetags`, `plugins/provenance`). A newly tracked theme or plugin needs its own `!` entry or it stays invisible to git.

`local/config/config.inc.php` and `local/config/database.inc.php` are git-ignored and hold the install's overrides and DB credentials.

`.rodney/` (Chrome profile) and `.agent-tests/` (browser verification output) are ignored as local agent working state.

`.ddev/` carries its own DDEV-generated `.ddev/.gitignore` that excludes everything generated, so only `.ddev/config.yaml` is tracked. Don't add blanket `.ddev` rules to the root `.gitignore` — that file manages itself.

## Agent working conventions

- Research notes: `docs/agents/research/YYYY-MM-DD-topic.md`
- Implementation plans: `docs/agents/plans/YYYY-MM-DD-topic.md`
- Both carry YAML frontmatter: `date`, `git_commit`, `branch`, `topic`, `tags`, `status`
- Decisions: `docs/agents/decisions/NNNN-slug.md`, one per file, numbered. A decision *not* to fix something is as worth recording as a fix — cite the file instead of re-litigating it. A decision that later changes gets a new file superseding the old, never an edit that erases what was decided
- Browser verification reports and screenshots: `.agent-tests/YYYY-MM-DD-topic/` — git-ignored, local only. Write them there for the current task, but don't expect earlier runs to exist in a fresh clone

## Security Patterns

- CSRF protection via `pwg_token` — `get_pwg_token()` / `check_pwg_token()`; pass the token into JS through a template variable
- Input validation: `check_input_parameter()` for request parameters
- SQL: `pwg_db_real_escape_string()` for user input in queries
- Admin pages check `is_admin()` or `is_webmaster()`

## Commit Convention

Prefix commits that relate to a GitHub issue with `issue #NNN` or `fixes #NNN` (auto-links). Fork-local work with no upstream issue uses a plain imperative subject — see `add Colored Tags plugin as git submodule`, `fix CSS not loading: add missing modus theme`.

Branches: `fix/<topic>` off `master`, matching what's already in the repo. This overrides the generic `development`-trunk default in the user-level CLAUDE.md.
