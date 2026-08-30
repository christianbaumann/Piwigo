# Piwigo Architecture

Read before editing Piwigo core, a theme, or a plugin's integration points: the request
lifecycle, entry points, admin routing, web services, the database layer, the plugin system,
themes, i18n, derivatives, core code style, and the security patterns core expects.

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

## Security patterns

- CSRF protection via `pwg_token` — `get_pwg_token()` / `check_pwg_token()`; pass the token into JS through a template variable
- Input validation: `check_input_parameter()` for request parameters
- SQL: `pwg_db_real_escape_string()` for user input in queries
- Admin pages check `is_admin()` or `is_webmaster()`
