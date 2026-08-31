---
date: 2026-08-30T06:28:53+00:00
git_commit: 0cad900420e132f8fea17f957dc21c2973a26cea
branch: feat/provenance-metadata
topic: "Person / face tagging plugin"
tags: [plan, persons, faces, mwg-regions, exiftool, picture-page, web-services, plugins]
status: approved
---

# Person / Face Tagging Implementation Plan

## Overview

Add a fork-local plugin `plugins/persons` that lets a logged-in user draw a box around a person in
a photo, name that person (picking an existing one by partial search or creating a new one), and
have the region written into the image file as MWG regions plus `XMP-iptcExt:PersonInImage`. The
database holds a rebuildable index so persons can be browsed and counted, and each person is
mirrored as an ordinary Piwigo tag so core browsing works unmodified.

All research findings this plan builds on are in
`docs/agents/research/2026-08-29-person-face-tagging.md`. That document is the source of truth for
everything about the existing code, the MWG spec, and prior art; this plan does not restate it.

## Current State Analysis

Nothing in the repo does person tagging. What exists, and what this plan reuses:

- **Two working plugin precedents.** `plugins/typetags` (interactive widget injected into
  `picture.php` via a Smarty prefilter + plugin-registered WS methods) and `plugins/provenance`
  (own schema, re-entrant `install()`, exiftool write-back with locking and argfiles, three test
  layers). Structure, naming, test harness and commit gate are copied from `provenance`.
- **exiftool 13.25 in the DDEV web image reads and writes `XMP-mwg-rs` with no custom config**
  (verified 2026-08-29). Unlike `plugins/provenance`, no `.config` file is needed.
- **`{$ELEMENT_CONTENT}` occurs exactly once** in `themes/default/template/picture.tpl:133`, and
  `themes/modus/template/picture.tpl` does not exist, so modus inherits it. That single occurrence
  is the public-page injection anchor.
- **The displayed `<img>` is rewritten at runtime.** `themes/modus/js/photo.autosize.js`
  `rvas_choose()` rewrites `src`, `width`, `height` and `usemap` on `#theMainImage` on load and on
  every `resize` (`:145`), and removes `usemap` on HiDPI (`:63`). A click handler on the same
  element navigates prev/next/up when no `usemap` is set (`:148-166`). No ancestor declares
  `position: relative`.
- **`piwigo_image_tag` has a composite `PRIMARY KEY (image_id, tag_id)` and no extra columns**, so
  regions cannot live there. `piwigo_tags.id` is a `smallint` (65,535 ceiling).
- **`get_sql_condition_FandF()`** (`include/functions_user.inc.php:1654`) is the core permission
  filter; `ws.php` does **not** include `admin/include/functions.php`.
- **No lint, no CI.** The mechanical checks are `php -l`, the two plugins' PHPUnit suites,
  Playwright, and `bash tools/test-hooks.sh`.

Constraints discovered that shape the design:

- **exiftool cannot write every format.** Regions can only be stored for files exiftool can write
  (JPEG, TIFF, PNG, WebP, HEIC…). With the file as the source of truth, an unwritable file simply
  cannot carry persons, and the UI must say so rather than fail on save.
- **Flattened tags cannot express multiple regions.** `-RegionName="A"` deletes every existing
  region name. Multi-region writes must use the `-json=` structure form.
- **`images.rotation` is a code `0..3`, not degrees**, and `SrcImage::__construct()`
  (`include/derivative.inc.php:74-92`) swaps width/height for odd codes, so `images.width` /
  `images.height` are the *raw file* dimensions.

## Desired End State

A logged-in, non-guest user viewing a photo they are allowed to see can:

1. See existing person boxes drawn over the photo (hover-revealed, dimmed outside the hovered box).
2. Enter tagging mode, drag a rectangle over a face, and be offered a box-anchored picker
   pre-populated with recently used persons that narrows as they type, with a **Create** entry when
   nothing matches.
3. Press Enter to commit, Esc to cancel. The region is merged into the image's XMP, the DB index
   and the mirrored Piwigo tag are updated, and the box appears named.
4. Click a person's name and land on that person's gallery page (the core `tags` section).
5. An administrator can do the same on a full-size admin screen, see a list of all persons with
   photo counts, rename or delete one, and trigger a rescan that rebuilds the whole index from the
   files.

Verification that the end state is reached: the E2E specs in Phase 6-8 pass, and
`exiftool -G1 -a -struct -XMP-mwg-rs:all <file>` on a tagged photo shows the region, read by a
process that is not the plugin.

### UI Mockups

**Public picture page, view mode (boxes hover-revealed):**

```
 +--------------------------------------------------------------+
 |                                                              |
 |        +-----------+              +-----------+              |
 |        |           |              |           |              |
 |        |   face    |              |   face    |              |
 |        |           |              |           |              |
 |        +-----------+              +-----------+              |
 |         Jane Doe                   John Smith                |
 |                                                              |
 |                                          [ Tag people ]      |
 +--------------------------------------------------------------+
   Persons:  Jane Doe, John Smith
```

**Tagging mode, after dragging a box (picker anchored to the box, collision-avoiding):**

```
 +--------------------------------------------------------------+
 |                                                              |
 |        +===========+                                         |
 |        |           |                                         |
 |        |  drawn    |                                         |
 |        |           |                                         |
 |        +===========+                                         |
 |        +---------------------------+                         |
 |        | Who is this?              |                         |
 |        | [ jan|                  ] |                         |
 |        |---------------------------|                         |
 |        | Jane Doe                  |  <- recent, filtered    |
 |        | Janine Weber              |                         |
 |        |---------------------------|                         |
 |        | + Create "jan"            |                         |
 |        +---------------------------+                         |
 |                            Enter commits - Esc cancels       |
 |                                          [ Done tagging ]    |
 +--------------------------------------------------------------+
```

**A stale region (`AppliedToDimensions` ratio disagrees with the current image):**

```
        +- - - - - -+
        ¦           ¦   dashed + 50% opacity, title="Region may be
        ¦           ¦   out of date: the image was resized since
        +- - - - - -+   it was tagged"
         Jane Doe (?)
```

**Plugin admin screen, `admin.php?page=plugin-persons`:**

```
 Persons                                        [ Rescan all files ]

 Search: [________]

 Name              Photos   Regions
 -----------------------------------------------------------------
 Jane Doe              14        14   [rename] [delete]
 John Smith             9         9   [rename] [delete]
 -----------------------------------------------------------------
 2 persons, 23 regions.   Index last rebuilt 2026-08-30 06:12.
```

### Key Discoveries

- `themes/default/template/picture.tpl:133` — `{$ELEMENT_CONTENT}`, the single public anchor
- `themes/modus/js/photo.autosize.js:35-100, 145` — the runtime `src`/`width`/`height` rewrite
- `themes/modus/js/photo.autosize.js:148-166` — the click-to-navigate handler already on the image
- `include/derivative.inc.php:74-92` — rotation code and the width/height swap
- `include/functions_user.inc.php:1654` — `get_sql_condition_FandF()`, the visibility filter
- `plugins/provenance/include/exiftool.inc.php:88-231` — batch driver, locking, argfiles
- `plugins/provenance/maintain.class.php:185-205` — the re-entrant `ALTER` guard
- `admin/include/functions.php:1709` — `tag_id_from_tag_name()`, find-or-create for the tag mirror
- `admin/picture_coi.php` — the precedent for a full-size rectangle editor on an admin screen

## What We're NOT Doing

- **No face detection.** Manual boxes only. No ML dependency, no confirm/reject flow.
- **No ingest of third-party regions as a bulk import step.** The rescan reads whatever the files
  hold, which incidentally picks up digiKam/Picasa regions, but there is no import wizard, no
  `.picasa.ini` parser, and no Microsoft `MP:RegionInfo` reader.
- **No Microsoft `XMP-MP` write.** MWG + `PersonInImage` only, per research answer 6.
- **No `/person/12-jane` section or permalink routing.** Browsing goes through the mirrored Piwigo
  tag and the existing `tags` section.
- **No face crops / people-thumbnail page.** Research answer 9 defers it.
- **No behaviour change when `images.coi` is edited.** Research answer 9 defers it; the
  stale-region indicator is the only thing that reacts. Rotation *is* handled — see Phase 8.
- **No touch / mobile drag support.** No surveyed product except Facebook ships it; deferred.
- **No changes to Piwigo core files.** Unlike `plugins/provenance`, this plugin needs no new
  `trigger_notify()`; every seam it uses is upstream.
- **No merging or splitting of persons**, and no "this is not X" correction affordance.

## Implementation Approach

The plugin is built bottom-up so that every phase is testable at the lowest layer that can express
it: pure coordinate math first (unit-only), then the file I/O (integration), then the API
(integration), then the two browser surfaces (E2E). Each phase leaves the tree green and
committable.

**Storage model.** The image file is the source of truth. Two tables form a *derived index* that
may be deleted and rebuilt at any time:

```
piwigo_persons          id, name, url_name, tag_id, lastmodified
piwigo_person_region     id, image_id, person_id,
                         area_x, area_y, area_w, area_h,     -- MWG: normalized, CENTER origin
                         applied_w, applied_h,               -- AppliedToDimensions at write time
                         rotation_at_write,                  -- images.rotation when last written
                         region_type,                        -- Face | Pet | Focus | BarCode
                         source                              -- piwigo | foreign
```

Every write is: merge into the file → re-read the file → rebuild that image's index rows from what
the file actually says. The DB never holds a region the file does not.

**Coordinate contract**, stated once and referenced everywhere:

- Stored: normalized `[0..1]`, **center** origin, **pre-rotation** (MWG normative).
- Render: apply `images.rotation` code (`0..3`, odd codes swap w/h) → convert center to top-left →
  multiply by the element's *rendered* box (`getBoundingClientRect()`), never by `width`/`height`
  attributes, which `rvas_choose()` rewrites.
- A region whose `applied_w/applied_h` aspect ratio differs from the current image's by more than
  `PERSONS_STALE_RATIO_TOLERANCE` is rendered dimmed and dashed, not dropped (MWG says a Consumer
  SHOULD ignore such regions; dropping data silently is worse for a gallery owner than flagging).
- A region whose center falls outside `[0..1]` is dropped. A region whose center is inside but
  whose box extends past the edge is **clipped**, per MWG.

**Permission model.** Writes require: not a guest, a matching `pwg_token`, and the image passing
`get_sql_condition_FandF(array('visible_images' => 'id'))` for the calling user. The third check is
new — it closes the question `docs/agents/decisions/0005-tag-assignment-permission-model.md`
explicitly left open — and gets its own decision file.

**Tag mirroring.** On the first region for a person, the plugin calls `tag_id_from_tag_name()` to
find-or-create a `piwigo_tags` row and stores its id in `persons.tag_id`. Adding a region inserts
the `image_tag` pair; removing the last region for that person on that image deletes it. This is
what makes browse, count, permission filtering, the menubar and permalinks work with no new code.

---

## Phase 1: Plugin skeleton and region math

### Overview

An activatable, uninstallable plugin with its schema and every pure coordinate/name helper, plus a
unit suite. Nothing is rendered and no file is touched.

### Changes Required:

#### [x] 1. Repository plumbing
**File**: `.gitignore`
**Changes**: add `!/plugins/persons` after the `!/plugins/provenance` line, or the whole plugin
stays invisible to git.

#### [x] 2. Plugin skeleton
**Files**: `plugins/persons/main.inc.php`, `maintain.class.php`, `index.php` (one per directory),
`composer.json`, `package.json`, `phpunit.xml`, `playwright.config.js`, `.gitignore`

Modelled line-for-line on `plugins/provenance`. `main.inc.php` carries the metadata header, the
folder-name guard, the constants, and registers only `ws_add_methods` in this phase.

```php
/*
Plugin Name: Persons
Version: 1.0.0
Description: Draw a box around a person in a photo and name them. Regions are stored in the
image file as MWG regions; the database holds a rebuildable index.
Plugin URI: https://github.com/christianbaumann/Piwigo
Author: Christian Baumann
Has Settings: true
*/
if (basename(dirname(__FILE__)) != 'persons') { /* register init error, return */ }

if (!defined('PERSONS_PATH')) { define('PERSONS_PATH', PHPWG_PLUGINS_PATH . 'persons/'); }
define('PERSONS_TABLE',        $prefixeTable . 'persons');
define('PERSONS_REGION_TABLE', $prefixeTable . 'person_region');
include_once(PERSONS_PATH . 'include/functions.inc.php');
```

#### [x] 3. Schema, defined once in the pure file
**File**: `plugins/persons/include/functions.inc.php`
**Changes**: the schema is data, read by `maintain.class.php`, by the WS handlers and by
`FixtureBuilder` — never restated.

```php
function persons_person_columns()  // column => DDL fragment
{
  return array(
    'id'           => 'mediumint(8) unsigned NOT NULL AUTO_INCREMENT',
    'name'         => 'varchar(' . PERSONS_NAME_MAX_BYTES . ') NOT NULL',
    'url_name'     => 'varchar(' . PERSONS_NAME_MAX_BYTES . ') BINARY DEFAULT NULL',
    'tag_id'       => 'smallint(5) unsigned DEFAULT NULL',
    'lastmodified' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  );
}
function persons_region_columns() { /* id, image_id, person_id, area_x/y/w/h,
                                       applied_w, applied_h, rotation_at_write,
                                       region_type, source */ }
function persons_region_types()   { return array('Face', 'Pet', 'Focus', 'BarCode'); }
function persons_region_sources() { return array('piwigo', 'foreign'); }
```

`persons.id` is a `mediumint` deliberately: the whole reason for a `persons` table rather than
`piwigo_tags` rows is that `tags.id` is a `smallint`. `UNIQUE KEY name (name)` on `persons`, and
`KEY image_lookup (image_id)` + `KEY person_lookup (person_id)` on `person_region`.

#### [x] 4. `maintain.class.php`
**Changes**: `install()` uses `CREATE TABLE IF NOT EXISTS` built from the two column functions and
is idempotent; `update()` delegates to `install()`; `uninstall()` drops both tables **and** the
mirrored tags it created (a `persons.tag_id` that no other `image_tag` row references), because
leaving orphan tags behind after an uninstall is worse than the extra work.

#### [x] 5. Pure coordinate helpers
**File**: `plugins/persons/include/functions.inc.php`

```php
// MWG center-origin <-> top-left corner
function persons_center_to_corner($x, $y, $w, $h);   // => array(left, top, w, h)
function persons_corner_to_center($l, $t, $w, $h);   // => array(x, y, w, h)

// MWG: drop a region whose CENTER is outside [0..1]; clip one whose box overruns.
function persons_clip_region($region);               // => clipped region, or null to drop

// images.rotation is a code 0..3. Rotate a normalized center-origin region by that code.
function persons_rotate_region($region, $rotation_code);

// Compare stored AppliedToDimensions against the image's current dimensions.
function persons_region_is_stale($applied_w, $applied_h, $image_w, $image_h);

function persons_clean_name($raw);                   // strip_tags, collapse whitespace, byte cap
function persons_is_valid_normalized($value);        // numeric, finite, 0 <= v <= 1
function persons_minimum_box_ok($w, $h);             // >= PERSONS_MIN_BOX_FRACTION on both axes
```

Constants declared here: `PERSONS_NAME_MAX_BYTES` (255), `PERSONS_MIN_BOX_FRACTION` (0.01),
`PERSONS_STALE_RATIO_TOLERANCE` (0.02), `PERSONS_PICKER_RECENT_LIMIT` (10),
`PERSONS_SEARCH_MAX_RESULTS` (25), `PERSONS_LOCK_DIR`, `PERSONS_ARGS_DIR`,
`PERSONS_LOCK_TIMEOUT_SECONDS`, `PERSONS_WRITEBACK_MAX_CHUNK`, and the template anchors.

#### [x] 6. Test harness
**Files**: `plugins/persons/tests/bootstrap.php`, `tests/Support/{Config,TestUsers,Db,WsClient,
FixtureBuilder,PiwigoRuntime}.php`, `tests/Support/create-test-users.php`

Copied and renamed from `plugins/provenance` (`Config.php` there records that it is deliberately a
copy, not a shared file — follow that precedent). `TestUsers::ROLES` is
`persons_webmaster => webmaster`, `persons_normal => normal`; `ENV_FILE` is
`local/config/persons-test.env`; env prefix `PERSONS_TEST_`. `FixtureBuilder` calls
`assertThrowawayInstall()` with a `persons_throwaway_install` config marker.

#### [x] 7. Commit gate
**File**: `.githooks/lib.sh`
**Changes**: add the persons unit-suite command to `UNIT_SUITES` so the pre-commit hook gates it
like the other two plugins. `bash tools/test-hooks.sh` must still pass.

### Success Criteria:

#### Automated Verification:
- [x] `ddev exec php -l` clean on every new PHP file
- [x] Unit suite passes: `ddev exec plugins/persons/vendor/bin/phpunit --testsuite unit --configuration plugins/persons/phpunit.xml`
- [x] Hook self-test passes: `bash tools/test-hooks.sh`
- [x] `git status` shows the plugin directory as tracked (the `.gitignore` `!` entry works)

#### Manual Verification:
- [x] Plugin appears in Admin > Plugins, activates without error, and `SHOW TABLES LIKE 'piwigo_person%'` returns both tables
- [x] Deactivate then activate again — no error (install is re-entrant)
- [x] Uninstall drops both tables

**Implementation Note**: pause for manual confirmation before Phase 2.

---

## Phase 2: Read regions out of a file and build the index

### Overview

Parse MWG regions out of an image with exiftool and rebuild that image's index rows from the
result. Read-only: no file is modified.

### Changes Required:

#### [x] 1. exiftool read
**File**: `plugins/persons/include/exiftool.inc.php`

```php
function persons_exiftool_binary();      // $conf['persons_exiftool_path'] . 'exiftool'
function persons_exiftool_available();   // function_exists('exec') first, then -ver probe

// Returns array('ok'=>bool, 'regions'=>array, 'applied'=>array(w,h), 'names'=>array, 'message'=>string)
function persons_read_regions($file);
```

Invocation — no `-config`, `mwg-rs` is built into exiftool 13.25:

```
exiftool -json -struct -charset filename=UTF8 \
  -XMP-mwg-rs:RegionInfo -XMP-iptcExt:PersonInImage <file>
```

The JSON decode and the walk from `RegionInfo.RegionList[].Area{X,Y,W,H}` into the plugin's region
shape is a **pure function** (`persons_parse_regioninfo($decoded)`) in `functions.inc.php`, so it
is unit-testable against fixture JSON with no exiftool present. `exiftool.inc.php` only shells out
and hands it the decoded array.

Defensive cases the parser must handle, all seen in the wild or documented in the research:
`RegionList` present as a single object rather than an array (one region); numeric values arriving
as strings (the bug Immich PR #29333 fixed); `AppliedToDimensions` absent entirely (digiKam bug
429219) — treat as unknown, never as stale; `Unit` absent or not `normalized`; a region with an
`Area` but no `Name` (an unnamed detected face — skip, this plugin has no unconfirmed state).

#### [x] 2. Index rebuild
**File**: `plugins/persons/include/index.inc.php`

```php
// Replace this image's index rows with exactly what the file says. Transactional per image.
function persons_reindex_image($image_id, $file_path);

// Find-or-create the person row AND its mirrored piwigo_tags row.
function persons_person_id_from_name($name);

// Sync piwigo_image_tag to match this image's regions.
function persons_sync_image_tags($image_id);
```

`persons_person_id_from_name()` includes `admin/include/functions.php` itself before calling
`tag_id_from_tag_name()` — `ws.php` does not include it.

#### [x] 3. Rescan entry point
**File**: `plugins/persons/include/rescan.inc.php`
**Changes**: `persons_rescan_images(array $image_ids)` looping `persons_reindex_image()`, chunked
by `PERSONS_WRITEBACK_MAX_CHUNK`, returning `array('scanned'=>int, 'failed'=>array(id=>message))`.
A file that is missing, unreadable, or that exiftool cannot parse is recorded as failed and the
loop continues — it never aborts the batch.

### Success Criteria:

#### Automated Verification:
- [x] Unit suite passes (parser fixtures)
- [x] Integration suite passes: `ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; plugins/persons/vendor/bin/phpunit --testsuite integration --configuration plugins/persons/phpunit.xml'`
- [x] An integration test writes a two-region file with `exiftool -json=`, calls `persons_reindex_image()`, and asserts both `persons` rows, both `person_region` rows and both `image_tag` rows exist

#### Manual Verification:
- [x] Point the rescan at a photo tagged in digiKam and confirm the names land in `piwigo_persons` —
      **automated**, no longer a manual box: `ReindexTest::testARescanIndexesAFileTaggedByAThirdPartyToolWithNoAppliedToDimensions`
      seeds the file in digiKam's shape (no `AppliedToDimensions`, KDE bug 429219) with a plain exiftool
      call and drives `persons_rescan_images()`. Watched red against two mutants. Running an actual
      digiKam binary is not automatable and is recorded in the open table of `docs/agents/TESTING.md`

**Implementation Note**: pause for manual confirmation before Phase 3.

---

## Phase 3: Write regions into the file, merging

### Overview

The dangerous phase, and the one the whole design rests on: with regions stored nowhere else, a
partial merge loses data outright. Read-merge-write, always through a lock, always leaving
exiftool's `_original` sidecar.

### Changes Required:

#### [x] 1. The merge, as a pure function
**File**: `plugins/persons/include/functions.inc.php`

```php
// $existing: regions parsed out of the file (may include foreign ones, e.g. Pet, BarCode).
// $add / $remove: this operation's changes.
// Returns the complete RegionInfo structure to write. Never drops a region it did not touch.
function persons_merge_regions($existing, $add, $remove, $applied_w, $applied_h);
```

This is the review point that closed Immich's write-back PR and the reason the Piwigo Face Tag
Editor rewrote its writer. It is pure, so it is unit-tested exhaustively rather than through the
file system.

#### [x] 2. The write
**File**: `plugins/persons/include/exiftool.inc.php`

```php
function persons_write_regions($image_id, $file, $regioninfo, $names);
function persons_lock_acquire($db_path);   // same shape as provenance: a separate sha1(path).lock
```

The write uses the **`-json=` form**, not flattened tags and not the brace syntax — from PHP it
sidesteps exiftool's escaping rules entirely:

```
exiftool -charset filename=UTF8 -json=<operation_dir>/<image_id>.json <file>
```

with the JSON file holding `RegionInfo` (`AppliedToDimensions` + the complete merged `RegionList`)
and `PersonInImage` (the complete name list). `SourceFile` is omitted.

Copied verbatim in shape from `plugins/provenance/include/exiftool.inc.php`: argfile/JSON written
into `_data/persons/args/<operation id>/` and removed in a `finally`; a `flock` on a separate
`_data/persons/locks/<sha1(path)>.lock` because exiftool replaces the image by rename; **no**
`-overwrite_original`, so the `_original` sidecar is the pre-write copy; a per-image failure is
recorded and the batch continues.

#### [x] 3. Write-then-reindex
**File**: `plugins/persons/include/index.inc.php`
**Changes**: `persons_apply_change($image_id, $add, $remove)` = read file → merge → write → **re-read
the file** → reindex. The re-read is not redundant: it is the only thing that proves the DB index
matches the bytes on disk.

#### [x] 4. `_data/persons/` documentation
**File**: `.claude/rules/piwigo-dev-environment.md` (not `CLAUDE.md`: that file is capped at
100 lines and the `_data/provenance/` subsection this one mirrors already lives in the rules file —
two homes for one fact is the copy that goes stale)
**Changes**: a subsection mirroring the existing `_data/provenance/` one — what `locks/` and
`args/` are, that both are safe to delete when nothing is writing, and that the `_original`
sidecars sit beside the image and are the only copy of the pre-write bytes.

### Success Criteria:

#### Automated Verification:
- [x] Unit suite passes, including the merge table
- [x] Integration: writing person B into a file that already holds person A leaves both
- [x] Integration: a foreign region (`Type=Pet`, or a region this plugin never wrote) survives a write
- [x] Integration: an independent reader confirms the write — `exiftool -json -struct` run as a
      separate process, the same technique as `provenance`'s `WriteBackTest`
- [x] Integration: N concurrent writers to the same file (the `write-back-worker.php` pattern) all
      succeed and the final file holds every region
- [x] Integration: a read-only file is reported as failed, the file is unchanged, and no `_original` is left

#### Manual Verification:
- [x] Open a written file in digiKam or exiftool GUI and confirm the face shows in the right place —
      falsifiable half **automated**: `WriteRegionsTest::testAnIndependentLibraryFindsTheRegionInTheStandardXmpPacket`
      extracts the XMP packet with ImageMagick, which has no MWG support at all, and asserts the
      namespace, the element names and all four coordinates as text. Watched red against a write
      with `RegionInfo` emptied; it found the `persons_clip_region()` float defect on its first
      run. Whether a GUI *draws* the box where a human expects has no oracle — open table in
      `docs/agents/TESTING.md`
- [x] Confirm an `_original` sidecar exists beside the written image — **automated**:
      `WriteRegionsTest::testTheOriginalBytesAreKeptAsASidecar`

**Implementation Note**: pause for manual confirmation before Phase 4.

---

## Phase 4: Web-service methods and the permission model

### Overview

The API the two browser surfaces will use, with the permission gate research answer 3 specified.

### Changes Required:

#### [x] 1. Method registration
**File**: `plugins/persons/main.inc.php`

| Method | Params | Gate |
|---|---|---|
| `pwg.persons.getList` | `q` (optional), `image_id` (optional), `per_page` | non-guest |
| `pwg.persons.getRegions` | `image_id` | non-guest + image visible |
| `pwg.persons.addRegion` | `image_id`, `name`, `x`, `y`, `w`, `h`, `type`, `pwg_token` | non-guest + token + image visible |
| `pwg.persons.deleteRegion` | `region_id`, `pwg_token` | non-guest + token + image visible |
| `pwg.persons.rename` | `person_id`, `name`, `pwg_token` | `admin_only` |
| `pwg.persons.delete` | `person_id`, `pwg_token` | `admin_only` |
| `pwg.persons.rescan` | `image_ids`, `pwg_token` | `admin_only` |

Implementations live in `include/ws_functions.inc.php`, passed as `addMethod()`'s fifth argument so
the file loads lazily. No `post_only` on the non-admin methods, per
`docs/agents/decisions/0003-no-post-only-on-ws-methods.md`.

#### [x] 2. The visibility gate
**File**: `plugins/persons/include/ws_functions.inc.php`

```php
// Returns true when the calling user is allowed to see this image at all.
// Closes the question decision 0005 left open: neither typetags method checks this.
function persons_user_can_see_image($image_id)
{
  $query = '
SELECT COUNT(DISTINCT ic.image_id)
  FROM ' . IMAGE_CATEGORY_TABLE . ' AS ic
  WHERE ic.image_id = ' . (int)$image_id . '
    ' . get_sql_condition_FandF(
          array('forbidden_categories' => 'ic.category_id',
                'visible_categories'   => 'ic.category_id',
                'forbidden_images'     => 'ic.image_id'),
          'AND') . '
;';
  return pwg_db_fetch_row(pwg_query($query))[0] > 0;
}
```

An image the caller cannot see returns `PwgError(404)`, not 403 — a 403 would confirm the image
exists, which is exactly what the gate is hiding. Faces are personal data; this asymmetry is
deliberate and gets a comment saying so.

#### [x] 3. Search
**Changes**: `pwg.persons.getList` with no `q` returns the `PERSONS_PICKER_RECENT_LIMIT` most
recently used persons (`ORDER BY persons.lastmodified DESC`), matching the Facebook patent's
"list of previously used tags" that is visible *before* typing. With `q`, it is a
`LIKE '%<escaped>%'` on `name` ordered by `pwg_transliterate()`-comparable name, capped at
`PERSONS_SEARCH_MAX_RESULTS`.

This is a **server-side** search, unlike core's tag picker, which ships the whole list to
localStorage. Persons are personal data and the list is not bounded by a smallint, so shipping all
of them to every browser is the wrong trade.

#### [x] 4. Decision file
**File**: `docs/agents/decisions/NNNN-person-region-permission-model.md`
**Changes**: record that person writes gate on non-guest + `pwg_token` + per-image visibility,
that this deliberately goes beyond decision 0005, that a hidden image answers 404 not 403, and that
rename/delete/rescan are `admin_only` because they affect every photo at once.
(Confirm the next free number against `docs/agents/decisions/` at implementation time. Phase 1
took 0017 and 0018.)

### Success Criteria:

#### Automated Verification:
- [x] Integration suite passes
- [x] Guest is rejected 401 on every non-admin method
- [x] `persons_normal` succeeds on a visible image and gets 404 on an image in a forbidden album
- [x] `persons_normal` is refused by `rename`/`delete`/`rescan` (`admin_only`). Core answers **401**,
      not 403 (`include/ws_core.inc.php:515`); `AdminOnlyTest` records that as `[ERR]`, see
      [decision 0019](../decisions/0019-person-region-permission-model.md)
- [x] Bad token 403, empty token 1002, missing token 1002, `image_id` of 0/-1 gives 1003

#### Manual Verification:
- [x] Call `pwg.persons.getList` from the browser as a normal user and confirm the recency ordering —
      **automated 2026-08-30** by `SearchTest::testGetListWithNoQueryReturnsRecentPersonsMostRecentFirst`
      and `testTaggingAnExistingPersonAgainMovesThemToTheFrontOfTheRecentList`, both called over
      ws.php as `persons_normal` with a session cookie — the same request a browser issues. The
      second found a real defect; see the hand-check ledger in `docs/agents/TESTING.md`

**Implementation Note**: pause for manual confirmation before Phase 5.

---

## Phase 5: Public picture page — read-only overlay

### Overview

Draw existing regions over `#theMainImage` and keep them correct while modus rewrites that element
underneath them. No editing.

### Changes Required:

#### [x] 1. Injection
**File**: `plugins/persons/include/events_public.inc.php`

```php
define('PERSONS_TPL_INJECT_POINT', '{$ELEMENT_CONTENT}');

function persons_picture_overlay()   // on loc_end_picture, only when script_basename()=='picture'
{
  // bail for guests; bail when the image has no regions
  $template->assign(array('PERSONS_REGIONS' => ..., 'PERSONS_IMAGE_ID' => ...,
                          'PERSONS_ROTATION' => ..., 'PERSONS_PATH' => PERSONS_PATH));
  $template->set_prefilter('picture', 'persons_picture_prefilter');
}

function persons_picture_prefilter($content)
{
  if (strpos($content, PERSONS_TPL_INJECT_POINT) === false) { return $content; }  // sub-templates
  $stage = '<div id="persons-stage">' . PERSONS_TPL_INJECT_POINT
         . '<div id="persons-overlay"></div></div>';
  return str_replace(PERSONS_TPL_INJECT_POINT, $stage, $content);
}
```

The wrapper is what supplies `position: relative` — no ancestor of `#theMainImage` declares it.
CSS and JS load from inside the injected `.tpl` via `{combine_css}` / `{combine_script}`, not from
PHP, matching `provenance`.

#### [x] 2. The overlay module
**File**: `plugins/persons/template/overlay.js`, `overlay.css`

Positioning rule, stated once in the file header and implemented once:

```js
// The only truthful source of the image's on-screen box. rvas_choose() rewrites
// src/width/height on #theMainImage on load and on every resize
// (themes/modus/js/photo.autosize.js:35-100,145), so the width/height attributes
// and any cached measurement are stale the moment the window moves.
function stageRect() { return document.getElementById('theMainImage').getBoundingClientRect(); }
```

Redraw is triggered by: `DOMContentLoaded`, the image's `load` event (fires again on every `src`
rewrite), and a debounced `resize`. A `ResizeObserver` on `#theMainImage` covers the case where
`rvas_choose()` changes only the attributes without a new `load`.

Rendering: absolutely positioned `div`s inside `#persons-overlay`, `pointer-events: none` in view
mode so the existing click-to-navigate handler
(`themes/modus/js/photo.autosize.js:148-166`) and the `<area>` map keep working untouched. Boxes
are hidden until hover over the stage, and hovering one box dims the area outside it — the two
refinements research found worth copying (Immich PR #26667, #27402).

#### [x] 3. Read-only person row
**File**: `plugins/persons/template/public_persons.tpl`
**Changes**: a `<div id="Persons" class="imageInfo">` row inside `dl#standard`, listing the names
as links to the mirrored tag's gallery page. Gated on core's `picture_informations` map with a
`persons` key seeded by `maintain.class.php`, the same pattern and the same "leave an existing key
alone" rule as `provenance`.

#### [x] 4. Structural guard
**File**: `plugins/persons/tests/Unit/PicturePageAnchorTest.php`
**Changes**: assert `{$ELEMENT_CONTENT}` occurs **exactly once** in
`themes/default/template/picture.tpl` and that `themes/modus/template/picture.tpl` does not exist.
A silently non-matching `str_replace` is exactly the failure `typetags`' `TemplateContractTest`
exists to catch.

### Success Criteria:

#### Automated Verification:
- [x] Unit + integration suites pass
- [x] Integration: the rendered page source contains `#persons-stage` and one `.person-box` per region
- [x] Integration: a guest sees no overlay markup
- [x] E2E: box geometry matches the region within a 2px tolerance at two different window widths,
      proving the redraw survives `rvas_choose()`
- [x] E2E: clicking outside a box still navigates prev/next (the core handler is not broken)

#### Manual Verification:
- [x] Resize the window slowly across a derivative-switch threshold and confirm boxes track the image —
      **automated 2026-08-31**: `overlay.spec.js` → `the boxes track the photo across a stepped resize`
- [x] Check on a HiDPI display, where `rvas_choose()` removes `usemap` and takes a different branch —
      **automated 2026-08-31**: the `deviceScaleFactor: 2` block in `overlay.spec.js` (2 specs)

**Implementation Note**: pause for manual confirmation before Phase 6.

---

## Phase 6: Public picture page — the editor

### Overview

Drag-to-draw, name, commit. The interaction follows the cross-product conventions the research
established: drag-to-draw, a box-anchored picker, a list visible before typing, Enter commits, Esc
cancels, and a create-new escape hatch inside the picker.

### Changes Required:

#### [ ] 1. Tagging mode
**File**: `plugins/persons/template/editor.js`
**Changes**: a "Tag people" button toggles a mode, exactly as Facebook does. Entering the mode sets
`pointer-events: auto` on the overlay, hides the `<map>` by removing `usemap` (restored on exit),
and shows all boxes rather than hover-only. This is what keeps the editor from fighting the two
click consumers already bound to that element.

#### [ ] 2. Drag-to-draw
**Changes**: `mousedown`/`mousemove`/`mouseup` on the overlay produce a rectangle in element pixels,
converted to normalized center-origin against `stageRect()` and rejected below
`PERSONS_MIN_BOX_FRACTION` on either axis (PhotoPrism's 16px minimum, expressed as a fraction so it
is resolution-independent).

#### [ ] 3. The picker
**Changes**: anchored to the drawn box, auto-focused, pre-populated from `pwg.persons.getList` with
no `q`, re-queried (debounced) as the user types. Position is chosen from four candidates —
below, above, right, left — picking the one that overlaps the drawn box least; Immich's
collision-avoidance is the only shipped answer to "the picker covers the face you are looking at".
Enter commits the highlighted entry, Esc cancels and removes the box, and a
`+ Create "<typed>"` entry appears when nothing matches exactly.

Arrow-key navigation of the list is implemented even though the research found it documented in
none of the six products — it is standard autocomplete behaviour and its absence is a real
accessibility gap, not a convention.

#### [ ] 4. Delete
**Changes**: an `x` affordance on a box, in tagging mode only, calling `pwg.persons.deleteRegion`.

#### [ ] 5. Failure handling
**Changes**: `PwgError` arrives as HTTP 200 with `stat:"fail"`, so a refused save lands in
`success()`, not `error()` — the convention both existing plugins document. A failed save restores
the box to its pre-save state and surfaces the message; it never silently drops the drawn box.
An image whose file exiftool cannot write disables the button with an explanatory title rather
than offering an action that can only fail (the `PROVENANCE_EXIFTOOL` precedent).

### Success Criteria:

#### Automated Verification:
- [ ] E2E: drag a box, type a new name, press Enter; the box appears named after a reload
- [ ] E2E: the written region is read back out of the file by exiftool in a separate process
- [ ] E2E: Esc removes the drawn box and writes nothing
- [ ] E2E: a second person on the same photo does not remove the first
- [ ] E2E: a box smaller than the minimum is rejected with a visible message
- [ ] E2E: a guest sees no "Tag people" button
- [ ] Integration: `pwg.persons.addRegion` on a fixture photo produces the region, the person, the
      mirrored tag and the `image_tag` row

#### Manual Verification:
- [ ] Tag a face on a real photo and confirm the box sits where it was drawn after reload
- [ ] Confirm the picker never covers the face being tagged, at several box positions

**Implementation Note**: pause for manual confirmation before Phase 7.

---

## Phase 7: Admin photo screen

### Overview

The same editor on a full-size, static image for an administrator, on a dedicated screen rather
than `picture_modify`'s thumbnail.

### Changes Required:

#### [ ] 1. The screen
**File**: `plugins/persons/admin/photo.php` reached at
`admin.php?page=plugin-persons&image_id=N`, template `plugins/persons/template/admin_photo.tpl`

Modelled on `admin/picture_coi.php`: it renders one large derivative with no RVAS, no `<map>` and
no click-to-navigate handler, so the editor module runs on a static element. The overlay/editor JS
is the **same file** as the public page — the coordinate contract has one implementation, and the
only difference is which element `stageRect()` measures.

`picture_modify.tpl:114`'s `<img src="{$TN_SRC}">` is a thumbnail, too small to box a face on;
that is why this is its own screen rather than an injection into the existing photo tab.

#### [ ] 2. Link from the photo screen
**File**: `plugins/persons/include/events_admin.inc.php`
**Changes**: a `loc_begin_admin_page` handler that, on `$page['page'] == 'photo'`, injects a link to
the screen above. `$page['tab']` is not set yet at that event — read `$_GET['tab']`, the trap
`provenance/include/events_admin.inc.php:25-27` documents.

### Success Criteria:

#### Automated Verification:
- [ ] E2E: an administrator draws and names a box on the admin screen; the file carries it
- [ ] E2E: `persons_normal` navigating to the screen directly is refused
- [ ] Unit: structural guard on the `picture_modify` anchor

#### Manual Verification:
- [ ] Confirm the admin screen and the public page place an identical region identically

**Implementation Note**: pause for manual confirmation before Phase 8.

---

## Phase 8: Browse by person and the persons admin screen

### Overview

Make the mirrored tags do their job, and give an administrator a way to see, rename, delete and
rebuild.

### Changes Required:

#### [ ] 1. Tag-mirror maintenance
**File**: `plugins/persons/include/index.inc.php`
**Changes**:
- `pwg.persons.rename` renames both the `persons` row and the mirrored tag, refreshes `url_name`
  through `trigger_change('render_tag_url', $name)` (never stored by the caller anywhere in core),
  and rewrites the name in every affected file.
- `pwg.persons.delete` removes the regions from the files, the index rows, and the `image_tag`
  pairs. The mirrored tag is left to core's orphan-tag mechanism
  (`get_orphan_tags()`, `admin/include/functions.php:430`) rather than deleted directly — a tag may
  have been applied by hand to photos that never had a region.
- After any write, invalidate the tag cache: `UPDATE user_cache SET nb_available_tags = NULL` with
  no `WHERE`, per `docs/agents/decisions/0004-unscoped-tag-cache-invalidation-accepted.md`.
- Register a `delete_elements` handler so deleting a photo removes its region rows.

#### [ ] 2. The admin screen
**File**: `plugins/persons/admin/persons.php` + `template/admin_persons.tpl` / `.js`
**Changes**: the list mocked above — search, photo/region counts, rename, delete, and a chunked
"Rescan all files" driven the way `provenance`'s apply/write-back runner is, publishing
`data-done` / `data-total` on the progress element so the E2E suite reads state rather than
measuring an animation.

#### [ ] 3. React to a changed rotation
**File**: `plugins/persons/include/index.inc.php`

This Piwigo has **no rotate action**: `images.rotation` is written only by sync and upload
(`admin/include/image.class.php:269-303` reads it out of EXIF Orientation), and
`admin/picture_modify.php:206-207` merely *reads* it to swap the displayed dimensions. So there is
no event to hook. The trigger is therefore a detected *change*, evaluated on every reindex:

```php
// Called from persons_reindex_image(), before the index rows are rewritten.
// Returns the rotation delta (0..3) the stored regions must be corrected by, or 0.
function persons_rotation_delta($stored_rotation, $current_rotation,
                                $applied_w, $applied_h, $file_w, $file_h);
```

Two distinct events have to be told apart, and conflating them is what would corrupt correct data:

| Observed | Meaning | Action |
|---|---|---|
| `images.rotation` changed, file dimensions still match `applied_w/applied_h` | Only the *display* transform changed; the file's bytes and its pre-rotation regions are still correct (MWG stores regions prior to Exif Orientation) | Update `rotation_at_write`, rewrite nothing |
| File dimensions are the transpose of `applied_w/applied_h` | The file was **physically** rotated by something outside Piwigo (an external tool, a re-upload with `$conf['upload_form_automatic_rotation']`) | Rotate every region by the delta via `persons_rotate_region()`, write the corrected `RegionInfo` back, refresh `applied_*` |
| File dimensions differ some other way | A crop or resize | Leave the region; the staleness indicator already covers it |

`rotation_at_write` exists solely to make the first row detectable; without it a rotation change and
a no-op are indistinguishable.

#### [ ] 4. Person links
**File**: `plugins/persons/template/public_persons.tpl`, `overlay.js`
**Changes**: a person's name links to `make_index_url(array('tags' => array(...)))` for the mirrored
tag. No new routing, per research answer 5.

### Success Criteria:

#### Automated Verification:
- [ ] Integration: renaming a person updates the tag, the index and the file
- [ ] Integration: deleting a photo removes its region rows
- [ ] Integration: a physically rotated file has its regions corrected on reindex; a display-only
      rotation change rewrites nothing
- [ ] Integration: deleting every region for a person leaves no `image_tag` row behind
- [ ] Integration: `persons_rescan_images()` over a set with one unreadable file reports one
      failure and still indexes the rest
- [ ] E2E: clicking a person's name lands on a gallery page listing that person's photos
- [ ] E2E: the rescan button completes and the counts on the admin screen match the database

#### Manual Verification:
- [ ] Delete the two tables, run a full rescan, and confirm the index comes back identical — this
      is the claim "the DB is disposable" and nothing else tests it end to end

**Implementation Note**: pause for manual confirmation before Phase 9.

---

## Phase 9: Hardening and documentation

### Overview

The strength check and the record. No new behaviour.

### Changes Required:

#### [ ] 1. Mutation pass over the unit suite
**Changes**: one table, per `.claude/rules/mutation-testing.md` — prose, not a script, and each
mutant verified to have reached the container (checksum poll) before the suite runs. Target the
coordinate math, which is where a silent regression would be worst:

| Mutant | Expected killer |
|---|---|
| `persons_center_to_corner`: `- $w/2` → `+ $w/2` | the center↔corner round-trip test |
| `persons_clip_region`: `>` → `>=` on the `[0..1]` bound | the boundary pair at exactly 0 and 1 |
| `persons_rotate_region`: `% 4` → `% 3` | the rotation-code equivalence-class test |
| `persons_region_is_stale`: tolerance `>` → `<` | the just-inside / just-outside boundary pair |
| `persons_merge_regions`: `array_merge` → replace | the "foreign region survives" test |
| `persons_minimum_box_ok`: `and` → `or` | the one-axis-too-small case |
| `persons_rotation_delta`: transpose check `==` → `!=` | the display-only vs. physical pair |

#### [ ] 2. Documentation
**Files**: `CLAUDE.md`, `docs/agents/TESTING.md`, `docs/agents/decisions/`
**Changes**:
- `CLAUDE.md`: the plugin in the overview, its four test commands, its `create-test-users.php`, the
  `_data/persons/` subsection, the `.gitignore` `!` entry, and the fact that this plugin needed
  **no** core changes (the contrast with `provenance`'s two `trigger_notify()` calls is worth
  stating, since a future reader will assume symmetry).
- `docs/agents/TESTING.md`: the mutant table, the deliberate non-coverage entries (touch/mobile
  drag, HiDPI branch, `images.coi` changes), and the hand-check ledger rows this plan leaves —
  "picker never covers the face", "boxes track the image while resizing", "digiKam shows the region
  in the right place" — each with why it has no oracle.
- Decision files: the permission model (Phase 4), plus one recording that the DB is a derived index
  and the file is the source of truth, with the drift window that creates when files are edited
  outside Piwigo.
- `docs/backlog.md`: the deferred items — face crops, `images.coi` invalidation, touch support,
  person merge, Microsoft `MP` write.

#### [ ] 3. Research document status
**File**: `docs/agents/research/2026-08-29-person-face-tagging.md`
**Changes**: its "Open Questions" section says the answers are "not yet written up as decision
files". Once Phase 9 writes them, update that sentence to cite the files. Stale process
documentation is worse than none.

### Success Criteria:

#### Automated Verification:
- [ ] Every mutant in the table is killed, or its survival is recorded with the reason
- [ ] Full suite passes twice in a row and in reverse order
- [ ] `bash tools/test-hooks.sh` passes

#### Manual Verification:
- [ ] `CLAUDE.md` commands copy-paste and run on a fresh checkout

---

## Testing Strategy

Layer placement follows `.claude/rules/testing.md`: the coordinate math is unit-only, anything
crossing exiftool or `ws.php` is integration, and only what needs a real rendered image is E2E.

### Test Design Techniques Applied

Decision-table testing is **not applicable** to most of this plugin: the helpers each have one or
two conditions and a single outcome. It applies in exactly one place — the merge — where three
conditions (region exists in file / region in `add` / region in `remove`) combine, and that case is
enumerated below.

### Unit Tests

#### Coordinate math (`tests/Unit/RegionGeometryTest.php`)

**Happy path:**
- [ ] `testCenterToCornerConvertsACenteredBox` — center `(0.5,0.5,0.2,0.2)` → corner `(0.4,0.4,0.2,0.2)` `[HAPPY]`
- [ ] `testCornerToCenterIsTheInverseOfCenterToCorner` — round-trip over a table of boxes `[HAPPY]`

**Boundary values (each normalized field: below 0, exactly 0, just inside, just below 1, exactly 1, above 1):**
- [ ] `testACenterAtExactlyZeroIsKept` `[BVA]`
- [ ] `testACenterAtExactlyOneIsKept` `[BVA]`
- [ ] `testACenterBelowZeroIsDropped` `[BVA]` `[NEG]`
- [ ] `testACenterAboveOneIsDropped` `[BVA]` `[NEG]`
- [ ] `testABoxOverrunningTheLeftEdgeIsClippedNotDropped` `[BVA]`
- [ ] `testABoxOverrunningTwoEdgesIsClippedOnBoth` `[BVA]`
- [ ] `testAZeroWidthBoxIsRejected` `[BVA]` `[NEG]`
- [ ] `testABoxAtExactlyTheMinimumFractionIsAccepted` `[BVA]`
- [ ] `testABoxOneEpsilonBelowTheMinimumIsRejected` `[BVA]` `[NEG]`
- [ ] `testAWidthOfExactlyOneCoversTheWholeImage` `[BVA]`

**Equivalence classes on `rotation`:**
- [ ] `testRotationCodeZeroLeavesTheRegionUnchanged` `[ECP]`
- [ ] `testRotationCodeOneSwapsTheAxes` `[ECP]`
- [ ] `testRotationCodeTwoMirrorsBothAxes` `[ECP]`
- [ ] `testRotationCodeThreeSwapsTheAxesTheOtherWay` `[ECP]`
- [ ] `testRotationCodeFourIsTreatedAsZero` — the `% 4` in `derivative.inc.php:74-92` `[BVA]`
- [ ] `testANegativeRotationCodeIsTreatedAsZero` `[NEG]`
- [ ] `testFourSuccessiveRotationsReturnTheOriginalRegion` — property test `[ECP]`

**Rotation change detection (`persons_rotation_delta`):**
- [ ] `testAnUnchangedRotationYieldsNoDelta` `[HAPPY]`
- [ ] `testADisplayOnlyRotationChangeYieldsNoDelta` — code changed, dimensions still match `[DT]`
- [ ] `testAPhysicalRotationYieldsTheDelta` — dimensions transposed `[DT]`
- [ ] `testAPhysicalRotationTheOtherWayYieldsTheOppositeDelta` `[DT]`
- [ ] `testASquareImageIsNeverReportedPhysicallyRotated` — transpose is indistinguishable `[BVA]` `[NEG]`
- [ ] `testACropYieldsNoDelta` — dimensions differ but are not a transpose `[DT]`
- [ ] `testUnknownAppliedDimensionsYieldNoDelta` `[NEG]`

**Staleness (`persons_region_is_stale`):**
- [ ] `testIdenticalDimensionsAreNotStale` `[HAPPY]`
- [ ] `testARatioDifferenceAtExactlyTheToleranceIsNotStale` `[BVA]`
- [ ] `testARatioDifferenceOneEpsilonPastToleranceIsStale` `[BVA]`
- [ ] `testAProportionalResizeIsNotStale` — 4000x3000 → 2000x1500, the common case `[ECP]`
- [ ] `testACropIsStale` — 4000x3000 → 4000x2000 `[ECP]`
- [ ] `testUnknownAppliedDimensionsAreNotReportedStale` — digiKam bug 429219 `[ERR]` `[NEG]`
- [ ] `testZeroAppliedDimensionsDoNotDivideByZero` `[BVA]` `[NEG]`

#### RegionInfo parsing (`tests/Unit/ParseRegionInfoTest.php`)

- [x] `testASingleRegionIsParsed` `[HAPPY]`
- [x] `testTwoRegionsAreParsedInOrder` `[HAPPY]`
- [x] `testARegionListArrivingAsAnObjectIsTreatedAsOneRegion` `[ERR]`
- [x] `testNumericFieldsArrivingAsStringsAreParsed` — Immich PR #29333 `[ERR]`
- [x] `testHighPrecisionCoordinatesDoNotLosePosition` `[BVA]`
- [x] `testAMissingAppliedToDimensionsYieldsUnknownNotZero` `[NEG]`
- [x] `testARegionWithNoNameIsSkipped` `[NEG]`
- [x] `testARegionWithANonNormalizedUnitIsSkipped` `[NEG]`
- [x] `testAnEmptyRegionListYieldsNoRegions` `[BVA]`
- [x] `testMalformedJsonYieldsNoRegionsAndNoWarning` `[NEG]`
- [x] `testANonFaceRegionTypeIsKeptAsForeign` — Pet/Focus/BarCode must survive a later merge `[ECP]`
- [x] `testAUnicodeNameSurvivesParsing` `[ERR]`

#### Merge (`tests/Unit/MergeRegionsTest.php`) — the decision table

Conditions: **E** in file, **A** in add, **R** in remove.

- [ ] `testERegionOnlyInTheFileIsKept` — E=1 A=0 R=0 `[DT]`
- [ ] `testANewRegionIsAdded` — E=0 A=1 R=0 `[DT]`
- [ ] `testARemovalOfSomethingAbsentIsANoOp` — E=0 A=0 R=1 `[DT]` `[NEG]`
- [ ] `testReAddingAnExistingRegionDoesNotDuplicateIt` — E=1 A=1 R=0 `[DT]`
- [ ] `testARegionInTheFileAndInRemoveIsDropped` — E=1 A=0 R=1 `[DT]`
- [ ] `testAddAndRemoveOfTheSameRegionResolvesToRemove` — E=1 A=1 R=1 `[DT]` `[NEG]`
- [ ] `testAForeignPetRegionSurvivesAFaceWrite` `[DT]`
- [ ] `testAppliedToDimensionsIsAlwaysWritten` — MWG normative `[HAPPY]`
- [ ] `testMergingIntoAFileWithNoRegionInfoProducesAValidStructure` `[BVA]`
- [ ] `testTheSamePersonTwiceInOneImageIsAllowed` — the case `image_tag`'s PK forbids `[ECP]`
- [ ] `testPersonInImageListsEachNameOnce` `[ECP]`

#### Name handling (`tests/Unit/PersonNameTest.php`)

- [ ] `testATypicalNameIsUnchanged` `[HAPPY]`
- [ ] `testMarkupIsStripped` `[NEG]`
- [ ] `testSurroundingWhitespaceIsTrimmed` `[ECP]`
- [ ] `testInternalWhitespaceIsCollapsed` `[ECP]`
- [ ] `testAnEmptyNameIsRejected` `[BVA]` `[NEG]`
- [ ] `testANameOfOnlyWhitespaceIsRejected` `[BVA]` `[NEG]`
- [ ] `testANameAtExactlyTheByteCapIsAccepted` `[BVA]`
- [ ] `testANameOneByteOverTheCapIsTruncatedOnAUtf8Boundary` `[BVA]`
- [ ] `testAMultibyteNameCountsBytesNotCharacters` `[ERR]`
- [ ] `testANameContainingANewlineIsFlattened` — it reaches a JSON file `[NEG]`

#### Structural guards
- [x] `tests/Unit/PicturePageAnchorTest.php` — `{$ELEMENT_CONTENT}` occurs exactly once in
      `themes/default/template/picture.tpl`, and `themes/modus/template/picture.tpl` is absent
- [ ] `tests/Unit/PhotoTemplateAnchorTest.php` — the admin `picture_modify` anchor occurs once
- [ ] `tests/Unit/SchemaDefinitionTest.php` — `maintain.class.php` creates exactly the columns the
      two column functions declare
- [ ] `tests/Unit/CleanCheckoutTest.php` — every runtime include target is committed and none is
      git-ignored (the `.gitignore` `!` entry is easy to forget)

#### Anti-vacuity
- [ ] Every test that counts regions asserts the fixture yields a non-zero count first
- [ ] Every page-source scan asserts `strlen($html) > MIN_BYTES` before its `substr_count`

#### Regression — affected existing functionality
- [ ] `plugins/typetags` unit + integration suites still pass — the plugin injects into the same
      picture template and the mirrored tags land in the same `piwigo_tags` / `piwigo_image_tag`
      tables typetags reads
- [ ] `plugins/provenance` unit + integration suites still pass — it injects into the same
      `dl#standard` and the same `picture_informations` config map
- [ ] New: an integration test asserting typetags' and persons' picture-page injections coexist,
      because nothing else would notice one prefilter eating the other's anchor

### Integration Tests

**Happy path:**
- [ ] `WriteRegionTest::testARegionWrittenByThePluginIsReadBackByAnIndependentExiftoolRun` `[HAPPY]`
- [x] `ReindexTest::testTheIndexMatchesWhatTheFileHolds` `[HAPPY]`
- [x] `AddRegionTest::testAddingARegionCreatesThePersonTheRegionAndTheMirroredTag` `[HAPPY]`
- [x] `SearchTest::testGetListWithNoQueryReturnsRecentPersonsMostRecentFirst` `[HAPPY]`
- [x] `SearchTest::testGetListWithAPartialQueryMatchesInTheMiddleOfAName` `[HAPPY]`
- [x] `SearchTest::testTaggingAnExistingPersonAgainMovesThemToTheFrontOfTheRecentList` `[ST]` — the
      one case that separates "most recently used" from "most recently created"

**Negative / error propagation:**
- [x] `AddRegionTest::testGuestIsRejected` — 401 `[NEG]`
- [x] `AddRegionTest::testBadTokenIsRejected` — 403 `[NEG]`
- [x] `AddRegionTest::testEmptyTokenIsRejectedByTheDispatcher` — 1002 `[NEG]`
- [x] `VisibilityTest::testANormalUserGets404ForAnImageInAForbiddenAlbum` `[NEG]`
- [x] `VisibilityTest::testTheRefusalDoesNotRevealWhetherTheImageExists` — a nonexistent id and a
      forbidden id return the same code and message `[NEG]`
- [x] `AdminOnlyTest::testANormalUserCannotRenameDeleteOrRescan` — 401 ×3 `[NEG]` `[ERR]` (core's code, not 403)
- [ ] `WriteRegionTest::testAReadOnlyFileIsReportedFailedAndLeftUnchanged` `[NEG]`
- [ ] `WriteRegionTest::testAFileExiftoolCannotWriteIsReportedNotCrashed` `[NEG]`
- [x] `RescanTest::testOneUnreadableFileDoesNotAbortTheBatch` `[NEG]` — landed as `ReindexTest::testOneUnreadableFileDoesNotAbortTheBatch`

**Boundary / edge:**
- [ ] `WriteRegionTest::testAForeignRegionInTheFileSurvivesOurWrite` `[BVA]`
- [ ] `WriteRegionTest::testConcurrentWritersAllSucceedAndNoRegionIsLost` — the
      `write-back-worker.php` pattern, `[ERR]`
- [ ] `WriteRegionTest::testAnOriginalSidecarIsLeftBesideTheImage` `[HAPPY]`
- [ ] `ReindexTest::testDeletingTheTablesAndRescanningRebuildsAnIdenticalIndex` `[ST]`
- [ ] `ReindexTest::testAnImageWithNoRegionsProducesNoRows` `[BVA]`
- [ ] `RotationTest::testAPhysicallyRotatedFileHasItsRegionsCorrectedOnReindex` — rotate a tagged
      fixture with `exiftool`/ImageMagick outside the plugin, reindex, and assert the region still
      lands on the same feature `[ST]`
- [ ] `RotationTest::testChangingOnlyImagesRotationRewritesNothing` — the file's mtime and its
      `RegionInfo` are byte-identical afterwards `[ST]` `[NEG]`
- [x] `testRemovingTheLastRegionRemovesTheImageTagRow` `[ST]` — landed in `DeleteRegionTest`
- [x] `testRemovingOneOfTwoRegionsForTheSamePersonKeepsTheImageTagRow` `[ST]` — landed in `DeleteRegionTest`
- [x] `PicturePageSourceTest::testTheOverlayIsInjectedExactlyOnce` — the sub-template guard `[BVA]` — landed as `testTheStageIsInjectedExactlyOnce`
- [x] `PicturePageSourceTest::testAGuestSeesNoOverlay` `[NEG]`
- [ ] `PluginActivationTest` — install / activate / deactivate / uninstall driven through
      `pwg.plugins.performAction`, so what is asserted is what clicking Activate actually does `[ST]`

### End-to-End Tests

- [ ] `tag-person.spec.js` — drag, type a new name, Enter, reload, the box is named `[HAPPY]`
- [ ] `tag-person.spec.js` — the region is confirmed in the file by `support/metadata.js` `[HAPPY]`
- [ ] `tag-person.spec.js` — Esc cancels and writes nothing `[NEG]`
- [ ] `tag-person.spec.js` — a box below the minimum is refused with a visible message `[NEG]`
- [ ] `tag-person.spec.js` — picking an existing person from the list writes that person `[HAPPY]`
- [x] `overlay.spec.js` — box geometry within tolerance at two window widths `[ST]`
- [x] `overlay.spec.js` — clicking outside a box still navigates (core handler intact) `[NEG]`
- [x] `overlay.spec.js` — a stale region renders dashed and dimmed `[ECP]`
- [ ] `overlay.spec.js` — a guest sees no button and no boxes `[NEG]`
- [ ] `admin-persons.spec.js` — rename propagates to the gallery link and the file `[HAPPY]`
- [ ] `admin-persons.spec.js` — rescan completes, `data-done` reaches `data-total` `[HAPPY]`
- [ ] `browse.spec.js` — clicking a person's name lists that person's photos `[HAPPY]`

Every locator lives in a page object under `tests/e2e/support/` — `PicturePage.js`,
`PersonOverlay.js`, `AdminPersonsPage.js` — and a locator in a spec file is a bug. `retries: 0`,
`workers: 1`.

Two throwaway seed scenarios, following the `provenance` precedent that a suite never writes to a
real scan: `seed.php --scenario=tagging` (an album of copied photos to draw on) and
`--scenario=tagged` (copied photos pre-written with known regions). `--restore` deletes the albums,
rows, copied files and `_original` sidecars.

### Manual Testing Steps

Only what has no oracle; each becomes a row in the `docs/agents/TESTING.md` hand-check ledger.

1. Resize the browser slowly across a derivative-switch threshold — boxes must track the image
   continuously, not jump and settle.
2. On a HiDPI display, confirm the overlay is correct on the branch where `rvas_choose()` removes
   `usemap` and rescales.
3. Tag several faces on a crowded photo and confirm the picker never covers the face being named.
4. Open a written file in digiKam and confirm the box lands on the same face.
5. Confirm the contrast of box outline and name label is legible over both a light and a dark photo.

### Test Commands

```bash
# once per install (also rotates the passwords)
ddev exec php plugins/persons/tests/Support/create-test-users.php

# Unit
ddev exec plugins/persons/vendor/bin/phpunit --testsuite unit --configuration plugins/persons/phpunit.xml

# Integration
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; \
  plugins/persons/vendor/bin/phpunit --testsuite integration --configuration plugins/persons/phpunit.xml'

# E2E
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; \
  cd plugins/persons && npx playwright test'

# Regression across the other two plugins
ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; \
  plugins/typetags/vendor/bin/phpunit --configuration plugins/typetags/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  plugins/provenance/vendor/bin/phpunit --configuration plugins/provenance/phpunit.xml'

# Syntax + hook gate
ddev exec php -l <file>
bash tools/test-hooks.sh
```

A fresh clone needs `ddev exec composer install -d plugins/persons` and
`ddev exec bash -c 'cd plugins/persons && npm install'` first.

## Performance Considerations

- **The picture page must not shell out.** Rendering the overlay reads the DB index only; exiftool
  runs only on write and on rescan. This is the main reason the index exists at all.
- **Rescan is O(files) and shells out per file.** It is chunked at
  `PERSONS_WRITEBACK_MAX_CHUNK` (10, matching `provenance`) with each chunk a separate request, so
  no single request can hit `max_execution_time`. On a large gallery it is a minutes-long
  administrator action, and the screen says so.
- **Search is a `LIKE '%…%'`**, which cannot use the index on `name`. Acceptable: the row count is
  the number of *people*, not photos, and the result is capped. If it ever matters, the fix is a
  prefix index and a prefix-only search, not a bigger cap.
- **Redraw on resize is debounced** and reads `getBoundingClientRect()` once per frame. The
  per-region work is a `transform`, not a layout-triggering property.
- **Tag cache invalidation is unscoped** (`nb_available_tags = NULL`, no `WHERE`) per decision
  0004. Over-invalidation is always safe and this happens only on write.

## Migration Notes

- **No data migration.** The plugin ships with empty tables; a gallery whose files already carry
  MWG regions populates itself on the first rescan.
- **Rollback.** Uninstalling drops both tables and the mirrored tags. The regions stay in the image
  files, so nothing the plugin recorded is lost — reinstalling and rescanning restores the index.
  This is the practical payoff of file-as-source-of-truth and is worth stating in the plugin
  description.
- **Drift.** Editing a file's regions outside Piwigo (digiKam, Lightroom) leaves the index stale
  until a rescan. There is no file-watcher and no automatic detection; the admin screen shows when
  the index was last rebuilt. This is a recorded consequence of the storage decision, not a defect.
- **Upgrade path within the plugin**: `update()` delegates to `install()`, which is re-entrant, so
  a version bump adds columns and never rewrites data.

## References

- `docs/agents/research/2026-08-29-person-face-tagging.md` — the full research this plan implements
- `docs/agents/research/2026-04-24-picture-page-tag-assignment.md` — prior work on the same injection point
- `docs/agents/plans/2026-08-29-provenance-metadata-writeback.md` — the plan this one is shaped after
- `docs/agents/decisions/0003-no-post-only-on-ws-methods.md`
- `docs/agents/decisions/0004-unscoped-tag-cache-invalidation-accepted.md`
- `docs/agents/decisions/0005-tag-assignment-permission-model.md` — the open question Phase 4 closes
- `docs/agents/decisions/0014-provenance-is-its-own-plugin.md` — why a plugin, not core changes
- `.claude/rules/testing.md`, `test-design.md`, `mutation-testing.md`, `e2e-tests.md`,
  `backpressure.md`, `precommit-hooks.md`
- Similar implementation: `plugins/provenance/include/exiftool.inc.php:88-231` (batch, locks, argfiles)
- Similar implementation: `plugins/typetags/include/events_public.inc.php:173-374` (picture-page injection)
- MWG 2.0 guidance (regions, p.55): https://web.archive.org/web/20180919181934/http://www.metadataworkinggroup.org/pdf/mwg_guidance.pdf
- ExifTool structured information / serialization: https://exiftool.sourceforge.net/struct.html
