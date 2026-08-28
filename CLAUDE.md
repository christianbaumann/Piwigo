# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Piwigo — open-source photo gallery web application. Procedural PHP, Smarty templates, MySQL/MariaDB.

- Version: `PHPWG_VERSION` in `include/constants.php` (currently `17.0.0beta1`)
- Upstream supports PHP 7.4+; this checkout runs PHP 8.4 locally
- This is a fork of Piwigo. It vendors the Colored Tags plugin (`plugins/typetags`) as a git submodule pointing at `github.com/christianbaumann/Piwigo-Colored-Tags`

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

No build step — no composer.json, no package.json. PHP is served directly from the repo root.

### Caches

Smarty compiles templates into `_data/templates_c/` and concatenates CSS/JS into `_data/combined/`.
`$conf['template_compile_check']` defaults to true, so `.tpl` edits normally recompile on their own — but stale combined assets are the usual cause of "my CSS/JS change didn't show up":

```bash
rm -rf _data/templates_c/* _data/combined/*
```

### Testing

General test-design, layering, and quality-gate rules (stack-independent) live in
`.claude/rules/testing.md`, `test-design.md`, `mutation-testing.md`, `e2e-tests.md`,
`backpressure.md`, and `precommit-hooks.md` — this section covers only what's
Piwigo/typetags-specific.

Piwigo core has no test suite. The typetags plugin carries a PHPUnit suite in `plugins/typetags/`:

```bash
# Unit — pure functions, no DDEV, no DB, no HTTP
ddev exec plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml

# Integration — needs DDEV up; hits ws.php over curl and MariaDB directly
ddev exec bash -c 'TYPETAGS_TEST_USERNAME=<user> TYPETAGS_TEST_PASSWORD=<pass> \
  plugins/typetags/vendor/bin/phpunit --testsuite integration --configuration plugins/typetags/phpunit.xml'
```

Login credentials come from `TYPETAGS_TEST_USERNAME` / `TYPETAGS_TEST_PASSWORD` — deliberately not hardcoded; `tests/Support/Config.php` fails fast naming the missing variable. Everything else defaults to DDEV values. A fresh clone needs `ddev exec composer install -d plugins/typetags` first.

The integration suite mutates the database and restores it (`tests/Support/FixtureBuilder.php`). It is not safe against a production install.

**Clear `_data/templates_c/` after editing a Smarty prefilter.** `Template::set_prefilter()` hashes only the filter's *callback name* into Smarty's `compile_id` (`include/template.class.php:1060-1070`), not the callback's source. Editing `typetags_picture_prefilter()` therefore leaves the previously compiled `picture.tpl` in place, and the page — and the integration suite reading it — keeps showing the old injection with no error.

Browser-level verification is done with `uvx rodney` (drive Chrome) and `uvx showboat` (report). The Chrome profile lands in the git-ignored `.rodney/`.

### No lint, no CI

Piwigo core has no `composer.json`, no `package.json`, no `.github/`, no CI pipeline, and no linter or static-analysis config (no PHP_CodeSniffer, PHPStan, or Psalm). `plugins/typetags` is the exception: it carries its own `composer.json` (PHPUnit) and `package.json` (Playwright), both dev-only, with `vendor/` and `node_modules/` git-ignored inside the submodule.

The mechanical checks available are:

```bash
php -l <file>                  # syntax check; use ddev exec php -l for 8.4 parity
ddev exec plugins/typetags/vendor/bin/phpunit --configuration plugins/typetags/phpunit.xml
```

Everything else is manual or browser-driven. Don't claim a lint or test pass that no command produced — say which of the above actually ran.

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

`.gitignore` excludes `plugins/*`, `themes/*`, `local/*`, `_data`, `upload`, `galleries/*`, then re-includes the tracked ones with `!` (`themes/default`, `themes/modus`, `themes/standard_pages`, `plugins/typetags`). A newly tracked theme or plugin needs its own `!` entry or it stays invisible to git.

`local/config/config.inc.php` and `local/config/database.inc.php` are git-ignored and hold the install's overrides and DB credentials.

`.rodney/` (Chrome profile) and `.agent-tests/` (browser verification output) are ignored as local agent working state.

`.ddev/` carries its own DDEV-generated `.ddev/.gitignore` that excludes everything generated, so only `.ddev/config.yaml` is tracked. Don't add blanket `.ddev` rules to the root `.gitignore` — that file manages itself.

## Agent working conventions

- Research notes: `docs/agents/research/YYYY-MM-DD-topic.md`
- Implementation plans: `docs/agents/plans/YYYY-MM-DD-topic.md`
- Both carry YAML frontmatter: `date`, `git_commit`, `branch`, `topic`, `tags`, `status`
- Browser verification reports and screenshots: `.agent-tests/YYYY-MM-DD-topic/` — git-ignored, local only. Write them there for the current task, but don't expect earlier runs to exist in a fresh clone

## Security Patterns

- CSRF protection via `pwg_token` — `get_pwg_token()` / `check_pwg_token()`; pass the token into JS through a template variable
- Input validation: `check_input_parameter()` for request parameters
- SQL: `pwg_db_real_escape_string()` for user input in queries
- Admin pages check `is_admin()` or `is_webmaster()`

## Commit Convention

Prefix commits that relate to a GitHub issue with `issue #NNN` or `fixes #NNN` (auto-links). Fork-local work with no upstream issue uses a plain imperative subject — see `add Colored Tags plugin as git submodule`, `fix CSS not loading: add missing modus theme`.

Branches: `fix/<topic>` off `master`, matching what's already in the repo. This overrides the generic `development`-trunk default in the user-level CLAUDE.md.
