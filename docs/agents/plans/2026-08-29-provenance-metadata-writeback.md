---
date: 2026-08-29T07:32:24+00:00
git_commit: 24e634b651dccd4ade28fc667a72d7af329131ea
branch: master
topic: "Provenance metadata for scanned photos: album-level entry, copy-down to photos, write-back into image files"
tags: [plan, provenance, metadata, exiftool, xmp, iptc, exif, albums, audit-trail, plugin, core-patch]
status: in_progress
research: docs/agents/research/2026-08-29-per-photo-freetext-field-and-metadata-writeback.md
---

# Provenance Metadata Implementation Plan

## Overview

This install holds scans of physical photographs borrowed from people. The feature records
**where a scan came from** — which physical album, who owns it, when it was scanned, plus free
text — entered once at album level, copied down onto every photo in the album, and written into
the image files themselves as EXIF, IPTC and XMP, with a value-level audit trail.

Everything is built as a new plugin, `plugins/provenance`, except two one-line trigger additions
to core that let late-joining photos inherit their album's provenance.

## Current State Analysis

From the research (`docs/agents/research/2026-08-29-per-photo-freetext-field-and-metadata-writeback.md`,
2616 lines, status `complete` — treated as the source of truth here and not re-investigated):

- **Piwigo's metadata layer is read-only.** No write path exists anywhere
  (`include/functions_metadata.inc.php`); the only metadata mutations are destructive strips on
  *derivatives* (`i.php:615-617`, `admin/include/image.class.php:504`).
- **Core has no revision or field-diff storage.** `piwigo_activity.details` is `varchar(255)` with
  nothing truncating before insert (`include/functions.inc.php:648`) — unsafe for unbounded text.
- **The album save path is closed.** `ws_categories_setInfo` hard-codes
  `$info_columns = array('name','comment','commentable')` (`include/ws_functions/pwg.categories.php:946`)
  and fires no `trigger_change`. A new album field needs its own WS method.
- **The album *screen* is injectable.** `loc_begin_admin_page` (`admin.php:406`) fires before the
  page include; the template handle is `album_properties`, not `cat_modify`
  (`admin/cat_modify.php:167`, `:393`). A button + modal already ships on that exact page
  (`cat_modify.tpl:141`, `:188`, `:191-203`).
- **No queue, no cron, no server-side batching.** The one established shape for an N-photo
  operation is client-chunked serialized AJAX against `ws.php`
  (`admin/themes/default/js/batchManagerGlobal.js:239-309`).
- **Production is capable but time-boxed.** ALL-INKL PrivatPlus, probed 2026-08-29: PHP 8.4.16
  fpm-fcgi, `disable_functions` empty, imagick present, **ExifTool 12.76 preinstalled**,
  `max_execution_time: 60`, `memory_limit: 384M`.
- **Concurrent exiftool writes destroy the file** — measured, 5 of 6 runs at 12-way contention;
  `flock` on a separate lock file fully mitigates (6/6 survived).
- **exiftool writes without re-encoding pixels** — measured byte-identical across PNG/JPEG/TIFF/HEIC.
- **`plugins/typetags` is the in-repo model** for prefilter injection, guarded WS write handlers,
  and the idempotent `SHOW COLUMNS` + `ALTER TABLE` schema idiom.

### Verified during this planning pass (2026-08-29, local exiftool 13.55)

The one design element the research had not exercised — a custom XMP namespace — was prototyped
on a real gallery PNG (`upload/2026/04/19/20260419142037-d27ce8fe.png`):

- All five MWG caption slots **and** `XMP-pwgprov:{PhysicalAlbum,Owner,ScannedOn,AlbumNote,PhotoNote}`
  write in **one** `exiftool -config … -@ argfile` invocation. Exit 0; the only output is the
  already-known `Warning: [minor] Creating non-standard IPTC in PNG`.
- **The `-config` file is required only for writing.** `-XMP-pwgprov:all` reads back correctly
  *without* it, because XMP embeds its own namespace URI. Verification tooling therefore needs no
  config file.
- Pixels identical: sha256 of the decompressed IDAT stream matched `_original` exactly.
- Chunk inventory after the write: `IHDR(13) zTXt(162) iTXt(1338) eXIf(108) IDAT(4096)` — a genuine
  PNG `eXIf` chunk.
- `.ddev/config.yaml` carried **no** `webimage_extra_packages`; the container's exiftool 13.25 was
  hand-installed and did not survive `ddev restart`. **Resolved in Phase 1** (decision C7a):
  `webimage_extra_packages: ["libimage-exiftool-perl"]` is now in `.ddev/config.yaml`, verified by
  `ddev restart && ddev exec exiftool -ver` (13.25, 2026-08-29).

## Desired End State

An administrator opens an album's properties tab, clicks **Provenance**, fills in four fields in a
modal, and saves. A second button applies those values to every photo in the album. A third writes
them into the photos' image files. Every value change is recorded with its old and new value. A
photo added to the album later inherits automatically. The photo page shows a Provenance row.

Verified by: the integration and E2E suites in `plugins/provenance/tests/` passing, plus the
hand-check ledger entries listed in *Testing Strategy*.

### UI Mockups

Album properties tab — `admin.php?page=album-1-properties`, after injection:

```
┌─ Properties ──────────────────────────────────────────────────────────┐
│  Name         [ Erstes Album                                       ]  │
│  Description  [ ...                                          ] [⛶]   │
│                                                                       │
│  ── Provenance ─────────────────────────────────────────────────────  │
│  Physical album:  Oma Müller, blaues Album                            │
│  Owner:           Anna Müller                                         │
│  Scanned on:      2026-04-19                                          │
│  Note:            Rückseiten teilweise beschriftet                    │
│                                                                       │
│  [ ✎ Edit provenance ]  [ ⇩ Apply to 76 photos ]  [ ⌸ Write to files ]│
│                                                                       │
│  [ 💾 Save Settings ]                                                 │
└───────────────────────────────────────────────────────────────────────┘
```

The edit modal reuses the shape already in `cat_modify.tpl:191-203`:

```
┌─ Provenance ────────────────────────────────────── [×] ─┐
│  Physical album  [ Oma Müller, blaues Album           ] │
│  Owner           [ Anna Müller                        ] │
│  Scanned on      [ 2026-04-19            ]  (date)      │
│  Note            [                                    ] │
│                  [                                    ] │
│                                          [ Save ]       │
└─────────────────────────────────────────────────────────┘
```

Apply / write-back progress, following `batchManagerGlobal.js`:

```
  Applying provenance to 76 photos…
  [██████████████████░░░░░░░░░]  54 / 76

  Writing metadata into 76 files…
  [███████████░░░░░░░░░░░░░░░░]  31 / 76      2 failed  ▸ show
```

Photo page info panel — one new row inside `<dl id="standard">`:

```
  Author        Anna Müller
  Created on    19. April 2026
  Dimensions    509 × 767
  Provenance    Oma Müller, blaues Album · Anna Müller · gescannt 19.04.2026
  Tags          Urlaub, Familie
```

### Key Discoveries

- `admin.php:406` `trigger_notify('loc_begin_admin_page')` fires before the page include; the
  album template handle is **`album_properties`** (`admin/cat_modify.php:167`).
- `picture.php:461-465` loads the image row with `SELECT *`, so new columns are reachable as
  `{$current.provenance_*}` with no extra query.
- `themes/modus` does **not** override `picture.tpl`, so one prefilter covers both themes.
- Core patch site 1: `admin/include/functions.php:2094-2098`, immediately after the
  `mass_inserts()` inside `associate_images_to_categories()`.
- Core patch site 2: `admin/site_update.php:676-681`, immediately after the second
  `mass_inserts()` (the `$insert_links` one), before `pwg_activity('photo', …)` at `:683`.
- `ws.php` does not include `admin/include/functions.php`, so WS handlers cannot call admin
  helpers — use core functions or direct SQL (`plugins/typetags/main.inc.php:189-238` is the model).
- `PwgError` returns **HTTP 200 with `stat:"fail"`**, so client code must handle failure inside the
  jQuery `success` callback.
- `.gitignore` excludes `plugins/*` then re-includes tracked ones with `!` — `plugins/provenance`
  needs its own `!` entry or it stays invisible to git.
- `.githooks/lib.sh` `UNIT_SUITE_ARGS` names only the typetags suite; the new plugin's unit suite
  must be added there or it is never gated.

## What We're NOT Doing

- **No per-album write-permission model.** Decision C5: admin-only for this slice.
- **No `owner` → people table.** Decision 3a: free text. Backlog carries the reference-table item.
- **No file-vs-DB divergence detection.** Decision 4a: none in v1; backlog item exists.
- **No history retention/purge.** Decision 6a: no purge in v1; growth path recorded in TESTING.md.
- **No enforcement of the 1:1 photo↔album relationship.** Separate backlog item; this plan *asserts*
  the assumption in fixtures rather than relying on it (see Phase 5).
- **No Batch Manager bulk actions for the new columns.** Decision 2a: album-level entry is the bulk path.
- **No `pwg.images.setInfo` extension.** Its allow-list is hard-coded with no hook; the plugin owns
  its own WS methods.
- **No changes to `images.comment` / `images.name` / `images.author`,** and no new entries in
  `$conf['use_iptc_mapping']` / `$conf['use_exif_mapping']` (decision C3 — this is what keeps
  `sync_metadata()` from ever reverting an album-sourced value).
- **No HEIC ingestion work.** The collection is 100% PNG; the ExifTool 12.76 HEIC rotation-corruption
  caveat is recorded, not designed around.
- **No `-stay_open` daemon.** Batched multi-file invocations only.

## Implementation Approach

Ten phases, each shipping its own tests and pausing for manual confirmation. Ordering rationale:

- The **pure composition layer (Phase 2)** lands before anything that uses it, because it is the
  only substantial logic that can live at the unit layer, and everything downstream reads its
  constants rather than carrying a second copy.
- The **history table (Phase 3)** lands before the apply operation (Phase 5), because apply writes
  history rows.
- The **file write-back (Phase 6)** lands after the DB path is complete and green, because it is the
  only phase where a defect destroys data.
- The **core patches (Phase 7)** open with characterization tests of the two functions as they
  behave today, per *cover the ground before you move it* (`.claude/rules/testing.md`).

Naming fixed here so no phase invents its own: plugin id `provenance`; table
`piwigo_provenance_history`; WS namespace `pwg.provenance.*`; XMP namespace prefix `pwgprov`,
URI `http://piwigo.org/ns/provenance/1.0/`.

### Schema (fixed, referenced by every phase)

```sql
-- piwigo_categories  (album-level entry)
provenance_physical_album  varchar(255)  DEFAULT NULL
provenance_owner           varchar(255)  DEFAULT NULL
provenance_scanned_on      date          DEFAULT NULL
provenance_note            text          DEFAULT NULL

-- piwigo_images
provenance_physical_album  varchar(255)  DEFAULT NULL   -- copied down from the album
provenance_owner           varchar(255)  DEFAULT NULL   -- copied down from the album
provenance_scanned_on      date          DEFAULT NULL   -- copied down from the album
provenance_album_note      text          DEFAULT NULL   -- copied down from the album
provenance_note            text          DEFAULT NULL   -- the photo's own, never written by an album

-- piwigo_provenance_history
id           int unsigned      NOT NULL AUTO_INCREMENT PRIMARY KEY
object       enum('album','photo') NOT NULL
object_id    int unsigned      NOT NULL
field        varchar(64)       NOT NULL
old_value    text              DEFAULT NULL
new_value    text              DEFAULT NULL
source       enum('album_edit','photo_edit','apply','inherit','writeback','truncation') NOT NULL
performed_by mediumint unsigned DEFAULT NULL
occured_on   timestamp         NOT NULL DEFAULT CURRENT_TIMESTAMP
KEY object_lookup (object, object_id, occured_on)
```

The four album-sourced columns are **album-authoritative** (decision C3): a re-apply overwrites them
on every photo. `provenance_note` on an image is **photo-authoritative** and is never touched by any
album operation.

---

## Phase 1: Plugin skeleton, schema, dev environment

### Overview
A tracked, installable, testable plugin with its schema and the exiftool config file — no feature
behaviour yet. This phase exists so every later phase has a suite to run.

### Changes Required:

#### [x] 1. Plugin skeleton
**Files**: `plugins/provenance/main.inc.php`, `plugins/provenance/maintain.class.php`,
`plugins/provenance/index.php`, `plugins/provenance/language/en_UK/plugin.lang.php`,
`plugins/provenance/language/de_DE/plugin.lang.php`
**Changes**: Header comment block (`Plugin Name`, `Version`, `Description`, `Has Settings: false`),
constants, folder-name guard, and hook registrations added as later phases need them.

```php
// main.inc.php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');
global $prefixeTable;
define('PROVENANCE_PATH',           PHPWG_PLUGINS_PATH . 'provenance/');
define('PROVENANCE_HISTORY_TABLE',  $prefixeTable . 'provenance_history');
define('PROVENANCE_XMP_CONFIG',     PROVENANCE_PATH . 'exiftool/pwgprov.config');
include_once(PROVENANCE_PATH . 'include/functions.inc.php');
```

#### [x] 2. Schema install / uninstall
**File**: `plugins/provenance/maintain.class.php`
**Changes**: `install()` adds nine columns behind `SHOW COLUMNS` guards and creates the history
table with `CREATE TABLE IF NOT EXISTS`; `update()` delegates to `install()`; `uninstall()` drops
all nine columns and the table. Follows `plugins/typetags/maintain.class.php:20-57`.

```php
private function add_column($table, $column, $definition)
{
  $result = pwg_query('SHOW COLUMNS FROM `'.$table.'` LIKE "'.$column.'";');
  if (!pwg_db_num_rows($result))
  {
    pwg_query('ALTER TABLE `'.$table.'` ADD `'.$column.'` '.$definition.';');
  }
}
```

#### [x] 3. exiftool custom-namespace config
**File**: `plugins/provenance/exiftool/pwgprov.config`
**Changes**: the file prototyped and verified in this planning pass, verbatim.

```perl
%Image::ExifTool::UserDefined::pwgprov = (
    GROUPS        => { 0 => 'XMP', 1 => 'XMP-pwgprov', 2 => 'Image' },
    NAMESPACE     => { 'pwgprov' => 'http://piwigo.org/ns/provenance/1.0/' },
    WRITABLE      => 'string',
    PhysicalAlbum => { }, Owner => { },
    ScannedOn     => { Groups => { 2 => 'Time' } },
    AlbumNote     => { }, PhotoNote => { },
);
%Image::ExifTool::UserDefined = (
    'Image::ExifTool::XMP::Main' => {
        pwgprov => { SubDirectory => { TagTable => 'Image::ExifTool::UserDefined::pwgprov' } },
    },
);
1;  #end
```

#### [x] 4. Test harness
**Files**: `plugins/provenance/composer.json`, `phpunit.xml`, `package.json`,
`playwright.config.js`, `tests/bootstrap.php`, `tests/Support/{Config,Db,FixtureBuilder}.php`,
`tests/e2e/support/`
**Changes**: mirror `plugins/typetags/` — `failOnWarning="true" failOnRisky="true"`,
`unit`/`integration` testsuites, `retries: 0`, `workers: 1`, `testDir: './tests/e2e'`, tracing on.
Credentials from `PROVENANCE_TEST_USERNAME` / `PROVENANCE_TEST_PASSWORD`, failing fast by name;
everything else defaults to DDEV values. `Db` and `Config` are copied rather than shared —
`plugins/typetags` is a git submodule tracking upstream and must not become a dependency of core
plugin code.

#### [x] 5. Dev environment: exiftool that survives a restart
**File**: `.ddev/config.yaml`
**Changes**: add `webimage_extra_packages: ["libimage-exiftool-perl"]` (decision C7a). Production
needs no change — ExifTool 12.76 is preinstalled at `/usr/bin/exiftool`.

#### [x] 6. Repository wiring
**Files**: `.gitignore`, `.githooks/lib.sh`
**Changes**: add `!plugins/provenance` alongside the existing `!plugins/typetags`; add the new
plugin's unit suite to the commit gate. `UNIT_SUITE_ARGS` becomes an array of suites so the two
cannot drift:

```bash
UNIT_SUITES=(
  "plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml"
  "plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml"
)
```

`tools/test-hooks.sh` keeps building its probes from `lib.sh` — no second copy. `plugins/provenance`
is a plain directory in the superproject, not a submodule, so `tools/install-hooks.sh` needs no
change; that fact is asserted by the self-test rather than assumed.

### Success Criteria:

#### Automated Verification:
- [x] Syntax: `ddev exec php -l plugins/provenance/main.inc.php` and the same for every new PHP file
- [x] Unit suite runs (empty is acceptable in this phase, non-empty from Phase 2):
      `ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml`
- [x] Integration: `PluginActivationTest` — activate, assert all nine columns and the table exist;
      deactivate/uninstall, assert they are gone; re-install, assert idempotence
- [x] `bash tools/test-hooks.sh` passes with the two-suite gate (≥2 red cases, 1 green case)
- [x] `ddev restart && ddev exec exiftool -ver` prints a version — proves `webimage_extra_packages` took
- [x] `git status --porcelain plugins/provenance | head -1` is non-empty — proves the `.gitignore` `!` entry works

#### Manual Verification:
Both boxes were **automated instead of hand-checked** (2026-08-29); neither needs a human, so
neither goes in the hand-check ledger.

- [x] ~~The plugin appears in the admin plugin list and activates without a PHP notice~~ →
      `AdminPluginPageTest`. The list is a rendering of `piwigo_plugins` joined with the header
      block parsed out of `main.inc.php`, and PHP diagnostics render inline into the response
      body on this install (`display_errors` on for FPM), so both halves have an HTTP oracle. The
      diagnostic check is **differential** — the same four pages fetched with the plugin
      deactivated and activated, asserting the activated side introduces nothing — so pre-existing
      core noise can neither fail the test nor mask a plugin-introduced notice.
- [x] ~~Watch the pre-commit hook block a staged `|| true` in a `plugins/provenance/tests/` file
      by hand~~ → a case in `tools/test-hooks.sh` (the commit-gate self-test, the one sanctioned
      exception in `.claude/rules/test-design.md`). It stages the vacuous probe at
      `plugins/provenance/tests/` and asserts exit 1, after first asserting that path is inside
      `TEST_PATH_PATTERN`'s scope — so the case cannot pass by scanning nothing.

**Implementation Note**: After completing this phase and all automated verification passes, pause
here for manual confirmation from the human before proceeding.

---

## Phase 2: The pure composition layer

### Overview
Every decision about *what text goes into a file* lives in pure functions with no database, no HTTP
and no shell — the only substantial unit-testable surface in this feature, and the single source of
truth the tests read instead of carrying their own copies of separators, labels and limits.

Test-first throughout: write the test, **watch it fail for the expected reason**, then implement.

### Changes Required:

#### [x] 1. Constants — one definition each
**File**: `plugins/provenance/include/functions.inc.php`

```php
define('PROVENANCE_CAPTION_SEPARATOR', ' | ');
define('PROVENANCE_IPTC_MAX_BYTES',    2000);          // MWG 2.0 §5.2, IPTC-IIM 2:120
define('PROVENANCE_TRUNCATION_MARK',   '…');
// Field order is part of the contract: the composed caption is deterministic.
function provenance_field_order()
{
  return array('provenance_physical_album', 'provenance_owner', 'provenance_scanned_on',
               'provenance_album_note', 'provenance_note');
}
```

Labels come from `l10n()` at call sites, never from a literal inside the composer — the composer
takes already-labelled parts so it stays free of the language layer and testable without Piwigo.

#### [x] 2. `provenance_compose_caption(array $parts): string`
Joins non-empty, trimmed parts with `PROVENANCE_CAPTION_SEPARATOR`. Empty and whitespace-only parts
are dropped. All-empty input returns `''`.

#### [x] 3. `provenance_truncate_for_iptc(string $text): array`
Returns `array('text' => …, 'truncated' => bool)`. Truncates on a **UTF-8 character boundary** so
the result is never invalid UTF-8, appending `PROVENANCE_TRUNCATION_MARK` within the byte budget.
`strlen()` (bytes), not `mb_strlen()` — the IPTC limit is a byte limit.

#### [x] 4. `provenance_build_argfile(array $values, string $caption): array`
Returns the argfile lines, in order: `-charset`, `iptc=UTF8`, the five MWG slots with the caption
(IPTC getting the truncated copy), then the five `-XMP-pwgprov:*` lines. Empty values are omitted
entirely rather than written as empty tags. One argument per line — the format exiftool's `-@`
expects, and the reason no value ever reaches a command line (decision C8).

#### [x] 5. `provenance_lock_path(string $imagePath): string`
`_data/provenance/locks/<sha1 of the path>.lock`. A separate file, never the image itself — exiftool
replaces the image by rename, so a lock on the old inode would exclude nothing after the first write.

### Success Criteria:

#### Automated Verification:
- [x] `ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml`
- [x] `ddev exec php -l plugins/provenance/include/functions.inc.php`
- [x] Suite passes twice consecutively and in reverse order (`--order-by=reverse`)

#### Manual Verification:
- [x] ~~For each new function, break its behaviour once and confirm the test goes red~~
      (`.claude/rules/test-design.md`, *proving a check can actually fail*) → **automated as a
      one-off, run 2026-08-29**. Each mutation was applied on the host, asserted to have changed
      the file, and the container was polled (`md5` vs `ddev exec md5sum`) until it held the new
      bytes before the suite ran — the shifted-by-one trap in `.claude/rules/mutation-testing.md`.
      The file was restored and re-run green afterwards.

      | Mutant | Result |
      |---|---|
      | `provenance_compose_caption()`: join order reversed | 4 failures |
      | `provenance_truncate_for_iptc()`: `<=` → `<` | 2 failures |
      | `provenance_build_argfile()`: `-charset` preamble dropped | 2 failures |
      | `provenance_sanitize_argfile_value()`: newline collapsing removed | 2 failures |
      | `provenance_lock_path()`: returns the image path itself | 3 failures |

      The harness is **not** kept: `.claude/rules/test-design.md` (*build no apparatus that proves
      another apparatus*) and `mutation-testing.md` (*keep it as prose, not a script*) both forbid
      it, so this table is the artefact and nothing was added to the suite. The Phase 10 mutation
      table supersedes it as the standing strength record.

### Deviation from the plan

`provenance_sanitize_argfile_value()` is one helper the plan did not name. The plan left the
newline case as "rejected or escaped"; neither was taken — newlines are collapsed to a space.
Recorded as [decision 0006](../decisions/0006-argfile-newlines-collapsed-not-rejected.md).

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 3: History table and recorder

### Overview
The value-level audit trail. Every provenance write in every later phase goes through one recorder,
so no phase can add a write path that forgets to log.

### Changes Required:

#### [ ] 1. `provenance_record_change(...)`
**File**: `plugins/provenance/include/history.inc.php`
**Changes**: one row per changed field. **A field whose value did not change writes no row** — the
trail records changes, not saves, or a 76-photo re-apply of unchanged text writes 380 useless rows.

```php
function provenance_record_change($object, $object_id, $field, $old, $new, $source)
```

Bulk callers use `provenance_record_changes(array $rows)`, which funnels into a single
`mass_inserts()` — the apply operation writes up to 4 rows × N photos.

#### [ ] 2. Read path
**File**: `plugins/provenance/include/ws_functions.inc.php`
**Changes**: `pwg.provenance.getHistory` — `admin_only`, params `object` (validated against
`array('album','photo')`), `object_id` (`PATTERN_ID`), optional `date_min`/`date_max`, `per_page`
(default 50, max 500). Follows the shape of `ws_getActivityList()`
(`include/ws_functions/pwg.php:453`) rather than inventing a new one.

### Success Criteria:

#### Automated Verification:
- [ ] Unit tests for the row-shaping helper (pure part) pass
- [ ] Integration: a recorded change is readable back through `pwg.provenance.getHistory` with
      `old_value` and `new_value` intact, including a value **longer than 255 bytes** — the exact
      case `piwigo_activity.details` cannot hold, which is why this table exists
- [ ] Integration: `getHistory` as a non-admin returns `stat:"fail"` with 403
- [ ] `ddev exec php -l` on every changed file

#### Manual Verification:
- [ ] A history row's `occured_on` and `performed_by` match the acting admin in the DB

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 4: Album admin UI and the album save

### Overview
Entering provenance on an album. Injection via prefilter, save via the plugin's own WS method — the
album's existing save path cannot be extended.

### Changes Required:

#### [ ] 1. Admin prefilter
**File**: `plugins/provenance/include/events_admin.inc.php`
**Changes**: registered on `loc_begin_admin_page` (guarded by `defined('IN_ADMIN')`), returns early
unless `$page['page'] === 'album'`, then `$template->set_prefilter('album_properties', …)`.
The injection anchor is a **named constant**, matched against `cat_modify.tpl` — never a literal
typed a second time in a test (`.claude/rules/test-design.md`, *do not transcribe production data*):

```php
define('PROVENANCE_TPL_ALBUM_ANCHOR', '<span class="buttonLike" id="cat-properties-save">');
```

Injection goes immediately **before** that anchor, so the provenance block sits above the Save
button, inside the existing form area. Values and `PWG_TOKEN` reach JS through template variables.

#### [ ] 2. Modal and JS
**Files**: `plugins/provenance/template/album_provenance.tpl`, `template/album_provenance.js`
**Changes**: reuse the page's own modal markup (`cat_modify.tpl:191-203`) and `buttonLike` /
`icon-*` conventions. No new dependency, no framework. `PwgError` returns HTTP 200 with
`stat:"fail"`, so failure handling lives in the jQuery `success` callback.

#### [ ] 3. `pwg.provenance.setAlbumInfo`
**File**: `plugins/provenance/include/ws_functions.inc.php`
**Changes**: registered on `ws_add_methods`, `admin_only` **and** `post_only`. Guard order copied
from `plugins/typetags/main.inc.php:189-238`: guest → 401, token mismatch → 403, unknown album →
404, then validate, then write, then record history.

Validation, at the boundary only:
- `cat_id` — `check_input_parameter(..., PATTERN_ID)`
- `physical_album`, `owner` — `strip_tags()`, then truncate to 255 with a `PwgError(400)` if the
  input exceeded it (silent truncation of a provenance fact is worse than a rejection)
- `scanned_on` — `''` or `/^\d{4}-\d{2}-\d{2}$/` **and** `checkdate()`; anything else → `PwgError(400)`
- `note` — `strip_tags()`, no length cap (column is `text`)
- `pwg_db_real_escape_string()` on every value reaching SQL

`$conf['allow_html_descriptions']` is deliberately **not** honoured here: this text is destined for
an EXIF/IPTC packet, where markup is meaningless. Recorded as a decision file.

### Success Criteria:

#### Automated Verification:
- [ ] Unit: input-validation helpers (date shape, length, tag stripping) — `[ECP]` `[BVA]` `[NEG]`
- [ ] Unit: structural guard — `PROVENANCE_TPL_ALBUM_ANCHOR` still occurs in
      `admin/themes/default/template/cat_modify.tpl`, **exactly once**, preceded by an
      `assertGreaterThan(MIN_TPL_BYTES, strlen($tpl))` anti-vacuity guard
- [ ] Integration: `setAlbumInfo` writes all four columns; reads back through the DB
- [ ] Integration: as guest → 401; bad token → 403; unknown `cat_id` → 404; malformed date → 400
- [ ] Integration: the rendered album page source contains the injected block
- [ ] `ddev exec php -l` on every changed file

#### Manual Verification:
- [ ] The modal opens, saves, and the values persist across a page reload
- [ ] The injected block does not disturb the existing Properties layout at narrow widths
- [ ] `rm -rf _data/templates_c/*` after any prefilter edit (CLAUDE.md caveat) — confirm the change
      is actually visible, since a stale compile shows the old injection with no error

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 5: Copy-down apply (database only)

### Overview
Applying the album's four values onto every photo in the album. **No image file is touched** —
decision C2 makes write-back a separate, explicit operation.

### Changes Required:

#### [ ] 1. `pwg.provenance.applyToPhotos`
**File**: `plugins/provenance/include/ws_functions.inc.php`
**Changes**: `admin_only` + `post_only`. Params: `cat_id`, and `image_ids` (a comma-joined chunk the
client supplies). The server does **not** iterate a whole album in one request — the client chunks,
matching `batchManagerGlobal.js:239-309` and the production 60 s ceiling.

Writes `provenance_physical_album`, `provenance_owner`, `provenance_scanned_on`,
`provenance_album_note` via `mass_updates(IMAGES_TABLE, …)` — **without** `MASS_UPDATES_SKIP_EMPTY`,
so clearing an album field clears it on the photos too (the semantics the Batch Manager `author`
action already uses, `admin/batch_manager_global.php:243-266`). `provenance_note` is not in the
update set at all.

Old values are read **before** the update so the history rows are accurate.

#### [ ] 2. Chunking client
**File**: `plugins/provenance/template/album_provenance.js`
**Changes**: chunk size `min(round(n/2), 200)` — smaller than the Batch Manager's 1000 because each
row carries up to four `text` values, and the production request budget is 60 s. Serialized through
one in-flight request at a time, progress bar per callback, failures surfaced rather than swallowed.

#### [ ] 3. Photo-level save
**File**: `plugins/provenance/include/events_admin.inc.php`
**Changes**: `pwg.provenance.setPhotoInfo` writes `provenance_note` only, injected into
`picture_modify` via prefilter (the photo screen's own anchor constant), same guard order.

### Success Criteria:

#### Automated Verification:
- [ ] Integration: apply writes the four album-sourced columns onto every photo in the album `[HAPPY]`
- [ ] Integration: apply does **not** modify `provenance_note` on any photo `[NEG]` — the decision
      the whole two-note schema exists for
- [ ] Integration: a second apply after an album edit overwrites the copied columns (decision Q6b) `[ST]`
- [ ] Integration: clearing an album field clears it on the photos (empty writes NULL) `[BVA]`
- [ ] Integration: history rows exist for changed fields and **not** for unchanged ones `[DT]`
- [ ] Integration: the fixture asserts the photo is in exactly one album before the body runs —
      the 1:1 assumption is asserted, never hoped for
- [ ] Integration: apply leaves every image file's mtime and size unchanged (scenario
      *Applying to photos does not itself touch the files*) `[NEG]`
- [ ] `ddev exec php -l` on every changed file

#### Manual Verification:
- [ ] The progress bar advances and completes for the 76-photo album
- [ ] A deliberate mid-run failure (stop the DB) surfaces in the UI rather than silently stalling

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 6: File write-back

### Overview
The only phase where a defect destroys data. Concurrent exiftool writes to one file delete it
outright (measured: 5 of 6 runs at 12-way contention). Locking is built **first**, and the
concurrency test is written before the writer is wired to any UI.

### Changes Required:

#### [ ] 1. Capability probe
**File**: `plugins/provenance/include/exiftool.inc.php`
**Changes**: copy the degradation shape of `pwg_image::is_ext_imagick()`
(`admin/include/image.class.php:393-410`) verbatim in structure:

```php
function provenance_exiftool_available()
{
  global $conf;
  if (!function_exists('exec')) { return false; }        // disable_functions, checked FIRST
  @exec(escapeshellcmd($conf['provenance_exiftool_path'].'exiftool').' -ver', $out);
  return is_array($out) and !empty($out[0]) and preg_match('/^\d+\.\d+/', $out[0]);
}
```

`$conf['provenance_exiftool_path']` defaults to `''` (on `PATH`), configurable for a host where the
binary sits in a home directory. When unavailable, the write-back button is hidden and the WS method
returns a typed error — every other part of the feature keeps working (research Finding D).

#### [ ] 2. Locked, argfile-driven writer
**File**: `plugins/provenance/include/exiftool.inc.php`
**Changes**: per image — acquire an exclusive `flock` on `provenance_lock_path()`, write the argfile
into `_data/provenance/args/<operation-id>/`, invoke
`exiftool -config <pwgprov.config> -@ <argfile> <image>`, capture exit code and stderr, release.

- **Default mode** (keeps `_original`) — decision 7b, and the only *atomic* mode (measured: rename,
  not truncate-in-place), which is what makes derivative generation safe against a concurrent write.
- `-charset iptc=UTF8` — mandatory, not optional; without it non-latin-1 text is silently mangled.
- Per-photo failures are recorded into the history table **before** the `finally` removes the
  operation directory, so cleanup never erases the evidence of a failure
  (`.claude/rules/test-design.md`).
- IPTC truncation, when it happens, is a `source='truncation'` history row (decision 10a).

#### [ ] 3. `pwg.provenance.writeBack`
**Changes**: `admin_only` + `post_only`, takes a chunk of `image_ids`, continues on error (decision
13a), returns `array('written' => n, 'failed' => array(image_id => message))`. Chunk size starts at
**10 images per request** and is justified by the measurement below, not guessed.

#### [ ] 4. Measure throughput against the 60 s ceiling
**Changes**: run a batched invocation over 76 real files in the container, record wall-clock as a
**dated measurement** in `docs/agents/TESTING.md` — never as an assertion in the suite
(`.claude/rules/test-design.md`, *assert the causal fact, not a wall-clock figure*). If the measured
per-image cost puts 10 images near the ceiling, the constant moves and the new figure is recorded.

### Success Criteria:

#### Automated Verification:
- [ ] Unit: `provenance_build_argfile()` line-by-line output for every field combination (Phase 2
      tests extended, not duplicated)
- [ ] Integration: after a write, all five MWG slots and all five `XMP-pwgprov:*` tags read back
      correctly — read via **exiftool or Imagick, never a raw byte grep** (XMP lands in a compressed
      `zTXt` chunk on PNG, so a substring search reports a false negative) and never via
      `exif_read_data()` (returns `false` for PNG `eXIf`)
- [ ] Integration: pixels unchanged — sha256 of the decompressed IDAT stream matches pre-write, with
      an `assertGreaterThan(MIN_IDAT_BYTES, …)` guard first so a zero-byte read cannot pass
- [ ] Integration: a `_original` sidecar exists after the first write, and is **unchanged** after a
      second and third write (measured research finding 6)
- [ ] Integration: **concurrency** — N parallel writers against one file; the file still exists,
      is readable, and carries one of the written values. This is the test for the one measured
      data-loss mode; it is written and watched red (locking disabled) before locking is enabled
- [ ] Integration: text longer than `PROVENANCE_IPTC_MAX_BYTES` — XMP and EXIF hold the full text,
      IPTC holds a valid-UTF-8 truncated copy, and a `source='truncation'` history row exists `[BVA]`
- [ ] Integration: non-latin-1 text (`Łódź Ω 日本 Müller`) round-trips through
      `clean_iptc_value()` intact `[ERR]` — characterization of core's auto-detecting reader
- [ ] Integration: with `exec` unavailable, the WS method returns a typed error and no file is
      touched `[NEG]`
- [ ] Integration: after a failed write the operation directory is gone **and** the failure row is
      in the history
- [ ] `ddev exec php -l` on every changed file

#### Manual Verification:
- [ ] Open a written file in an external viewer (macOS Preview / `exiftool` on the host) and confirm
      the caption is visible where a normal photo tool shows it — hand-check ledger entry, because
      "does a third-party tool find it" has no oracle in this repo
- [ ] Confirm disk growth after a full 76-photo write-back is roughly one extra copy, once

**Implementation Note**: Pause for manual confirmation before proceeding. Do not begin Phase 7 until
the concurrency test has been watched red with locking disabled and green with it enabled.

---

## Phase 7: Core patches so late-joining photos inherit

### Overview
Two `trigger_notify` calls in core (decisions C4 and 1b), and the plugin handler that acts on them.
This is the only phase that changes existing core behaviour, so it opens by covering that behaviour
as it is today.

### Changes Required:

#### [ ] 1. Characterize the two functions as they behave now — lands and passes first, committed separately
**Files**: `plugins/provenance/tests/Integration/CoreAssociationCharacterizationTest.php`
**Changes**: cover `associate_images_to_categories()` and the `admin/site_update.php` link insert
**before** either is touched: existing pairs skipped and keeping their rank, new pairs getting
`++max_rank` per category, the storage-link guard in dissociate/move, and the FS-sync insert
producing a non-NULL `storage_category_id`. Tagged `[ERR]` with the oracle declared — these record
the implementation, not a requirement. Each is watched go red by breaking the behaviour it claims to
watch, since none of them can fail on first run.

#### [ ] 2. Core patch 1 — the virtual-link funnel
**File**: `admin/include/functions.php` (in `associate_images_to_categories()`, after the
`mass_inserts()` at `:2094-2098`, alongside the existing `update_category($categories)`)

```php
trigger_notify('associate_images_to_categories',
  array('image_ids' => $images, 'category_ids' => $categories));
```

Fires after the rows are committed, so a handler sees real data. Piwigo's own style: `and`/`or`,
long array syntax, two-space indent, Allman braces.

#### [ ] 3. Core patch 2 — filesystem sync
**File**: `admin/site_update.php`, immediately after the second `mass_inserts()` (the `$insert_links`
one, `:676-681`) and before `pwg_activity('photo', $caddiables, 'add', …)` at `:683`

```php
trigger_notify('site_update_associate_images', $insert_links);
```

Fires **after** the bulk insert with the full id set — not inside the scan loop, where the rows do
not exist yet. This introduces the first trigger into that file.

#### [ ] 4. Inheritance handler
**File**: `plugins/provenance/include/events_inherit.inc.php`
**Changes**: for each `(image, category)` pair, copy the four album-sourced values onto the image and
record `source='inherit'` history rows. Skips albums with no provenance set. `provenance_note` is
never written.

#### [ ] 5. Keep the instructions honest
**File**: `CLAUDE.md`
**Changes**: the research recorded "`admin/site_update.php` fires no triggers at all". After patch 2
that is false. Fix it in the same commit that makes it untrue (`.claude/rules/backpressure.md`).

### Success Criteria:

#### Automated Verification:
- [ ] The characterization suite passes **before** either patch — committed on its own
- [ ] The same suite passes **after** both patches (the regression check). Name the command that ran
- [ ] Integration: a photo associated to a provenance-carrying album via `pwg.images.setCategory`
      inherits all four values `[HAPPY]`
- [ ] Integration: a photo associated to an album with no provenance gets nothing, and no history
      row is written `[NEG]`
- [ ] Integration: a photo discovered by filesystem sync inherits — needs a fixture that places a
      real file on disk and drives the sync path (see Phase 10's FixtureBuilder work, delivered here)
- [ ] Integration: inheritance does not overwrite an existing `provenance_note` `[NEG]`
- [ ] `ddev exec php -l admin/include/functions.php admin/site_update.php`

#### Manual Verification:
- [ ] Upload a photo into a provenance-carrying album through the normal admin UI and confirm it
      arrives with the values — exercises the upload path end to end, which no fixture drives

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 8: Public `#Provenance` row

### Overview
Showing provenance on the photo page, with a visibility toggle so it is not forced on every install.

### Changes Required:

#### [ ] 1. Public prefilter
**File**: `plugins/provenance/include/events_public.inc.php`
**Changes**: registered on `loc_end_picture` guarded by `script_basename() == 'picture'`; injects one
row into `<dl id="standard">` immediately before the anchor, held as a named constant matching
`themes/default/template/picture.tpl:303`:

```php
define('PROVENANCE_TPL_INJECT_POINT', '{if isset($metadata)}');
```

The row follows the existing shape exactly, and the values come from `{$current.provenance_*}` —
`picture.php:461-465` already loaded them with `SELECT *`, so no extra query.

```smarty
{if $display_info.provenance and isset($PROVENANCE_TEXT)}
<div id="Provenance" class="imageInfo">
  <dt>{'Provenance'|@translate}</dt><dd>{$PROVENANCE_TEXT}</dd>
</div>
{/if}
```

#### [ ] 2. Visibility toggle
**File**: `plugins/provenance/maintain.class.php`
**Changes**: `install()` adds a `provenance` key to the serialized `$conf['picture_informations']`
map (seeded at `install/config.sql:52-57`, edited at `admin/configuration.php:280-283,527`);
`uninstall()` removes it. Without the key the row would render unconditionally.

### Success Criteria:

#### Automated Verification:
- [ ] Unit: structural guard — `PROVENANCE_TPL_INJECT_POINT` occurs exactly once in `picture.tpl`,
      with a byte-count anti-vacuity guard first
- [ ] Integration: the picture page **source** contains `id="Provenance"` with the expected text,
      scanned with `<script>` blocks stripped and guarded by an assertion that stripping left markup
      behind (the trap `docs/agents/TESTING.md` records for typetags)
- [ ] Integration: with the `picture_informations` key off, the row is absent `[DT]`
- [ ] E2E: `provenance.spec.js` — the row is visible in the rendered DOM in both `default` and
      `modus`; every locator lives in `tests/e2e/support/PicturePage.js`, none in the spec
- [ ] `ddev exec php -l` on every changed file

#### Manual Verification:
- [ ] The row reads correctly in German (the local install's locale) and does not break the info
      panel layout on a narrow viewport
- [ ] `rm -rf _data/templates_c/*` after the prefilter edit, then confirm the row appears

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 9: Move, dissociate and album-delete prompts

### Overview
Decisions Q7 and Q8 answered "ask the user". That is a confirmation step to build, plus a documented
default for the API paths that have no UI.

### Changes Required:

#### [ ] 1. Documented default for unattended paths
**File**: `plugins/provenance/include/events_inherit.inc.php`
**Changes**: `pwg.images.setCategory` and `move_images_to_categories()` have no UI to prompt in. The
default when unattended is **keep the existing values** — a move never silently rewrites provenance;
the new album's values arrive only when an admin runs apply. Recorded as a decision file so it is
cited later rather than re-litigated.

#### [ ] 2. Move / dissociate prompt
**File**: `plugins/provenance/template/album_provenance.js` and the Batch Manager panel
**Changes**: when a moved photo carries provenance from its old album, the admin is asked: keep,
clear, or replace with the destination album's values. The choice travels as an explicit parameter,
so the WS path and the UI path share one code path with different defaults.

#### [ ] 3. Album-delete prompt
**File**: `plugins/provenance/include/events_admin.inc.php`
**Changes**: extend the **existing** `$photo_deletion_mode` prompt (`delete_categories()`,
`admin/include/functions.php:53-151`) rather than inventing a second one. Cleanup hooks on
`trigger_notify('delete_categories', $ids)` at `:149`, which fires after the rows are gone — so the
album's values are read **before** deletion if they are to be preserved.

### Success Criteria:

#### Automated Verification:
- [ ] Integration: move with `keep` — values unchanged `[ST]`
- [ ] Integration: move with `clear` — the four album-sourced columns are NULL, `provenance_note`
      untouched `[ST]`
- [ ] Integration: move with `replace` — destination album's values present `[ST]`
- [ ] Integration: `pwg.images.setCategory` with no parameter defaults to `keep` `[DT]`
- [ ] Integration: album deleted with `photo_deletion_mode='no_delete'` — surviving photos keep
      their inherited values `[ST]`
- [ ] Integration: history rows record each of the above with the right `source`
- [ ] `ddev exec php -l` on every changed file

#### Manual Verification:
- [ ] The move prompt appears in the Batch Manager and its three choices behave as labelled
- [ ] The delete prompt reads clearly alongside the existing photo-deletion choice

**Implementation Note**: Pause for manual confirmation before proceeding.

---

## Phase 10: Close-out — strength check and documentation

### Overview
One mutation-testing pass over the unit suite (`.claude/rules/mutation-testing.md`: end of plan,
once, unit layer only, recorded as prose), plus the documentation the rules require.

### Changes Required:

#### [ ] 1. Mutation table
**File**: `docs/agents/TESTING.md`
**Changes**: a mutant → expected-killer → result table over the Phase 2 pure functions. Minimum set:

| Mutant | Expected killer |
|---|---|
| `>` → `>=` on the `PROVENANCE_IPTC_MAX_BYTES` comparison | the boundary pair at 1999/2000/2001 bytes |
| `strlen` → `mb_strlen` in the truncator | the multi-byte truncation test |
| separator constant emptied | the composition order test |
| `!empty($part)` → `isset($part)` in the composer | the whitespace-only-part test |
| field order array reversed | the deterministic-caption test |
| `-charset iptc=UTF8` line dropped from the argfile builder | the argfile line-order test |

Survivors are recorded as findings with which of the two explanations applies (weak test, or an
unreachable boundary) — not swapped for an easier mutant.

#### [ ] 2. `docs/agents/TESTING.md`
**Changes**: new suite section, the technique legend reference, the deliberate non-coverage table
(everything in *What We're NOT Doing* that a reader might mistake for a gap), the dated throughput
measurement from Phase 6, and hand-check ledger entries for: the external-viewer check, the
narrow-viewport layout check, and the upload-path inheritance check.

#### [ ] 3. Decision records
**Files**: `docs/agents/decisions/0007-…` onward, one per file (0006 was taken in Phase 2)
**Changes**: (a) provenance lives in its own plugin, not typetags, not core; (b) album-sourced
fields stay out of `use_iptc_mapping`/`use_exif_mapping`, which is what prevents the sync revert
loop; (c) `allow_html_descriptions` is not honoured for provenance text; (d) move defaults to
`keep` on unattended API paths; (e) no history retention in v1.

#### [ ] 4. `CLAUDE.md`
**Changes**: the new plugin, its suites and their one-command invocations, the exiftool dependency
and the `webimage_extra_packages` entry, the two core patches, and the `_data/provenance/` working
area. Anything the plan made untrue is corrected here (the `site_update.php` "no triggers" claim was
already fixed in Phase 7).

#### [ ] 5. `docs/backlog.md`
**Changes**: tick nothing off silently; add the growth path for the history table and note the two
existing low-priority provenance items are unchanged by this plan.

### Success Criteria:

#### Automated Verification:
- [ ] Full unit suite: `ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml`
- [ ] Full suite, both plugins, twice consecutively and in reverse order, with no manual repair
- [ ] Integration and E2E green (commands in *Test Commands*)
- [ ] `bash tools/test-hooks.sh`
- [ ] Every count written into `TESTING.md` carries the date it was measured

#### Manual Verification:
- [ ] Every mutant in the table was actually applied and reverted by hand; no result is asserted
      from reasoning alone
- [ ] The hand-check ledger has an entry per surviving manual item, each with the reason it cannot
      be automated

---

## Testing Strategy

Placement follows `.claude/rules/testing.md`: lowest layer that can express the behaviour, never
restated higher up. The anti-regression cross-check applies — break a Phase 2 function and its unit
test must go red *before* any integration or E2E test does.

### Test Design Techniques Applied

Legend per `.claude/rules/test-design.md`: `[HAPPY]` `[NEG]` `[ECP]` `[BVA]` `[ST]` `[DT]` `[ERR]`.

Techniques recorded as not applicable, with the reason:
- `[ST]` does not apply to the Phase 2 pure functions — they hold no state.
- `[DT]` does not apply to `provenance_compose_caption()` — one condition (part empty or not) per
  part, two outcomes; the equivalence classes cover it.

### Unit Tests (base — fast, isolated, exhaustive)

`provenance_compose_caption()`:
- [x] all five parts present → joined in `provenance_field_order()` order with the separator `[HAPPY]`
- [x] one part empty string → omitted, no doubled separator `[ECP]`
- [x] one part whitespace-only → omitted `[ECP]`
- [x] all parts empty → returns `''` `[BVA]`
- [x] exactly one part → no separator anywhere `[BVA]`
- [x] parts containing the separator itself → not re-split, passed through `[ERR]`
- [x] leading/trailing whitespace trimmed per part `[ECP]`

`provenance_truncate_for_iptc()`:
- [x] text of 1999 bytes → unchanged, `truncated === false` `[BVA]`
- [x] text of exactly 2000 bytes → unchanged, `truncated === false` `[BVA]`
- [x] text of 2001 bytes → truncated, `truncated === true`, result ≤ 2000 bytes `[BVA]`
- [x] empty string → unchanged, not flagged `[BVA]`
- [x] multi-byte character straddling the boundary → result is valid UTF-8
      (`mb_check_encoding`), never a split character `[ERR]`
- [x] a string of only multi-byte characters exceeding the cap → valid UTF-8, ≤ 2000 bytes `[BVA]`
- [x] the truncation mark itself fits inside the budget `[BVA]`

`provenance_build_argfile()`:
- [x] all fields present → exact line sequence, `-charset`/`iptc=UTF8` first `[HAPPY]`
- [x] IPTC line carries the truncated text while EXIF/XMP lines carry the full text `[DT]`
- [x] an empty field emits no line for that tag `[ECP]`
- [x] all fields empty → no tag lines at all (caller must not invoke exiftool) `[BVA]`
- [x] a value containing a newline → rejected or escaped, never producing two argfile lines `[NEG]`
- [x] a value containing `-` at line start → still parsed as a value, not a flag `[NEG]`

`provenance_lock_path()`:
- [x] two different image paths → two different lock paths `[HAPPY]`
- [x] the same path twice → the same lock path (deterministic) `[HAPPY]`
- [x] the lock path is never equal to the image path `[NEG]`

Input validation helpers (Phase 4):
- [ ] date `2026-04-19` accepted; `2026-13-01` rejected (`checkdate`); `2026-4-9` rejected;
      `''` accepted as "not set"; `19.04.2026` rejected `[ECP]` `[NEG]` `[BVA]`
- [ ] a 255-byte `owner` accepted; 256 bytes rejected `[BVA]`
- [ ] `<b>x</b>` → `x` after `strip_tags()` `[NEG]`

Structural guards (they run in the normal unit suite; nothing else would report these regressions):
- [ ] `PROVENANCE_TPL_ALBUM_ANCHOR` occurs exactly once in `cat_modify.tpl`, after a
      `strlen()` lower-bound guard
- [ ] `PROVENANCE_TPL_INJECT_POINT` occurs exactly once in `picture.tpl`, same guard
- [x] `pwgprov.config` declares the namespace URI the writer uses — one constant, read by both

#### Regression — affected existing functionality
- [ ] `plugins/typetags` full unit + integration suites — the two core patches sit in files typetags
      does not touch, but `associate_images_to_categories()` is on the upload path that seeds its
      fixtures. Run them and name the command.
- [ ] `CoreAssociationCharacterizationTest` — the Phase 7 net, run before and after the patches.

### Integration Tests (middle — real DB, real `ws.php`)

Fixtures (`plugins/provenance/tests/Support/FixtureBuilder.php`) **force** their precondition and
assert it took effect. `anyImageId()`-style `SELECT … LIMIT 1` lookups are not used: the album a
photo belongs to *is* the thing under test. The builder gains — create an album; create an image row
linked to a named album; create an image in two albums (the case with no data on this install);
place real PNG/JPEG/TIFF files on disk; snapshot and restore all of it.

Fixture provenance is recorded per fixture: which case it covers and how it is built. A new fixture
must cover a case that actually differs.

**Happy path:**
- [ ] `AlbumSaveTest` — `setAlbumInfo` persists all four columns `[HAPPY]`
- [ ] `ApplyTest` — apply copies four values onto every photo in the album `[HAPPY]`
- [ ] `WriteBackTest` — all five MWG slots and all five `XMP-pwgprov:*` tags read back `[HAPPY]`
- [ ] `InheritTest` — a photo joining afterwards inherits `[HAPPY]`
- [ ] `HistoryTest` — a >255-byte value round-trips through `pwg.provenance.getHistory` `[HAPPY]`

**Negative / error propagation:**
- [ ] guest → 401; bad token → 403; unknown id → 404; malformed date → 400, on every WS method `[NEG]`
- [ ] apply never writes `provenance_note` `[NEG]`
- [ ] apply modifies no image file (mtime + size unchanged) `[NEG]`
- [ ] `exec` unavailable → typed error, no file touched `[NEG]`
- [ ] a write failure leaves a history row and removes the operation directory `[NEG]`
- [ ] inheritance from an album with no provenance writes nothing and logs nothing `[NEG]`

**Boundary / edge:**
- [ ] text over 2000 bytes → full in XMP/EXIF, truncated in IPTC, truncation logged `[BVA]`
- [ ] `Łódź Ω 日本 Müller` survives `clean_iptc_value()` `[ERR]`
- [ ] empty album fields clear the photo columns (NULL, not `''`) `[BVA]`
- [ ] an album with zero photos — apply succeeds, writes nothing `[BVA]`
- [ ] **concurrency**: N parallel writers on one file; the file exists and is readable afterwards.
      Written and watched **red with locking disabled** before locking is enabled — this is the one
      measured data-loss mode `[NEG]`
- [ ] pixels unchanged after a write (IDAT sha256), with a byte lower-bound guard first `[ERR]`
- [ ] `_original` unchanged after the second and third write `[ERR]`

**Deliberately failing (skipped) tests** — known gaps, visible in every run rather than buried in
prose (`.claude/rules/test-design.md`):
- [ ] a photo in **two** albums: which album's provenance applies. Skipped with the reason and a
      link to the `docs/backlog.md` 1:1 item. Un-skipping it is the first step of that fix.
- [ ] file-vs-DB divergence after a third-party edit is detected. Skipped, decision 4a, backlog item.

### End-to-End Tests (top — real browser)

Every locator lives in a page object; a locator in a spec is a bug. `retries: 0`, `workers: 1`,
tracing on, no bare sleeps — wait on locator and network state. Specs are drafted against the live
app, not written from reading the templates.

- [ ] `album-provenance.spec.js` — open the modal, fill four fields, save, reload, values persist `[HAPPY]`
- [ ] `album-provenance.spec.js` — apply to photos, progress completes, a photo page shows the values `[HAPPY]`
- [ ] `picture-provenance.spec.js` — the `#Provenance` row is visible in the rendered DOM `[HAPPY]`
- [ ] `album-provenance.spec.js` — an application-level failure (HTTP 200 with `stat:"fail"`) surfaces
      an error in the UI `[NEG]`. Distinct from the next case, which hits a different client path
- [ ] `album-provenance.spec.js` — a network-level failure (aborted request) surfaces an error `[NEG]`

### Manual Testing Steps

Only what cannot be automated; each becomes a dated hand-check ledger entry in `TESTING.md`:

1. Open a written file in an external viewer and confirm a normal photo tool shows the caption.
2. Confirm the injected album block and the `#Provenance` row look right at a narrow viewport.
3. Upload a photo through the real admin UI into a provenance-carrying album and confirm inheritance.
4. Confirm the pre-commit gate blocks a staged `|| true` in a `plugins/provenance/tests/` file.

### Test Commands

```bash
# Unit (both plugins)
ddev exec plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml
ddev exec plugins/typetags/vendor/bin/phpunit  --testsuite unit --configuration plugins/typetags/phpunit.xml

# Integration (DDEV up; credentials from the environment)
ddev exec bash -c 'PROVENANCE_TEST_USERNAME=<user> PROVENANCE_TEST_PASSWORD=<pass> \
  plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'

# E2E (DDEV up)
ddev exec bash -c 'cd plugins/provenance && PROVENANCE_TEST_USERNAME=<user> PROVENANCE_TEST_PASSWORD=<pass> \
  npx playwright test'

# Full regression, both plugins
ddev exec bash -c 'PROVENANCE_TEST_USERNAME=<user> PROVENANCE_TEST_PASSWORD=<pass> \
  plugins/provenance/vendor/bin/phpunit --configuration plugins/provenance/phpunit.xml'
ddev exec bash -c 'TYPETAGS_TEST_USERNAME=<user> TYPETAGS_TEST_PASSWORD=<pass> \
  plugins/typetags/vendor/bin/phpunit --configuration plugins/typetags/phpunit.xml'

# Syntax at container PHP version
ddev exec php -l <file>

# Commit gate self-test
bash tools/test-hooks.sh
```

A fresh clone needs `ddev exec composer install -d plugins/provenance` and
`ddev exec bash -c 'cd plugins/provenance && npm install'` first.

**The integration and E2E suites mutate the real database and place real files on disk. Neither is
safe against a production install.** Stated, not assumed.

## Performance Considerations

- **Production ceiling is 60 s per request** (`max_execution_time`, measured on the live host);
  local DDEV has none, so nothing that passes locally proves anything about production. Both apply
  and write-back are client-chunked; the write-back chunk constant is set from the Phase 6
  measurement, recorded with its date.
- **exiftool pays Perl startup per invocation.** The writer batches multiple files into one
  invocation where the lock discipline allows; `-stay_open` is explicitly out of scope.
- **Disk grows by roughly one extra copy per written file, once** — `_original` is never overwritten
  by a later write (measured). Kept as the safety net per decision 7b.
- **History rows**: one per *changed* field, so an unchanged re-apply writes none. A 76-photo album
  with four changed fields writes 304 rows per apply. No purge in v1; the growth path is recorded.
- The `#Provenance` row costs **no extra query** — `picture.php:461-465` already does `SELECT *`.

## Migration Notes

- All nine columns are nullable with no default, so existing rows are unaffected and the feature is
  invisible until an admin enters something.
- `uninstall()` drops the columns and the table — the plugin is fully reversible on the DB side.
  Metadata already written into image files is **not** reverted; that is stated in the plugin
  description rather than silently true.
- The two core patches are additive `trigger_notify` calls with no behaviour change when no handler
  is registered, so uninstalling the plugin leaves core functional.
- Existing photos inherit nothing retroactively — an admin runs apply once per album.

## References

- Research: `docs/agents/research/2026-08-29-per-photo-freetext-field-and-metadata-writeback.md`
  (decisions Q1–Q15, conflicts C1–C9, follow-ons 1–10, and every measurement cited above)
- Prefilter + guarded WS write precedent: `plugins/typetags/include/events_public.inc.php:4-5`,
  `:131-190`; `plugins/typetags/main.inc.php:189-238`
- Idempotent schema idiom: `plugins/typetags/maintain.class.php:20-57`
- Shell-out degradation pattern to copy: `admin/include/image.class.php:393-410`
- Chunked-AJAX progress pattern: `admin/themes/default/js/batchManagerGlobal.js:239-309`
- Album screen injection: `admin.php:406`, `admin/cat_modify.php:167`, `:393`;
  `admin/themes/default/template/cat_modify.tpl:141`, `:188`, `:191-203`
- Core patch sites: `admin/include/functions.php:2094-2098`; `admin/site_update.php:676-681`
- Picture page: `picture.php:461-465`, `:1019`; `themes/default/template/picture.tpl:172-303`
- Rules: `.claude/rules/{testing,test-design,mutation-testing,e2e-tests,backpressure,precommit-hooks}.md`
