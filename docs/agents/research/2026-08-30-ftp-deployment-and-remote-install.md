---
date: 2026-08-30T18:55:26+00:00
git_commit: 62bbd256f3b86f1dc40807a837bd21ca7880d05f
branch: feat/provenance-metadata
topic: "FTP deployment script and first-run remote install of this Piwigo instance"
tags: [research, codebase, deployment, ftp, install, hosting, all-inkl]
status: complete
---

# Research: FTP deployment script and first-run remote install

## Research Question

> Creating a script (python?) that uploads this piwigo instance to webspace; only new/changed and
> needed files shall be uploaded. ftp & mysql credentials will be provided in a json file (create
> structure/demo file), that will be given as a parameter to the script. After first upload,
> instance needs to be setup/installed.

Research only — this document maps what exists today. It contains no plan and no recommendations.
The two **Decisions** sections record answers given by the repository owner on 2026-08-30; they are
scope choices, not findings.

⚠ **Scope, decided 2026-08-30: the remote instance is a toy/sandbox.** The production instance is a
separate, later decision. Several answers below are cheap only because remote data is disposable —
they are not a production posture. See decision 9.

## Summary

Six findings shape any such script:

1. **Nothing like it exists in this repo.** No Python at all, no FTP client, no rsync, no
   `mysqldump`, no CI, no deployment plan, decision, or backlog item. The only remote tooling is
   Perl and drives the *web API of an already-installed* gallery over HTTP.
2. **The install has no CLI mode**, but it is a single unguarded `isset($_POST['install'])` branch
   in `install.php` — no token, no nonce, no captcha. Eleven form fields POSTed over HTTP complete
   a full install. `install.php` writes `local/config/database.inc.php` itself.
3. **"Already installed" is decided by exactly one thing**: whether `local/config/database.inc.php`
   defines `PHPWG_INSTALLED` (`install.php:156-165`). Nothing in the DB is consulted.
4. **The install is fully portable.** No absolute path and no URL is stored anywhere in the
   database — measured, 0 rows. Every stored path is relative (`./galleries/...`), every URL is
   computed per request from `$_SERVER`, and `PHPWG_ROOT_PATH` is a literal `'./'` in each entry
   script. A move needs no DB rewrite.
5. **"Needed files" is a much smaller set than the working copy.** 1.5 GB on disk excluding
   `.git`; 3321 git-tracked entries; roughly **138 MB** of tracked payload, of which 87 MB is the
   `galleries/` recovered scans. The ~1.17 GB difference is dev artifacts (Playwright browsers,
   `vendor/`, `node_modules/`, test-results) already excluded by each plugin's own `.gitignore`.
6. **The production host is already researched and named**: ALL-INKL PrivatPlus, no SSH — which is
   why FTP is the transport. Two capabilities the fork-local plugins depend on (`exec()` and
   Imagick) are recorded as unresolved on that host.

## Detailed Findings

### 1. What exists today for deployment: nothing

Searched for `ftp|rsync|sftp|scp|mysqldump|deploy|release|curl upload` across the repo.

- **Zero Python files.** `find . -name '*.py'` (excluding `.git`, `node_modules`, `vendor`) returns
  nothing. No `requirements.txt`, `pyproject.toml`, `setup.py`.
- **No FTP client, no rsync, no sftp, no scp, no mysqldump anywhere.** `admin/photos_add_ftp.php`
  is a 33-line template-only page that renders help text and performs no FTP.
- **No CI, no root build tooling**: no `.github/`, no root `composer.json`/`package.json`/
  `Dockerfile`/`Makefile`.
- **`docs/` contains no deployment plan, decision, or backlog item.** 19 decisions (0001–0019),
  5 plans, 6 research docs — none about deployment, hosting, or a production install.
  `docs/backlog.md` has no deployment entry.

Python tooling available on the host: `python3` 3.11.9 via pyenv, plus `uv`/`uvx` at
`~/.local/bin` (already used for `uvx rodney` / `uvx showboat`). The DDEV web container has
Python 3.13.5 but **no `pip3` on PATH**. Stdlib `ftplib` (and `ftplib.FTP_TLS`) imports fine;
`paramiko` is not installed.

### 2. The existing remote tooling drives the API, not the filesystem

| Script | What it actually does |
|---|---|
| `tools/remote_sync.pl` | Logs in via `pwg.session.login`, then POSTs a form to `admin.php?page=site_update&site=1` (`:42-56`) to trigger a filesystem→DB sync. Site id hardcoded to 1. **Transfers nothing.** |
| `tools/piwigo_upload.pl` | Chunked single-photo upload via `pwg.images.upload` (`:88`), chunk size 500 000 (`:32-34`). |
| `tools/piwigo_remote.pl` | Action dispatcher over `ws.php` — `pwg.images.add`, `addChunk`, `addFile`, `tags.*`, `categories.*`. `send_chunks()` at `:377-421` base64-encodes chunks. |
| `tools/piwigo_addSimple.pl` | 36-line demo, everything hardcoded. |

`tools/pwg_rel_create.sh` is the upstream release packager. **It has no exclusion list** — it is
purely additive, cloning upstream repos fresh into `/tmp` and deleting `.git` (`:45`, `:75`,
`:102`). It always clones **upstream `Piwigo/Piwigo`**, never this fork or its fork-local plugins.
Its one directly reusable fact is the permission set it applies (`:133-140`):

```bash
chmod -R g-w piwigo
chmod -R a+w piwigo/local
chmod a+w piwigo/_data
chmod a+w piwigo/upload
chmod a+w piwigo/plugins
chmod a+w piwigo/themes
```

and that it creates `upload/` and `_data/` as empty directories before zipping (`:123-127`).

### 3. The install flow (`install.php`, 556 lines)

**Already-installed detection** — `install.php:156-165`, the only marker:

```php
$config_file = PHPWG_ROOT_PATH.PWG_LOCAL_DIR .'config/database.inc.php';
if (@file_exists($config_file))
{
  include($config_file);
  if (defined("PHPWG_INSTALLED"))
  {
    die('Piwigo is already installed');
  }
}
```

The mirror is `include/common.inc.php:84-88`: if that constant is *not* defined after including the
file, every normal page redirects to `install.php`.

**Accepted parameters.** GET: `dl` (32-hex, config download), `language`. POST (form at
`admin/themes/default/template/install.tpl:203-295`): `dbhost`, `dbuser`, `dbpasswd`, `dbname`,
`prefix`, `admin_name`, `admin_pass1`, `admin_pass2`, `admin_mail`, `newsletter_subscribe`
(checkbox), `send_credentials_by_mail` (checkbox), `install` (the trigger).

**There is no unattended/CLI mode.** No script reads `$argv` or branches on `PHP_SAPI` for
installation; `install.php` and `upgrade.php` both instantiate a `Template`, emit headers
(`install.php:238`) and end in `$template->pparse()`. The only headless path is POSTing the form
over HTTP — the branch is gated solely on `isset($_POST['install'])`, with no CSRF token.

**Validation order** (`install.php:258-302`): `install_db_connect()` → `pwg_db_check_charset()` →
prefix (≤20 chars, no leading digit, `/^[a-zA-Z0-9_$]*$/u`) → webmaster login (non-empty, no `'`
or `"`) → passwords match and non-empty → mail via `validate_mail_address()`.

**On success** (`install.php:307-431`), in order:

1. Writes `local/config/database.inc.php` (§4).
2. `execute_sqlfile('install/piwigo_structure-mysql.sql', ...)` — 34 `CREATE TABLE`s.
3. `execute_sqlfile('install/config.sql', ...)` — 75 config rows.
4. `INSERT ... ('secret_key', sha1(random_bytes(1000)))` (`:361-365`).
5. `conf_update_param('piwigo_db_version', '17')`, `gallery_title`, `page_banner`.
6. Activates the chosen language.
7. `activate_core_themes()` activates **only `modus`**; `activate_core_plugins()` iterates an
   **empty array** and activates nothing (`admin/include/functions_install.inc.php:62-91`).
8. `piwigo_sites` row 1: `galleries_url = './galleries/'`.
9. Users 1 (webmaster, `md5($admin_pass1)`) and 2 (`guest`); `create_user_infos()` assigns status
   from `webmaster_id`/`guest_id`. `pwg_password_verify()`
   (`include/functions_user.inc.php:1181-1213`) accepts md5 and silently rehashes to phpass on
   first login.
10. Marks **every** id from `get_available_upgrade_ids()` as applied, so `upgrade.php` finds
    nothing to do.

**Post-install** (`:463-543`): auto-logs in as user 1, and — only when `newsletter_subscribe` is
posted — makes an outbound `fetchRemote()` call. `$is_newsletter_subscribe` is set from
`isset($_POST['newsletter_subscribe'])` whenever `install` is posted (`:147-151`), so simply not
sending the field suppresses the outbound request.

**Fallback if `local/config/` is not writable** (`install.php:325-342`): the config content is
written to `_data/pwg_<md5>` and offered for download at `install.php?dl=<md5>` instead. Note
`:343-344` then unconditionally `@fputs()` on the possibly-false handle.

### 4. `local/config/database.inc.php` — the only environment-coupled file

Written verbatim by `install.php:307-321`. This checkout's copy is mode `0600`, git-ignored,
15 lines:

| Key | Local value |
|---|---|
| `$conf['dblayer']` | `mysqli` |
| `$conf['db_base']` | `db` |
| `$conf['db_user']` | `db` |
| `$conf['db_password']` | (present) |
| `$conf['db_host']` | `db` — a Docker service name |
| `$prefixeTable` | `piwigo_` |
| `PHPWG_INSTALLED`, `PWG_CHARSET`, `DB_CHARSET`, `DB_COLLATE` | `true`, `utf-8`, `utf8`, `''` |

There is no `db_port` or socket key. `$prefixeTable` drives all 39 `*_TABLE` constants
(`include/constants.php:43-110`).

`$conf['db_password']` has a second, non-obvious use: `include/common.inc.php:141-144` salts the
log filename with `sha1(date . $conf['db_password'])`, deliberately rather than with `secret_key`,
so `i.php` can write the log without a DB-loaded value.

`local/config/config.inc.php` currently holds exactly one line: `$conf['assume_https'] = true;`.
Grepping the codebase for `assume_https` returns **zero** hits — no core file, plugin, or theme
reads it.

### 5. Nothing in the database is environment-coupled

Measured on the live install, 2026-08-30:

```sql
SELECT param, LEFT(value,80) FROM piwigo_config
 WHERE value LIKE '/%' OR value LIKE '%http%' OR value LIKE '%/var/%'
    OR value LIKE '%ddev%' OR value LIKE '%Users%';
-- 0 rows
```

- `piwigo_config` has **94 rows**, none containing a path or URL. There is no `gallery_url`,
  `galleries_url`, `upload_dir` or `data_location` row — those exist only as defaults in
  `include/config_default.inc.php` (`:667`, `:901`, `:896`, `:907`).
- `piwigo_sites` — 1 row: `galleries_url = './galleries/'`, relative.
- `piwigo_images.path` — relative with a `./` prefix. Distribution over all 106 rows:
  `./galleries` ×105, `./upload` ×1. No row contains a host path.
- `piwigo_categories.dir` holds a single path *segment* (`1992_Rund_um_Sefferweich`), not a path;
  `global_rank` is an ordering string unrelated to the filesystem.
- `PHPWG_ROOT_PATH` is a literal `'./'` defined in each of the 22 entry scripts
  (`index.php:10`, `admin.php:13`, `i.php:9`, `install.php:10`, …). Correctness depends on process
  CWD, not on a stored value.
- `get_absolute_root_url()` (`include/functions_url.inc.php:33-92`) is computed entirely from the
  request: `HTTP_X_FORWARDED_PROTO`/`HTTPS` for scheme, `HTTP_X_FORWARDED_HOST`/`HTTP_HOST` for
  host, `$conf['url_port']` (default `'none'`) for port, `cookie_path()` for suffix.

**Database size**: 38 tables, `data_length + index_length` = **2 931 969 bytes (2.80 MB)**.
`piwigo_activity` alone is 87.4 % of it (18 746 rows, 2.37 MB). 18 tables are empty.

Environment-specific *content* that does exist in the DB:

- `secret_key` (40-hex SHA-1) and `send_piwigo_infos_origin_hash` (= `sha1(secret_key .
  get_absolute_root_url())`, `admin/include/updates.class.php:89` — URL-derived, so it goes stale
  on a move).
- `provenance_throwaway_install = 1` and `persons_throwaway_install = 1` — dev-only markers;
  `FixtureBuilder` refuses to run without the latter.
- 6 of the 8 `piwigo_users` rows are test scaffolding (`provenance_webmaster`, `typetags_normal`,
  `persons_webmaster`, …), created by the per-plugin `create-test-users.php`.
- One leftover virtual album, id 1851 `Persons admin fixture`.
- Timestamped rows: `fs_quick_check_last_check`, `update_notify_last_check`,
  `send_piwigo_infos_last_notice`, `c13y_ignore` (pinned to `17.0.0beta1`).

`secret_key` is written **once**, at `install.php:361-365`, and again only by schema upgrade 174
(`install/db/174-database.php:16`). There is no rotation command.

**Activation state** that a fresh remote install would not have: `piwigo_plugins` holds 3 active
rows (`typetags` version `auto`, `provenance` 1.0.0, `persons` 1.0.0); `piwigo_themes` holds
`modus` 17.0.0; `piwigo_languages` holds `de_DE`. A fresh `install.php` run activates only `modus`
and the chosen language — no plugins.

### 6. What is on disk, and what of it is "needed"

Measured 2026-08-30 (`du -sh`, `find | wc -l`, `git ls-files`).

| Path | Size | Note |
|---|---|---|
| `plugins/` | 1.0 G | almost entirely dev artifacts |
| `.git/` | 201 M | |
| `galleries/` | **87 M** | 106 tracked files, 105 PNG — the recovered scans |
| `_temp/` | 87 M | git-ignored staging area for that recovery |
| `_data/` | 61 M | git-ignored runtime cache |
| `themes/` | 20 M | `default` 6.0M, `modus` 13M, `standard_pages` 1.3M |
| `admin/` | 13 M | |
| `language/` | 12 M | 75 locales, 1292 tracked files |
| `upload/` | 12 M | 12.7 MB of it is one persons test fixture |
| `include/` | 3.2 M | |
| `install/` | 716 K | |

Total 1.7 G; ~1.5 G excluding `.git`. **Tracked payload on disk: ~138 MB**, essentially
`galleries/` 87M + `themes/` 20M + `admin/` 13M + `language/` 12M + core.

**3321 git-tracked entries**, by top-level: `language` 1292, `themes` 840, `admin` 404,
`include` 281, `install` 152, `plugins` 126, `galleries` 106, `docs` 35, root 30, `tools` 23.

The 1.17 GB of dev artifacts, all ignored by each plugin's own identical `.gitignore`
(`/vendor/ /node_modules/ /test-results/ /playwright-report/ /.playwright-browsers/
/.phpunit.result.cache /tests/e2e/.state/`):

| Item | Size |
|---|---|
| `plugins/typetags/.playwright-browsers/` | 983 M |
| `test-results/` (×2 plugins) | 55 M |
| `node_modules/` (×3) | 54 M |
| `playwright-report/` (×3) | 45.5 M |
| `vendor/` (×3) | 36 M |

Note `tests/` itself is **not** ignored — provenance (51 files) and persons (20 files) commit their
suites, as do the dev config files (`phpunit.xml`, `composer.json`, `package.json`,
`playwright.config.js`, lockfiles). Tracked *non-test* plugin files are small: provenance 33 files,
persons 20 files, typetags 131 files / 680 K.

`plugins/typetags` is a **git submodule** (gitlink mode 160000, commit `44fdd062`, url
`github.com/christianbaumann/Piwigo-Colored-Tags.git`), so `git ls-files` on the superproject
reports it as one entry, not 157 files.

**`local/`** — 9 files, 36 K. Only the four `index.php` guards are tracked. Ignored:
`config/config.inc.php`, `config/database.inc.php`, and three `*-test.env` credential files
(`persons-`, `provenance-`, `typetags-`), each holding four `*_WEBMASTER_USERNAME` /
`*_NORMAL_PASSWORD`-shaped keys.

**`.htaccess`**: exactly **one** in the whole working copy — `_data/logs/.htaccess`, 13 bytes,
`deny from all`, itself git-ignored. None at the document root, in `upload/`, `galleries/`,
`include/`, `admin/`, or any plugin.

**Untracked-but-not-ignored right now** (work in progress on this branch):
`docs/agents/decisions/0019-person-region-permission-model.md`,
`plugins/persons/include/ws_functions.inc.php`, and 6 `plugins/persons/tests/Integration/*.php`.

### 7. Runtime requirements and writable directories

From `README.md:9-14` — the only requirements statement in the repo:

> * A webserver (Apache or nginx recommended)
> * PHP 7.4+ …
> * MySQL 5 or greater or MariaDB equivalent
> * ImageMagick (recommended) or PHP GD

And `README.md:16-30` documents the manual path as: unzip, "Transfer everything to your web space
with any FTP client", open the URL.

Enforced in code:

- `include/constants.php:23` — `REQUIRED_PHP_VERSION = '7.4.0'`, checked at `install.php:240-244`
  and `upgrade.php:399-403`. It is a **soft** block — an entry in `$errors`, not a hard stop.
- `include/dblayer/functions_mysqli.inc.php:14` — `REQUIRED_MYSQL_VERSION = '5.0.0'`, checked at
  `:97`.
- **`mysqli` is the one hard extension requirement** (`install.php:122-140`; the `mysql` fallback
  is unreachable on PHP ≥ 7). `exif` is optional and degrades
  (`include/functions_metadata.inc.php:132`). Graphics is one-of-three, auto-selected
  (`admin/include/image.class.php:366-450`, `$conf['graphics_library'] = 'auto'`). Nothing checks
  mbstring, intl, curl, or zip.

**Writable directories.** There is no central list; checks are scattered through `is_writable()`
and `mkgetdir()` (`include/functions.inc.php:108-143`), which `mkdir()`s with
`$conf['chmod_value']` — `0777` under Apache SAPI, `0755` otherwise
(`include/config_default.inc.php:972`).

| Directory | Enforcement |
|---|---|
| `_data/` | `include/template.class.php:80-99` — **the hard gate**; `fatal_error('Give write access (chmod 777) to "%s" directory…')`. Result cached as the `data_dir_checked` config row |
| `_data/templates_c/` | `include/template.class.php:101-102`, die-on-error |
| `_data/combined/`, `_data/i/` | `include/constants.php:19-20`; created at `template.class.php:1959`, `i.php:522` |
| `_data/cache/`, `_data/logs/`, `_data/update/` | `include/cache.class.php:101`, `include/Logger.class.php:128`, `admin/include/updates.class.php:535` |
| `upload/` | `admin/include/functions_upload.inc.php:1075,1085-1094` — tries `@chmod(0777)` then fatals |
| `local/config/` | implicitly, `install.php:325` — failure falls back to the `?dl=` download path |
| `themes/`, `language/` | `admin/themes_new.php:29-33`, `admin/languages_new.php:32-36` |

`plugins/` has **no** writability check, unlike its themes and languages siblings.

**Sessions are stored in the database**, not on the filesystem —
`include/config_default.inc.php:435-437` sets `$conf['session_save_handler'] = 'db'`, and
`include/functions_session.inc.php:28-44` installs `PwgSession`. Table `piwigo_sessions`
(`install/piwigo_structure-mysql.sql:335-341`, MyISAM). No session directory is needed.

**No rewrite rules are required.** `$conf['question_mark_in_urls'] = true` and
`$conf['php_extension_in_urls'] = true` (`config_default.inc.php:672`, `:678`) are the conservative
defaults, producing `index.php?/...` and `i.php?/...` URLs. `install/php5_apache_configuration.php`
and `install/hosting.php` (a 12-provider table of PHP5 `.htaccess` lines) are dead code — the
include is commented out at `install.php:239`.

### 8. Filesystem synchronization on the remote side

`admin/site_update.php`, reached as `admin.php?page=site_update&site=<id>`.

- `:20-23` dies unless `$conf['enable_synchronization']` (default `true`); `:25` requires
  `ACCESS_ADMINISTRATOR`.
- `:60-69` **`fatal_error('remote sites not supported')` if `url_is_remote($site_url)`** — only
  `LocalSiteReader` exists. Sync reads the local filesystem of the server it runs on.
- `:96-108` "Quick sync" (`?quick_sync`) is CSRF-checked via `check_pwg_token()` and presets
  `sync=files`, `sync_meta=1`, `add_to_caddie=1`, `subcats-included=1`, `simulate=0`.
- A `simulate` mode reports without writing (`:120-127`).
- ⚠ **Filenames are validated against `$conf['sync_chars_regex']`, default
  `'/^[a-zA-Z0-9-_.]+$/'`** (`config_default.inc.php:952`) — ASCII alphanumerics, hyphen,
  underscore, dot only. Anything else is reported as `PWG-UPDATE-1 wrong filename`. The four
  tracked album directories and their 105 PNGs all satisfy this.
- This file carries the fork-local `site_update_associate_images` `trigger_notify()`.

`tools/remote_sync.pl` exists precisely to drive this screen over HTTP after files are in place.

### 9. The production host: ALL-INKL PrivatPlus

Already researched at
`docs/agents/research/2026-08-29-per-photo-freetext-field-and-metadata-writeback.md:1996-2273`.
This is the only place the intended production host is named, and it records **no** install or
deploy procedure.

| Feature | Value | Confidence |
|---|---|---|
| **SSH access** | **no** (Premium/Business only) | `[OFFICIAL]` |
| Perl / custom CGI | supported | `[OFFICIAL]` |
| Cronjobs | 25, configured by **entering a URL to fetch**, not a shell command | `[OFFICIAL]` |
| Speicherplatz | 100 GB | `[OFFICIAL]` |
| MySQL databases | 25 | `[OFFICIAL]` |
| `.htaccess` | available | `[OFFICIAL]` |
| PHP version | selectable per (sub)domain in KAS; 5.6 … 8.2 referenced | `[OFFICIAL]` |
| **`exec()` family** | reportedly in `disable_functions` under default mod_php | `[USER-SOURCED]` |
| **Imagick under PHP 8** | reportedly unavailable | `[USER-SOURCED]` |
| exiftool preinstalled | no evidence either way | `[UNKNOWN]` |
| `memory_limit` / `max_execution_time` | not stated; since PHP 8 must use `.user.ini`, not `.htaccess` | `[USER]` |

No SSH is why FTP is the transport. Two consequences already recorded there:

- exiftool is a **Perl script, not a compiled binary** (measured: 8026 lines), and a self-contained
  copy plus its `Image/` module tree run from an ordinary home directory with `PERL5LIB` set. So it
  can be deployed by FTP upload — no package manager, no shell.
- Both fork-local plugins already honour a configurable directory:
  `$conf['persons_exiftool_path']` (`plugins/persons/main.inc.php:40-42`,
  `include/exiftool.inc.php:23-29`) and `$conf['provenance_exiftool_path']`
  (`plugins/provenance/main.inc.php:40-42`), each defaulting to `''` (on PATH). Both gate on
  `function_exists('exec')` **before** calling it, because `disable_functions` makes the call fatal
  rather than false — `plugins/persons/include/exiftool.inc.php:51-60`:

```php
  if (!function_exists('exec'))
  {
    return $available = false;
  }
  $out = array();
  @exec(escapeshellcmd(persons_exiftool_binary()).' -ver', $out);
  return $available = (is_array($out) and !empty($out[0]) and preg_match('/^\d+\.\d+/', $out[0]));
```

`plugins/provenance/include/ws_functions.inc.php:389-393` returns `PwgError(501, 'exiftool is not
available on this server')`, and `events_admin.inc.php:72-74` hides the write-back button. So both
plugins install and run on a host without exiftool; only the write-back is lost.

ImageMagick is **not** used by either plugin at runtime — the `convert`/`identify` calls are in the
integration suites only, as an independent reader.

The prior research also records a ready-made probe script
(`…-writeback.md:2158-2172`) that answers `exec`, `disable_functions`, `imagick`, `exif`, `gd`,
`PHP_VERSION`, `PHP_SAPI`, `memory_limit` and `max_execution_time` in one request, with the note to
give it an unguessable name and delete it afterwards.

### 10. Change detection over FTP — what the protocol and stdlib offer

No code in this repo does this today; recorded here as the constraint surface.

- Python stdlib `ftplib` provides `FTP.size()` (SIZE), `FTP.mlsd()` (MLSD — machine-readable
  listing with `size`, `modify`, `type` facts), `FTP.sendcmd('MDTM …')`, `FTP.nlst()`, and
  `ftplib.FTP_TLS` for FTPS. Whether the server supports MLSD, MDTM and FTPS is per-server and is
  not recorded anywhere in this repo.
- **FTP exposes no checksum.** SIZE and MDTM are the only server-side comparison facts, so
  content-hash comparison requires a manifest — either kept locally, or uploaded alongside the
  files and read back.
- Git offers an independent signal the repo already maintains: `git ls-files` (3321 entries),
  `git ls-files -s` (blob SHA-1 per path), and `git status --porcelain` for the working-tree delta.
  Note `plugins/typetags` is a submodule, so its 157 files are invisible to a superproject
  `git ls-files`.
- The DB is 2.80 MB and the working copy has no `mysqldump` usage; DDEV provides `ddev mysql` and
  DDEV's own export/import commands, none referenced in the repo.

## Code References

- `install.php:156-165` — the sole "already installed" test (`PHPWG_INSTALLED`)
- `install.php:258-433` — the entire install transaction, gated on `isset($_POST['install'])`
- `install.php:307-321` — the literal text of `local/config/database.inc.php`
- `install.php:325-342` — the `_data/pwg_<md5>` + `?dl=` fallback when `local/config/` is unwritable
- `install.php:361-365` — `secret_key` generation
- `install.php:390-394` — `piwigo_sites` row 1, `galleries_url = './galleries/'`
- `install.php:413-431` — marks every `install/db/*` migration as already applied
- `admin/include/functions_install.inc.php:24-57` — `execute_sqlfile()`; skips `DROP TABLE`,
  rewrites the table prefix
- `admin/include/functions_install.inc.php:62-91` — activates only `modus`; activates no plugins
- `include/common.inc.php:78-89` — bootstrap order and the redirect-to-`install.php` guard
- `include/common.inc.php:141-144` — log filename salted with `db_password`
- `include/constants.php:23` — `REQUIRED_PHP_VERSION = '7.4.0'`
- `include/config_default.inc.php:896,901,907,972` — `data_location`, `upload_dir`, `themes_dir`,
  `chmod_value`
- `include/config_default.inc.php:435-437` — `session_save_handler = 'db'`
- `include/config_default.inc.php:672,678` — the URL-style defaults that avoid needing rewrites
- `include/config_default.inc.php:952` — `sync_chars_regex`, ASCII-only filenames
- `include/functions.inc.php:108-143` — `mkgetdir()` and its `.htaccess`/`index.htm` protection
- `include/functions_url.inc.php:33-92` — `get_absolute_root_url()`, fully request-derived
- `include/template.class.php:80-99` — the `_data/` writability gate and `data_dir_checked`
- `admin/site_update.php:60-69` — remote sites unsupported; local filesystem only
- `plugins/persons/include/exiftool.inc.php:42-60` — the `function_exists('exec')`-first probe
- `plugins/provenance/include/ws_functions.inc.php:389-393` — 501 when exiftool is absent
- `tools/pwg_rel_create.sh:123-140` — the empty `upload/`+`_data/` creation and the chmod set
- `tools/remote_sync.pl:42-56` — triggering `site_update` over HTTP
- `docs/agents/research/2026-08-29-per-photo-freetext-field-and-metadata-writeback.md:1996-2273` —
  ALL-INKL PrivatPlus feasibility, the exiftool-by-FTP measurement, and the probe script

## Architecture Documentation

Patterns found that bear on a deployment script:

- **Relative-path discipline throughout.** Every stored path is relative, every URL is computed
  from the request, and `PHPWG_ROOT_PATH` is a per-entry-script `'./'`. Portability is a property
  of the existing design, not something a script must create.
- **File-based install marker, DB-based everything else.** The single `PHPWG_INSTALLED` constant in
  one git-ignored file is what separates "fresh" from "installed".
- **Capability probing with graceful degradation** is the established fork convention, copied from
  `pwg_image::is_ext_imagick()` (`admin/include/image.class.php:393-410`): check
  `function_exists('exec')` first, probe by running `-version` and matching output, keep the
  directory configurable.
- **Guarded, re-entrant plugin `install()`.** Both fork-local `maintain.class.php` files state
  their `install()` can run twice with no error, because Piwigo re-enters it through `update()` on
  every version bump (`plugins/persons/maintain.class.php:17,54`,
  `plugins/provenance/maintain.class.php:15,60`).
- **Per-plugin `.gitignore` as the dev/prod boundary.** The three plugins carry identical ignore
  lists, so git already knows which plugin files are dev artifacts — except `tests/` and the dev
  config files, which are deliberately tracked.
- **No lint, no CI.** The mechanical checks that exist are `php -l`, the three plugin PHPUnit
  suites, and `bash tools/test-hooks.sh` (per `.claude/rules/plugin-test-suites.md`).

## Decisions (answered 2026-08-30)

Answered by the repository owner. These are decisions for the deploy script's scope, not findings.

⚠ **Framing decision (Q9) that conditions all the others: the remote instance is a toy/sandbox.**
The production instance is a separate, later decision. So data loss on the remote is cheap, the
remote DB is disposable, and nothing below should be read as a production posture.

1. **`exec()` / Imagick on the host** → **run the probe script first.** The probe is already written
   (`…-writeback.md:2158-2172`); it answers `exec`, `disable_functions`, `imagick`, `exif`, `gd`,
   `PHP_VERSION`, `PHP_SAPI`, `memory_limit`, `max_execution_time` in one request. Unguessable
   filename, deleted after reading. This gates the exiftool design and costs five minutes.
2. **FTP transport** → **probe with `FEAT` at connect; require FTPS and fail loudly** naming what
   the server is missing. Plain FTP sends credentials in cleartext.
3. **Remote docroot** → **a `remote_root` key in the credentials JSON, default `/`.** Both
   domain-root and subdirectory layouts work with no code branch. Affects `cookie_path()` and the
   FTP base path only; nothing in the DB.
4. **`galleries/` (87 MB)** → **uploaded through the same manifest-driven path as everything else.**
   It is tracked content and does not change, so the manifest matches on every later deploy and it
   costs nothing after the first. No special case.
5. **Dev-only DB state** → **never transfer the local DB.** A fresh `install.php` run remotely means
   the `*_throwaway_install` markers, the six test users, the `Persons admin fixture` album
   (id 1851) and the 18 746 activity rows simply never exist there.
6. **Plugin activation** → **the script activates via `pwg.plugins.performAction`** (session login +
   `pwg_token`), which routes through `activate` → `install` and creates each plugin's schema.
   Inserting `piwigo_plugins` rows directly is rejected: it skips `install()` and leaves no tables.
7. **`plugins/typetags` submodule** → **enumerate with `git ls-files --recurse-submodules`**, so its
   157 files are included rather than the single gitlink.
8. **`local/config/config.inc.php`** → **generated by the script from the JSON** (remote
   `*_exiftool_path`, `assume_https`); the local copy is never uploaded.

---

## Follow-up Research 2026-08-30 — how a local DB change reaches the remote DB

> Question: if local development changes the db, how does this get to the remote/online db?

Answer up front: **it depends entirely on which of three kinds of change it is. Schema propagates
by itself once the files are uploaded; content does not propagate at all.** There is no dump, no
export, and no DB-to-DB mechanism anywhere in this repo — `grep` for `mysqldump` across the tree
returns nothing.

| Kind of change | Path to the remote DB | Automated? |
|---|---|---|
| **A. Core schema** — a new `install/db/NNN-database.php` | upload the file, then hit `upgrade_feed.php` | yes, and unauthenticated |
| **B. Plugin schema** — a column/table in `provenance`, `persons`, `typetags` | bump the `Version:` header; `autoupdate_plugin()` calls `update()` on the next request | **yes** |
| **C. Content/config rows** — albums, photos, tags, config, users | nothing exists; re-created on the remote by sync/API, or transferred by hand | **no** |

### A. Core schema — `install/db/NNN-database.php` → `upgrade_feed.php`

`get_available_upgrade_ids()` (`admin/include/functions_upgrade.php:265-280`) simply lists
`install/db/` and matches `/^(.*?)-database\.php$/`. There are currently **122 entries**
(`61-database.php` … `181-database.php`, plus `index.php`).

`upgrade_feed.php` (101 lines) diffs that list against the `piwigo_upgrade` table and applies the
difference:

```php
$applied  = array_from_query('SELECT id FROM '.PREFIX_TABLE.'upgrade;', 'id');
$existing = get_available_upgrade_ids();
$to_apply = array_diff($existing, $applied);
...
foreach ($to_apply as $upgrade_id)
{
  include(UPGRADES_PATH.'/'.$upgrade_id.'-database.php');
  pwg_query('INSERT INTO '.PREFIX_TABLE.'upgrade (id, applied, description)
    VALUES (\''.$upgrade_id.'\', NOW(), \''.$upgrade_description.'\');');
}
```

⚠ **`upgrade_feed.php` has no authentication of any kind.** Its only gate is
`if (!$conf['check_upgrade_feed']) die("upgrade feed is not active");` (`upgrade_feed.php:33-36`),
and that config defaults to **`true`** (`include/config_default.inc.php:236`). No session check, no
`pwg_token`, no `check_status()`. So `GET https://<site>/upgrade_feed.php` applies every pending
core migration for anyone who requests it. This is upstream behaviour, not fork-local.

The discovery half is in `include/common.inc.php:322-332`: when `check_upgrade_feed` is true, every
page runs `check_upgrade_feed()` and, if anything is pending, renders a header banner linking to
`upgrade_feed.php`. Note the inverse at `:148-154` — the automatic **redirect** to `upgrade.php`
sits inside `if (!$conf['check_upgrade_feed'])`, so on a default install it **never fires**:

```php
if (!$conf['check_upgrade_feed'])
{
  if (!isset($conf['piwigo_db_version']) or $conf['piwigo_db_version'] != get_branch_from_version(PHPWG_VERSION))
  {
    redirect(get_root_url().'upgrade.php');
  }
}
```

This track is irrelevant to ordinary fork work — nothing in this fork adds `install/db/` files. The
fork's own core edits are the two `trigger_notify()` calls, which are code, not schema.

### B. Plugin schema — automatic, driven by the `Version:` header

There are **two** callers of `PluginMaintain::update()`. The automatic one is the path that matters.

#### Path 1 — `autoupdate_plugin()`, on every request

`include/functions_plugins.inc.php:342-352` — `load_plugin()` calls it *before* including
`main.inc.php`:

```php
function load_plugin($plugin)
{
  $file_name = PHPWG_PLUGINS_PATH.$plugin['id'].'/main.inc.php';
  if (file_exists($file_name))
  {
    autoupdate_plugin($plugin);
    global $pwg_loaded_plugins;
    $pwg_loaded_plugins[ $plugin['id'] ] = $plugin;
    include_once($file_name);
  }
}
```

`load_plugins()` (`:432-445`) is called from `include/common.inc.php:159`, so this runs on **every
request through every entry point** (`index.php`, `admin.php`, `ws.php`, `picture.php`, …) for every
plugin whose `piwigo_plugins.state` is `active`. No button, no admin session, no `pwg_token`.

`autoupdate_plugin()` (`:362-427`) scrapes the filesystem version from **lines 2–10** of
`main.inc.php` and compares it against the DB column:

```php
  while (($line = fgets($fh))!==false && $fs_version==null && $i<10)
  {
    $i++;
    if ($i < 2) continue; // first lines are typically "<?php" and "/*"
    if (preg_match('/Version:\s*([\w.-]+)/', $line, $matches))
    {
      $fs_version = $matches[1];
    }
  }
  ...
  if ($fs_version != null && (
        $fs_version == 'auto' || $plugin['version'] == 'auto' ||
        safe_version_compare($plugin['version'], $fs_version, '<')
      )
  ) {
    $old_version = $plugin['version'];
    $new_version = $fs_version;
    $plugin['version'] = $fs_version;
    ...
      $plugin_maintain = new $classname($plugin['id']);
      $plugin_maintain->update($plugin['version'], $fs_version, $page['errors']);
    ...
    if ($new_version != $old_version)
    {
      pwg_query('UPDATE '. PLUGINS_TABLE .' SET version = "'. $plugin['version'] .'" WHERE id = "'. $plugin['id'] .'";');
      pwg_activity('system', ACTIVITY_SYSTEM_PLUGIN, 'autoupdate', ...);
    }
  }
```

**This is the propagation path for a fork-local plugin schema change.** Bump `Version:` in
`main.inc.php`, upload the plugin files, and the next request to the remote site runs
`update()` → `install()`, which is idempotent and additive (below). The `piwigo_plugins.version`
row is then written and an `autoupdate` activity row logged.

Details that matter:

- **The comparison is `safe_version_compare($db, $fs, '<')`**
  (`include/functions.inc.php:2413-2433`), so only an *increase* triggers it. Re-uploading the same
  version does nothing.
- **The version must sit on lines 2–10** of `main.inc.php`. Both fork plugins put it on line 4
  (`plugins/persons/main.inc.php:4`, `plugins/provenance/main.inc.php:4`, both `1.0.0`).
- **`maintain.class.php` must exist** (`:393-396`), or the version row is bumped with no `update()`
  call. All three plugins have one.
- ⚠ **`$old_version` is already the new value.** `$plugin['version']` is overwritten at `:391`
  *before* the call at `:410`, so `update()` receives `($new, $new)`. Harmless here — both fork
  plugins ignore `$old_version` and delegate straight to `install($new_version)` — but a
  version-conditional migration written against that argument would silently never fire.
- ⚠ **`Version: auto` fires the branch on every page load.** `plugins/typetags/main.inc.php:4`
  declares `auto`, so typetags' `update()` → `install()` → `CREATE TABLE IF NOT EXISTS` runs on
  every request. The DB write is guarded by `$new_version != $old_version` (`:415`), so `auto`→`auto`
  writes no row and logs no activity — but the schema work is redone each time.

#### Path 2 — `plugins::perform_action('update', …)`, PEM only

`admin/include/plugins.class.php:156-186` is a **PEM download**, not a local re-run:

```php
case 'update':
  $previous_version = $this->fs_plugins[$plugin_id]['version'];
  $errors[0] = $this->extract_plugin_files('upgrade', $options['revision'], $plugin_id);
  if ($errors[0] === 'ok')
  {
    $this->get_fs_plugin($plugin_id); // refresh plugins list
    $new_version = $this->fs_plugins[$plugin_id]['version'];
    $plugin_maintain = self::build_maintain_class($plugin_id);
    $plugin_maintain->update($previous_version, $new_version, $errors);
    if ($new_version != 'auto') { /* UPDATE piwigo_plugins SET version=... */ }
  }
```

`$options['revision']` is a Piwigo-Extensions revision id, and `:122-125` deliberately skips
building the maintain class for `update` ("wait for files to be updated"). The three fork-local
plugins are not on PEM, so this branch is unreachable for them. From the UI it is driven by
`pwg.extensions.update` (`include/ws_functions/pwg.extensions.php:144-205`), not by
`pwg.plugins.performAction`.

⚠ This contradicts what the repo currently records:
`plugins/persons/tests/Integration/PluginActivationTest.php:208-237` and its provenance equivalent
state that `update()` is reachable "only by downloading and extracting a real extension archive".
That is true of `perform_action`, but `autoupdate_plugin()` is a second, archive-free caller that
fires on every request.

#### The other `perform_action` cases

- **`install`** (`:133-154`) — **breaks early when a `piwigo_plugins` row already exists**
  (`if (!empty($crt_db_plugin) or !isset($this->fs_plugins[$plugin_id])) break;`). It cannot be used
  to re-run `install()` on an installed plugin.
- **`activate`** (`:188-219`) — falls through to `install` only when there is no DB row; on an
  already-installed plugin it calls `activate()`, an empty no-op in both fork plugins.
- **`restore`** (`:271-275`) = `uninstall` + `activate`. Destructive:
  `plugins/provenance/maintain.class.php:73-88` **drops its columns off `piwigo_categories` and
  `piwigo_images`** and drops `piwigo_provenance_history` — every provenance value is lost, not
  recoverable by a rescan. `plugins/persons/maintain.class.php:75-81` drops the two index tables
  and orphan mirrored tags, which *is* recoverable: the image file is the source of truth and
  `pwg.persons.rescan` (`plugins/persons/main.inc.php:140-150`, `admin_only`, `pwg_token`, at most
  `PERSONS_WRITEBACK_MAX_CHUNK = 10` ids per call) rebuilds it.

#### Why re-running `install()` is safe

Both classes define the schema once and apply it additively.
`plugins/persons/maintain.class.php:34-58`:

```php
    pwg_query('CREATE TABLE IF NOT EXISTS `' . $this->persons_table . '` ( ... );');
    ...
    // A database created by an earlier version carries that version's columns.
    // install() is re-entered through update() on every version bump, which is
    // where a new column has to reach an existing table - a
    // CREATE TABLE IF NOT EXISTS never touches one.
    $this->add_missing_columns($this->persons_table, persons_person_columns());
    $this->add_missing_columns($this->region_table, persons_region_columns());
```

`add_missing_columns()` (`:136-152`) checks `SHOW COLUMNS … LIKE` per column and issues
`ALTER TABLE … ADD` only for missing ones, skipping `id` because `AUTO_INCREMENT` is only
meaningful with the primary key `CREATE TABLE` declared. Column lists come from
`persons_person_columns()` / `persons_region_columns()`
(`plugins/persons/include/functions.inc.php:77-86`, `:102-118`), so the schema lives in one place.

Provenance is the same shape (`maintain.class.php:31-66`): `add_column()` per column from
`provenance_album_columns()` (4 onto `piwigo_categories`) and `provenance_image_columns()`
(5 onto `piwigo_images`), `CREATE TABLE IF NOT EXISTS` for the history table, plus
`widen_source_enum()` — an unconditional, idempotent `ALTER TABLE … MODIFY` — and
`add_display_info_key()`, which leaves an existing key alone so a re-run never re-enables a row an
administrator switched off.

⚠ **One gap in persons**: it has no `ALTER … MODIFY`. A column whose *definition* changed — a
widened `region_type` or `source` ENUM — is not touched by `add_missing_columns()`, because the
column already exists. Provenance covers exactly that case for its own `source` ENUM with
`widen_source_enum()`; persons has no equivalent.

### C. Content and config rows — nothing exists

**No export, import, backup, dump or migration facility anywhere.** `admin/maintenance.php`
(gated by `check_status(ACCESS_ADMINISTRATOR)` at `:21` and `check_pwg_token()` at `:23-26`) offers
the actions in `admin/maintenance_actions.php`: `phpinfo`, `lock_gallery`, `unlock_gallery`,
`categories`, `images`, `delete_orphan_tags`, `user_cache`, `history_detail`, `history_summary`,
`sessions`, `feeds`, `database`, `c13y`, `empty_lounge`, `search`, `compiled-templates`,
`derivatives`, `check_upgrade`, `ext_imagick`, `imagick`, `gd`.

The one named `database` (`:149-153`) is `do_maintenance_all_tables()`
(`include/dblayer/functions_mysqli.inc.php`) — `SHOW TABLES LIKE '<prefix>%'`, then `REPAIR TABLE`
and a primary-key re-order. **Maintenance, not a dump.**

What does exist are *re-creation* paths, which produce equivalent rows on the remote rather than
copying local ones:

- **Photos and albums**: `admin/site_update.php` re-scans `galleries/` on the server and inserts
  `piwigo_categories` / `piwigo_images` / `piwigo_image_category` rows. `tools/remote_sync.pl:42-56`
  drives it over HTTP. Constrained by `$conf['sync_chars_regex']`, ASCII-only
  (`include/config_default.inc.php:952`).
- **Person regions**: `pwg.persons.rescan` rebuilds the index from the MWG regions in the image
  files — so for persons, *the files are the transport* and the DB is derived. Getting regions to
  the remote means getting the written image files there, which is the FTP upload.
- **Provenance values**: no such path. The columns on `piwigo_categories` / `piwigo_images` are
  the source of truth; the write-back pushes them *into* files but nothing reads them back — this
  is recorded as a stated consequence of
  `docs/agents/decisions/0015-provenance-columns-stay-out-of-the-metadata-mappings.md` (no
  provenance column is a key in `use_iptc_mapping` / `use_exif_mapping`, so no synchronisation
  reads them back). Divergence detection is an open backlog item.
- **Per-object writes over the API**: `pwg.categories.add`, `pwg.categories.setInfo`,
  `pwg.images.setInfo`, `pwg.tags.add`, plus the fork-local `pwg.provenance.setAlbumInfo`,
  `setPhotoInfo`, `applyToPhotos` and `pwg.persons.addRegion`, `rename`, `deleteRegion`
  (`plugins/provenance/main.inc.php:81-150`, `plugins/persons/main.inc.php:57-150`).
  `tools/piwigo_remote.pl` already drives several of these. Row-by-row, not a migration.

⚠ **The local DB is not a clean source to copy from.** Measured in §5 above: it carries
`provenance_throwaway_install` and `persons_throwaway_install` markers, six test-scaffolding users
(`provenance_webmaster` … `persons_normal`), a leftover `Persons admin fixture` virtual album
(id 1851), 18 746 `piwigo_activity` rows (87.4 % of the 2.80 MB), and a `secret_key` whose
`send_piwigo_infos_origin_hash` is derived from the *local* URL. And the persons/provenance suites
mutate and restore this same database (`tests/Support/FixtureBuilder.php`), so its content reflects
whatever the last test run left.

### What this means for the deploy script's design space

Three distinct positions, each with a different consequence. **Position 1 was chosen** (decision 9
below):

1. **Fresh `install.php` on the remote, content re-created there.** The remote DB is built by the
   installer; `galleries/` is uploaded by FTP and re-scanned by `site_update`; plugins are
   activated (which runs their `install()`); person regions come back via `rescan` because they
   live in the files. Provenance values would have no path. Nothing local is copied.
2. **Transfer the local DB once, then diverge.** Requires a dump facility that does not exist here,
   plus rewriting `local/config/database.inc.php` for the remote credentials, plus scrubbing the
   dev-only rows listed above. After that, remote content is authoritative and the local DB stops
   being a source.
3. **Ongoing local→remote DB sync.** Nothing in the codebase supports this, and the two databases
   diverge the moment anyone uploads a photo or leaves a comment on the remote.

Positions 2 and 3 also collide with `upload/`: it is git-ignored, and on a live install it is where
the API and upload form write new photos — i.e. server-authoritative, not a deploy target.

### Additional code references (follow-up)

- `upgrade_feed.php:33-36` — the only gate on the unauthenticated core-migration runner
- `upgrade_feed.php:62-98` — the applied-vs-available diff and the per-migration `include`
- `admin/include/functions_upgrade.php:265-280` — `get_available_upgrade_ids()`
- `include/common.inc.php:148-154` — the `upgrade.php` redirect that never fires by default
- `include/common.inc.php:322-332` — the `upgrade_feed.php` banner that does
- `include/functions_plugins.inc.php:342-352` — `load_plugin()` calls `autoupdate_plugin()` first
- `include/functions_plugins.inc.php:362-427` — the automatic version-change detector and `update()` call
- `include/functions_plugins.inc.php:432-445` — `load_plugins()`, reached from `common.inc.php:159` every request
- `include/functions.inc.php:2413-2433` — `safe_version_compare()`
- `admin/include/plugins.class.php:122-125` — `update` deliberately skips building the maintain class
- `admin/include/plugins.class.php:156-176` — `update` is a PEM download, unreachable for fork-local plugins
- `admin/include/plugins.class.php:170` — `Version: auto` suppresses the version write
- `admin/include/plugins.class.php:187-192` — `activate` falls through to `install`
- `admin/include/plugins.class.php:271-275` — `restore` = uninstall + activate
- `admin/include/plugins.class.php:347-356` — the 2048-byte header parse for `Version:`
- `include/ws_functions/pwg.extensions.php:53-88` — `pwg.plugins.performAction`, webmaster + token
- `include/config_default.inc.php:476-489` — API keys may not call it
- `plugins/persons/maintain.class.php:34-58,135-152` — idempotent install and `add_missing_columns()`
- `plugins/persons/maintain.class.php:75-115` — uninstall drops the index and orphan mirrored tags
- `plugins/provenance/maintain.class.php:31-88` — install adds columns to core tables; uninstall drops them
- `plugins/persons/main.inc.php:140-150` — `pwg.persons.rescan`, 10 ids per call
- `admin/maintenance.php:21-26` — admin status plus `pwg_token`
- `admin/maintenance_actions.php:20-24,149-153` — `phpinfo` and the REPAIR/re-order `database` action

### Decisions (follow-up, answered 2026-08-30)

9. **Fresh install, one-time DB transfer, or ongoing sync?** → **fresh install remotely; content
   re-created there.** `galleries/` is uploaded by FTP and re-scanned by `site_update`; person
   regions come back via `pwg.persons.rescan` because they live in the image files. Provenance
   values have no path and are simply not carried over.
   ⚠ **This remote is a toy/sandbox instance.** The production instance is a separate, later
   decision, so this choice is not a production posture and should not be cited as one. Two
   consequences: remote data loss is cheap (so `restore` dropping provenance columns is tolerable
   here but would not be in production), and position 9 will need revisiting before any real
   gallery is hosted.
10. **Should the script verify the `Version:` bump?** → **no; bump manually.** The propagation
    itself is automatic (`autoupdate_plugin()`), and no check is added.
    ⚠ Recorded consequence: nothing fails when the header is not bumped. The new column never
    reaches the remote table and the plugin runs against a schema it does not expect — a silent
    failure mode, accepted deliberately for a sandbox. Separately, persons has no
    `ALTER … MODIFY` path, so a *changed* column definition does not propagate even with a bump.
11. **Tracked test suites on the public web space?** → **excluded by an explicit deploy exclusion
    list**: `tests/`, `phpunit.xml`, `composer.json`/`composer.lock`, `package.json`/
    `package-lock.json`, `playwright.config.js`. `tests/Support/create-test-users.php` is never
    published. (`plugins/*/vendor/` is already git-ignored, so PHPUnit would not ship anyway.)
12. **Capability probing after install** → **use `admin.php?page=maintenance&action=phpinfo`**
    (admin + `pwg_token`, `admin/maintenance_actions.php:20-24`), which reports `disable_functions`
    from inside the running install with no file at a guessable URL. The standalone probe file
    (decision 1) covers only the pre-install window and is deleted immediately after.
