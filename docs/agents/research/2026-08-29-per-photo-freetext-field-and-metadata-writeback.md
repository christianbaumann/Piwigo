---
date: 2026-08-29T05:10:18.540066+00:00
last_updated: 2026-08-29T07:15:18+00:00
git_commit: e7d58392d59e0390752b0a9cb2d398c14319b5a5
last_updated_commit: 24e634b651dccd4ade28fc667a72d7af329131ea
branch: master
topic: "Provenance metadata for scanned photos: per-photo and per-album freetext with write-back into image file metadata"
tags: [research, metadata, exif, iptc, xmp, exiftool, provenance, picture-page, picture-modify, imagick, ui,
       albums, categories, propagation, requirements, audit-trail, concurrency, locking, shared-hosting, all-inkl]
status: complete
production_host: "ALL-INKL PrivatPlus — probed 2026-08-29: PHP 8.4.16 fpm-fcgi, disable_functions empty, imagick present, exiftool 12.76 preinstalled. Write-back is FULLY supported."
---

# Research: Provenance metadata for scanned photos, with write-back into image files

## Document Status and Reading Guide

This document grew across several rounds on 2026-08-29. It is long (2000+ lines) and **sections
later in the file supersede earlier ones**. Read this guide first.

**Where the scope ended up.** It began as "a freetext field per photo, written into the file's
metadata". It is now: **structured provenance for scanned photographs** (which physical album, who
owns it, when it was scanned) entered at **album level**, copied down to every photo, and written
into the image files — with an audit trail.

**Research state: complete.** Every question opened in this investigation is closed or measured.
**Production feasibility: confirmed by probe on the live host** — `exec` permitted, Imagick present,
and **ExifTool 12.76 already installed**. The write-back requirement is buildable as specified. See
*Production Host Probe — RESULT*.

| Round | Section | What it settled |
|---|---|---|
| 1 | `Summary` → `Open Questions` | The original per-photo research: read-only metadata layer, format capability matrix, UI injection points |
| 2 | `Follow-up Research … Album-level` | Album schema, save path, propagation precedents, association model, batch infrastructure |
| 3 | `Decisions Recorded` | Answers to the 15 requirements questions; the scope reframing to provenance |
| 4 | `Conflict Resolutions` | The four ⚠ conflicts those answers created |
| 5 | `Follow-on Resolutions` | Ten second-round questions |
| 6 | `Audit Trail Infrastructure` | Core has no revision table; what must be built |
| 7 | `Metadata-Writing Library Survey` | Only exiftool writes EXIF into PNG |
| 8 | `Empirical Verification of exiftool` | Measured: all formats, zero pixel change, encoding, derivatives |
| 9 | `Concurrency and Locking` | **Concurrent writes destroy files**; `flock` fixes it |
| 10 | `Shared-Hosting Feasibility` | ALL-INKL PrivatPlus: two unknowns — **since resolved, see row 11** |
| 11 | `Production Host Probe — RESULT` | Probe run on the live account: **no blockers**; ExifTool preinstalled; 60 s execution ceiling |

**⚠ The three findings that most affect the plan:**

1. **Concurrent exiftool writes delete the photo** — measured, 5 of 6 runs at 12-way contention.
   Per-file `flock` on a separate lock file fully mitigates. Locking is mandatory, not advisory.
2. **exiftool writes without re-encoding pixels** — measured byte-identical across PNG/JPEG/TIFF/HEIC.
   This removes the lossy re-encode that made the Imagick route unattractive.
3. **The production host has a 60-second `max_execution_time`** — absent locally (`0`), so every
   batch operation must be chunked to fit. This is now the binding constraint on the write-back
   design, replacing the earlier worry that the host might not support write-back at all.

**⚠ Corrections made in later rounds** — earlier text in this file was wrong on these points and has
been annotated in place:

- "Piwigo core shells out nowhere today" — **false**; core ships an `exec()`-based ImageMagick backend.
- "`_original` backups accumulate per save" — **false**; exiftool never overwrites an existing backup.
- "`clean_iptc_value()` converts from windows-1252 unconditionally" — **false**; it auto-detects UTF-8.
- "cron gives a scheduling escape hatch on the host" — **false**; ALL-INKL cron fetches a URL.
- "ALL-INKL disables `exec` and lacks Imagick" — **false on this account**; those forum reports
  describe the older mod_php setup. The host runs PHP-FPM with an empty `disable_functions`.

**Next action:** planning. The capability probe has been run (round 11) and returned no blockers.
The one open measurement is ExifTool throughput against the 60 s ceiling, which is sizing work for
the plan rather than a feasibility question.

## Research Question

**As originally posed:** add a freetext field per photo; the text shall also be saved into an
appropriate field in the image's file metadata. If several metadata standards have a field for such
freetext, all shall be used (even if the text is then duplicated). The UI/UX shall fit the existing
design without disturbing the current page layout. Formats of interest: PNG, JPG, TIFF, HEIC.

**Extended during the investigation:**

1. *A freetext field per album*, saved at album level and given to every image in it; image-level
   text uses the same mechanism as the per-photo field.
2. *Restated purpose* — this install holds **scans of real photographs** obtained from people's
   physical albums. It must record provenance: which physical album, who owns it, when it was
   scanned. So the unit is several typed fields, not one blob.
3. *An audit trail* for all texts.
4. *Does it work on the production host* — ALL-INKL PrivatPlus?

Research only — this document describes what exists today and records decisions taken. Where a
section proposes an approach, it is recording a decision already made by the product owner, not a
recommendation from the research.

**Working assumption recorded mid-research:** photo → album is treated as **1:1**. The codebase does
not enforce this (see the scoping note in the album section); making it true is tracked separately in
`docs/backlog.md`.

## Summary

> **Scope note:** this section summarises **round 1 only** — the original per-photo question. The
> scope was extended twice afterwards (album level, then provenance-for-scans) and several claims
> below were later corrected. See the Document Status and Reading Guide above.

Three findings dominate the picture.

**1. Piwigo's metadata layer is read-only, in every code path.** `include/functions_metadata.inc.php`
reads EXIF (via `exif_read_data`) and IPTC (via `getimagesize` APP13 + `iptcparse`). There is
no write path anywhere in the tree — no `iptcembed`, no PEL, no exiftool, no vendored
metadata-writing library. The only places Piwigo alters metadata bytes are *destructive
strips on derivatives* (`i.php:615`, `admin/include/image.class.php:504`), never on the
original. XMP is not read or written by any runtime code; the sole XMP mention in the tree
is a standalone developer scratch script (`tools/metadata.php:84-110`). So a write-back
feature has no existing plumbing to extend — it would be new infrastructure.

**2. Metadata flows one way today, and a write-back inverts a documented assumption.**
`sync_metadata()` (`admin/include/functions_metadata.php:269`) reads the file and overwrites
DB columns, and is invoked on every upload (`admin/include/functions_upload.inc.php:403`),
from `pwg.images.syncMetadata`, and from the Batch Manager. A field written *into* the file
by Piwigo and *also* mapped in `$conf['use_iptc_mapping']` would be read back by the next
sync — a round-trip whose direction depends on which side ran last. The three existing
free-text columns (`name`, `comment`, `author`) are all already on the IPTC read map.

**3. Empirically measured in this container: no single library writes all standards, and
format support is uneven.** I probed the live DDEV container (PHP 8.4.20, Imagick/ImageMagick
7.1.1-43) by writing an XMP packet and an IPTC-IIM record into all four formats and reading
them back in a separate process. Results are in the capability matrix below. Headlines:
Imagick **cannot write EXIF at all** (`setImageProperty('exif:…')` produces no EXIF profile);
IPTC survives only in JPEG and TIFF (dropped entirely by HEIC); and only in **JPEG** can
Piwigo's own reader (`getimagesize`+`iptcparse`) see what was written. Also: writing via
Imagick re-encodes the whole file — lossless for PNG/TIFF, **lossy for JPEG and HEIC**.

A fourth point on UI: the picture page has a stable, repeated row pattern inside
`<dl id="standard">` that a new field fits without layout risk, and the typetags plugin in
this fork is a working precedent for injecting exactly such a row via a Smarty prefilter.

Related backlog entry (uncommitted, `docs/backlog.md`): "save labels/ tags in the image meta data".

## Detailed Findings

### A. The existing metadata layer (read-only)

`include/functions_metadata.inc.php` (247 lines) — four functions:

| Lines | Function | Behaviour |
|---|---|---|
| 21–70 | `get_iptc_data($filename, $map, $array_sep=',')` | `@getimagesize($filename, $imginfo)` (`:28`), takes `$imginfo['APP13']` (`:33`), `iptcparse()` (`:35`). Flips the map, reads `$iptc[$key][0]`; `2#025` (Keywords) joins all repeats (`:43-47`). `strip_tags()` unless `$conf['allow_html_in_metadata']` (`:57-63`). |
| 78–117 | `clean_iptc_value($value)` | Strips leading NULs, replaces `chr(0x00)`; for bytes `\x80-\xff` fires `trigger_change('clean_iptc_value', …)` (`:92`) then charset-converts from windows-1252/iso-8859-1 (`:99-113`). |
| 126–212 | `get_exif_data($filename, $map)` | `die()`s if the exif extension is missing (`:132-135`). Reads `@exif_read_data($filename)` (`:138`). Supports `Section;Field` map values (`:161`). GPS handled separately and unconditionally (`:170-191`). |
| 230–245 | `parse_exif_gps_data($raw, $ref)` | Rational-string GPS → float. |

Config (`include/config_default.inc.php`, metadata block `:338-415`):

- `$conf['use_iptc'] = false;` (`:363`) — IPTC read **off** by default
- `$conf['use_iptc_mapping']` (`:368-374`): `keywords→2#025`, `date_creation→2#055`, `author→2#122`, `name→2#005`, `comment→2#120`
- `$conf['use_exif'] = true;` (`:401`) — EXIF read **on**
- `$conf['use_exif_mapping']` (`:404-406`): `date_creation→DateTimeOriginal` only
- `$conf['show_iptc'] = false;` (`:343`), `$conf['show_exif'] = true;` (`:378`), `show_exif_fields` (`:392-397`)
- `$conf['allow_html_in_metadata'] = false;` (`:411`)

**Write support: none.** Greps for `iptcembed`, `exif_write`, `Pel`, `setImageProfile`,
`profileImage`, `exiftool` return no hits outside the read paths. Vendored libraries under
`include/` (smarty, minify, phpmailer, php_compat, inflectors, ws_protocols, dblayer, plus
single-file libs) contain nothing that writes image metadata.

Metadata-byte mutations that *do* exist, all destructive and all on derivatives:

- `i.php:615-617` — `$image->strip()` when `width*height < $conf['derivatives_strip_metadata_threshold']` (default `256000`, `include/config_default.inc.php:979`)
- `admin/include/image.class.php:109-113` — `strip()` inside `pwg_resize()` when `$strip_metadata`
- `admin/include/image.class.php:504` — `stripImage()` (Imagick backend); `:509-510` resets orientation
- `admin/include/image.class.php:269-300` — `get_rotation_angle()` reads EXIF `Orientation` to rotate the *derivative*

Consequence for this feature: the public gallery serves derivatives from `i.php`, so text
written into an original's metadata is absent from any derivative below the 256000-pixel
threshold, and present in larger ones only insofar as the imaging backend carries it through.

Plugin hooks in the read path: `clean_iptc_value` (`trigger_change`, string) and
`format_exif_data` (`trigger_change`, `$exif`, `$filename`, `$map`) — catalogued at
`tools/triggers_list.php:56-61` and `:120-127`.

### B. When metadata is read (the round-trip hazard)

`admin/include/functions_metadata.php` (437 lines):

| Lines | Function | Notes |
|---|---|---|
| 24–65 | `get_sync_iptc_data($file)` | `get_iptc_data($file, $conf['use_iptc_mapping'])` (`:30`); date normalization; `addslashes()`. |
| 73–117 | `get_sync_exif_data($file)` | `get_exif_data($file, $conf['use_exif_mapping'])` (`:77`). |
| 124–150 | `get_sync_metadata_attributes()` | `filesize,width,height` + exif map keys + `latitude,longitude` + iptc map keys. |
| 158–261 | `get_sync_metadata($infos)` | TIFF originals use the representative for dimensions but the **original** for EXIF/IPTC (`:171-189`, `:231-235`). Flattens newlines in `name`/`author` (`:249-258`). |
| 269–342 | `sync_metadata($ids)` | Per row → `mass_updates(IMAGES_TABLE, …, MASS_UPDATES_SKIP_EMPTY)` (`:320-339`); keywords/tags routed to `piwigo_image_tag` via `set_tags_of()`; sets `date_metadata_update = CURRENT_DATE`. |

Call sites: `admin/include/functions_upload.inc.php:403` (every upload),
`include/ws_functions/pwg.images.php:1685` and `:2963` (`pwg.images.syncMetadata`,
`pwg_token`-guarded), `admin/picture_modify.php:74` (the per-photo "synchronize metadata"
link, built at `:216`), `admin/batch_manager_unit.php:399`. The FS-sync page reaches the same
code via `admin/site_reader_local.php:149,155`; `admin/site_update.php` fires no triggers at all.

`MASS_UPDATES_SKIP_EMPTY` means an empty parsed value does not clobber a populated column,
but a non-empty one does.

### C. Measured format/standard capability (this container, empirical)

Environment probed directly: PHP **8.4.20**; extensions `exif`, `gd`, `imagick`, `mbstring`,
`iconv` all present; `iptcembed`/`iptcparse`/`exif_read_data` all available. **`exiftool` is
NOT installed** in the web container (`perl` is, at `/usr/bin/perl`). Imagick reports
ImageMagick **7.1.1-43 Q16 aarch64**.

Method: took a real gallery PNG, converted to each format via Imagick while setting an XMP
packet (`dc:description`) with `setImageProfile('xmp', …)`, an IPTC-IIM `2:120`
Caption-Abstract record with `setImageProfile('iptc', …)`, and `setImageProperty('exif:ImageDescription', …)`;
then read every output back **in a separate process** via raw byte grep, Imagick profiles,
`getimagesize`+`iptcparse`, and `exif_read_data`.

| Format | XMP written | IPTC written | EXIF written | Visible to Piwigo's own IPTC reader (`getimagesize` APP13) | Pixel fidelity of the write |
|---|---|---|---|---|---|
| **JPEG** | yes (`adobe:ns:meta` + `dc:description` in raw bytes) | yes (`iptcparse` returns `2#120`) | **no** | **YES** — the only format where it works | **LOSSY** (re-encode, quality 92; RMSE 0.0061) |
| **PNG** | yes (Imagick profile round-trips, 392 B) | yes (Imagick profile, 20 B) | **no** | no | lossless (RMSE 0.0) |
| **TIFF** | yes (raw bytes contain `dc:description`) | yes (24 B profile) | **no** | no | lossless (RMSE 0.0) |
| **HEIC** | yes (raw bytes contain `dc:description`) | **no** (profile dropped entirely) | **no** | no | **LOSSY** (RMSE 0.0106) |

Additional measured details:

- **Imagick cannot write EXIF.** `setImageProperty('exif:ImageDescription', 'MARKER')` followed
  by a JPEG write produced **no** `exif` profile, `exif_read_data()` returned no
  `ImageDescription`, and the marker string was absent from the raw bytes. An EXIF write
  therefore needs a different tool entirely (a dedicated PHP EXIF library, or exiftool).
- **PNG stores XMP in a compressed `zTXt` chunk, not `iTXt`.** Chunk inventory of the written
  PNG: `IHDR cHRM bKGD tEXt(78) zTXt(360) IDAT…`. Because it is compressed, the literal
  strings `dc:description` and `adobe:ns:meta` do **not** appear in the raw bytes even though
  Imagick reads the profile back correctly. A naive byte-grep verification would report a
  false negative on PNG.
- **PNG has no IPTC-IIM home that PHP core can read.** Imagick preserves an `iptc` profile
  it wrote itself, but `getimagesize()` reports no `APP13` (an APP-marker concept that is
  JPEG-specific), so `get_iptc_data()` — Piwigo's reader — sees nothing.
- **JPEG and HEIC writes are lossy** because the Imagick route decodes and re-encodes. PNG and
  TIFF came back pixel-identical (`METRIC_ROOTMEANSQUAREDERROR` = 0.0).

### D. This install's actual data

- 76 rows in `piwigo_images`; **all 76 are PNG** (`SELECT SUBSTRING_INDEX(file,'.',-1), COUNT(*) … GROUP BY 1` → `png 76`).
- Files live under `upload/2026/04/19/…png`; `galleries/` holds only `index.php`.
- Sampled rows have `comment` NULL and `author` NULL.

So on the current data set, the format in play is exactly the one where IPTC is invisible to
Piwigo's own reader and EXIF cannot be written by the available tooling — XMP is the only
standard that reaches a PNG here.

### E. Where a per-photo field already lives (DB + admin UI)

`install/piwigo_structure-mysql.sql`, `CREATE TABLE piwigo_images` at `:217-251`. Free-text
columns: **`name varchar(255)`** (`:222`), **`comment text`** (`:223`), **`author varchar(255)`**
(`:224`). There is no generic extra-text column and no per-photo key/value side table.

**`admin/picture_modify.php`** (430 lines) — the single-photo edit screen:

- guard `:9-12`; `check_status(ACCESS_ADMINISTRATOR)` `:19`; `check_input_parameter('image_id', …, PATTERN_ID)` `:21`
- `check_pwg_token()` on save `:81`
- free-text sanitisation, `:87-91`:
  ```php
  $to_sanitize_fields = array('name', 'author', 'comment');
  foreach ($to_sanitize_fields as $field)
  {
    $data[$field] = $conf['allow_html_descriptions'] ? @$_POST[$field] : strip_tags(@$_POST[$field]);
  }
  ```
- **`$data = trigger_change('picture_modify_before_update', $data);` `:102`** — a filter that can
  add or alter columns immediately before persistence
- `single_update(IMAGES_TABLE, $data, array('id' => $data['id']))` `:104-108`
- `trigger_notify('loc_end_picture_modify')` `:425`; `'PWG_TOKEN' => get_pwg_token()` `:422`

Template `admin/themes/default/template/picture_modify.tpl` — form `:95-249`. Field patterns:

```smarty
{* Title, :145-149 *}
<p><strong>{'Title'|@translate}</strong><br>
   <input type="text" class="large" name="name" value="{$NAME|@escape}"></p>

{* Description, :205-209 *}
<p><strong>{'Description'|@translate}</strong><br>
   <textarea name="comment" id="description" class="description">{$DESCRIPTION}</textarea></p>
```

**Batch Manager unit mode** — `admin/batch_manager_unit.php`; the POST branch (`:33-97`) uses
`name-<id>`, `author-<id>`, `description-<id>` and persists via `mass_updates` (`:86-93`), but the
**actual save is AJAX**: `admin/themes/default/js/batchManagerUnit.js` `saveChanges()` `:345-425`
POSTs `method: 'pwg.images.setInfo'` to `ws.php?format=json`. Server side
`ws_images_setInfo()` — `include/ws_functions/pwg.images.php:2568`, token check `:2572-2575`,
`$info_columns = array('name','author','comment','level','date_creation')` `:2596-2602`,
`strip_tags($params[$key], '<b><strong><em><i>')` `:2610`, `single_update` `:2656`.

The batch template carries a declared plugin extension point:
`{if isset($PLUGINS_BATCH_MANAGER_UNIT_ELEMENT_SUBTEMPLATE)}…{/if}` (`batch_manager_unit.tpl:252-256`)
plus a `pluginValues` JS registry (`:79`, consumed at `batchManagerUnit.js:380-386`).

### F. Where a per-photo field would surface (public page)

`picture.php` (1033 lines):

- whole row loaded with `SELECT * FROM IMAGES_TABLE …` `:461-465`, so every column is reachable
  in the template as `{$current.<column>}`
- `trigger_change('picture_pictures_data', $picture)` `:620`
- `COMMENT_IMG` from `comment`, through `trigger_change('render_element_description', …)` `:826-836`
- `INFO_AUTHOR` `:838-842`; other `$infos` `:844-892`
- `$template->assign($infos)` and `$template->assign('display_info', unserialize($conf['picture_informations']))` `:894-895`
- `trigger_notify('loc_end_picture')` `:1019` — after all assigns and after `page_header.php` (`:1018`),
  before `pparse`, so both `assign()` and `set_prefilter('picture', …)` still take effect

`$conf['picture_informations']` is a serialized bool map seeded in `install/config.sql:52-57`
(keys `author, created_on, posted_on, dimensions, file, filesize, tags, categories, visits,
rating_score, privacy_level`) and edited in `admin/configuration.php:280-283,527` — a new info
row wanting a visibility toggle would need a key here.

`themes/default/template/picture.tpl` (368 lines; **modus does not override it** — `themes/modus/template/`
holds only comment_list, footer, fotorama, header, mainpage_categories, menubar, month_calendar,
picture_content_asize, thumbnails). The info panel is `<dl id="standard" class="imageInfoTable">`
`:172`, `{strip}` `:173`, `{/strip}` `:300`, `</dl>` `:301`. Every row is the same shape:

```smarty
{if $display_info.author and isset($INFO_AUTHOR)}
<div id="Author" class="imageInfo">
    <dt>{'Author'|@translate}</dt>
    <dd>{$INFO_AUTHOR}</dd>
</div>
{/if}
```

Rows present: `#Author` `:174-179`, `#datecreate` `:180-185`, `#datepost` `:186-191`,
`#Dimensions` `:192-197`, `#File` `:198-203`, `#Filesize` `:204-209`, `#Tags` `:210-217`,
`#Categories` `:218-229`, `#Visits` `:230-235`, `#Pages` `:237-243`, `#Average` `:245-256`,
`#rating` `:258-298`. The description is rendered *outside* this list, at `:135-137`:
`{if isset($COMMENT_IMG)}<p class="imageComment">{$COMMENT_IMG}</p>{/if}`. A separate
`<dl id="Metadata">` sits at `:303-315` with the same `.imageInfo`/`dt`/`dd` row shape.

The existing per-photo *visitor* text feature for contrast: `piwigo_comments`
(`install/piwigo_structure-mysql.sql:70-85`, `content longtext` `:79`) — a one-to-many side
table with its own moderation pipeline (`include/functions_comment.inc.php`,
`include/picture_comment.inc.php`), rendered in its own `#comments` block (`picture.tpl:319-364`).

### G. The typetags precedent in this fork

`plugins/typetags` already adds an interactive per-photo control to exactly this info panel.

- Registration: `add_event_handler('loc_end_picture', 'typetags_picture_tags')` — `main.inc.php:40`,
  guarded by `script_basename() == 'picture'` (`:38`)
- Data prep `events_public.inc.php:131-171`: returns early for guests (`:135-138`), queries
  assignments and colored tags, assigns `TYPETAGS_*` template vars including
  `TYPETAGS_PWG_TOKEN = get_pwg_token()` (`:163-168`), then
  `$template->set_prefilter('picture', 'typetags_picture_prefilter')` (`:170`)
- The prefilter couples to two literal template strings, extracted as constants
  (`events_public.inc.php:4-5`) and matching `picture.tpl:214` and `:303`:
  ```php
  define('TYPETAGS_TPL_TAG_ANCHOR', '<a href="{$tag.URL}">{$tag.name}</a>');
  define('TYPETAGS_TPL_INJECT_POINT', '{if isset($metadata)}');
  ```
  Injection happens immediately *before* `{if isset($metadata)}`, i.e. just after the info
  table's `</dl>` (`:180-190`)
- JS is emitted inline via `{footer_script require='jquery'}` appended to the compiled source
  (`:193-371`); `Template::block_footer_script` → `scriptLoader->add_inline(…)`
  (`include/template.class.php:955-966`). No stylesheet is loaded on the front end; badge
  styling is inline `style=""`
- Web-service methods registered on `ws_add_methods` (`main.inc.php:65-114`); the write handler
  `ws_typetags_image_addTag` (`:189-238`) is the model for a per-photo write from the public
  page: `is_a_guest()` → 401 (`:191-194`), manual `get_pwg_token() != $params['pwg_token']` → 403
  (`:196-199`), existence checks → 404, then the write, then cache invalidation. Note it compares
  the token manually rather than calling `check_pwg_token()`, because the token arrives as a
  declared WS parameter
- Schema changes live in `maintain.class.php` — idempotent `SHOW COLUMNS` guard before
  `ALTER TABLE … ADD` (`:29-33`) and `CREATE TABLE IF NOT EXISTS` (`:35-43`), with `uninstall()`
  dropping both (`:51-57`)

Relevant core extension points catalogued from `tools/triggers_list.php` and the source:

| Event | Type | Fired at | Payload |
|---|---|---|---|
| `loc_end_picture` | notify | `picture.php:1019` | — (typetags uses this) |
| `picture_pictures_data` | change | `picture.php:620` | `array $picture` |
| `render_element_description` | change | `picture.php:831` | description string |
| `picture_modify_before_update` | change | `admin/picture_modify.php:102` | `array $data` before `single_update` |
| `loc_end_picture_modify` | notify | `admin/picture_modify.php:425` | — |
| `loc_end_add_uploaded_file` | notify | `admin/include/functions_upload.inc.php:426` | `array $image_infos` |
| `ws_images_uploadCompleted` | notify | `include/ws_functions/pwg.images.php:2859` | `image_ids, category_id, …` |
| `delete_elements` | notify | `admin/include/functions.php:348` | `array $ids` (where per-photo rows get cleaned up) |
| `ws_add_methods` | notify | `include/ws_core.inc.php:279` | `array(&$service)` |

`admin/site_update.php` fires no triggers, so the FS-sync path has no hook.

## Code References

- `include/functions_metadata.inc.php:21-70` — `get_iptc_data()`, the APP13/`iptcparse` read
- `include/functions_metadata.inc.php:126-212` — `get_exif_data()`, `exif_read_data` + GPS
- `include/config_default.inc.php:338-415` — the metadata config block and both mappings
- `include/config_default.inc.php:979` — `derivatives_strip_metadata_threshold = 256000`
- `i.php:615-617` — derivative metadata strip
- `admin/include/image.class.php:504` — `stripImage()` on the Imagick backend
- `admin/include/functions_metadata.php:269-342` — `sync_metadata()`, the file→DB direction
- `admin/include/functions_upload.inc.php:403` — sync on every upload
- `install/piwigo_structure-mysql.sql:217-251` — `piwigo_images`; free text at `:222-224`
- `admin/picture_modify.php:87-108` — sanitisation, `picture_modify_before_update`, `single_update`
- `admin/themes/default/template/picture_modify.tpl:145-149`, `:205-209` — input and textarea patterns
- `include/ws_functions/pwg.images.php:2568-2656` — `ws_images_setInfo()`, `$info_columns` at `:2596`
- `admin/themes/default/js/batchManagerUnit.js:345-425` — the AJAX save and `pluginValues` registry
- `picture.php:826-895` — `COMMENT_IMG`, `INFO_AUTHOR`, `$infos`, `display_info`
- `picture.php:1019` — `loc_end_picture`
- `themes/default/template/picture.tpl:172-301` — `<dl id="standard">` and every info row
- `themes/default/template/picture.tpl:135-137` — `<p class="imageComment">`
- `plugins/typetags/include/events_public.inc.php:4-5`, `:131-190` — prefilter constants and injection
- `plugins/typetags/main.inc.php:189-238` — the guarded per-photo write handler
- `plugins/typetags/maintain.class.php:20-57` — idempotent schema install/uninstall
- `tools/metadata.php:84-110` — the only XMP-aware code in the tree (standalone scratch script)

## Architecture Documentation

**Storage conventions.** Core keeps per-photo scalars as columns on `piwigo_images` and
per-photo multiplicity in side tables (`piwigo_image_tag`, `piwigo_comments`). Plugins in this
fork extend core tables directly with an idempotent `SHOW COLUMNS` + `ALTER TABLE` guard
(typetags adds `piwigo_tags.id_typetags` this way) and add their own tables via
`CREATE TABLE IF NOT EXISTS`. There are no foreign keys anywhere — core tables are MyISAM and
relations are by convention.

**Write paths for per-photo text.** Three exist, and they do not share a single funnel:
`single_update` from `admin/picture_modify.php:104`, `mass_updates` from
`admin/batch_manager_unit.php:86`, and `single_update` from `ws_images_setInfo()`
(`pwg.images.php:2656`) which is what the Batch Manager UI actually calls. Only the first has
a filter hook (`picture_modify_before_update`); `ws_images_setInfo` gates on a fixed
`$info_columns` allow-list with no hook.

**UI injection convention.** Plugins do not edit `.tpl` files; they register Smarty prefilters
that `str_replace` literal template text (`Template::set_prefilter`, `include/template.class.php:1015`).
The picture page's info rows are uniform enough that a new row is one more
`{if …}<div id="…" class="imageInfo"><dt>…</dt><dd>…</dd></div>{/if}` block inside
`picture.tpl:173-300`, and `themes/modus` inherits that file unchanged. Front-end JS is emitted
inline through `{footer_script require='jquery'}`; admin assets go through `{combine_script}`/`{combine_css}`.

**Caching caveat carried in CLAUDE.md.** `Template::set_prefilter()` hashes only the callback
*name* into Smarty's `compile_id`, so editing a prefilter's body leaves the previously compiled
template in place — `_data/templates_c/` must be cleared after such an edit.

**Security conventions for a public-page write.** The typetags handlers show the established
shape: `is_a_guest()` → `PwgError(401)`, manual token comparison against `get_pwg_token()` →
`PwgError(403)`, existence checks → `PwgError(404)`, `(int)` casts on ids. Note that `ws.php`
does not include `admin/include/functions.php`, so admin helpers (including
`invalidate_user_cache()`) are unavailable to WS handlers — typetags works around this with a
direct `UPDATE piwigo_user_cache`. Also relevant: `PwgError` returns as **HTTP 200 with
`stat:"fail"`**, so client code must handle failures in the `success` callback.

## Open Questions

1. **[CLOSED 2026-08-29 — see "Metadata-Writing Library Survey" below.** Verdict: exiftool is the
   only tool that writes EXIF into PNG, and it writes without re-encoding pixels. No pure-PHP
   option covers PNG EXIF.] ~~Third-party PHP metadata-writing libraries were not surveyed.~~ The sub-agent assigned to
   research `lsolesen/pel`, PHPExiftool wrappers, XMP libraries, licences, maintenance status
   and exiftool's multi-standard write syntax failed with a session rate-limit error before
   reporting. What is established here is only what the container can do natively:
   Imagick writes XMP and IPTC profiles but not EXIF; `iptcembed()` exists in PHP core (JPEG
   APP13 only, untested here); exiftool is not installed. Any claim about a specific library's
   capabilities still needs verification.
2. **[CLOSED 2026-08-29 — see the MWG subsection below.** Verdict: EXIF `ImageDescription` (0x010E),
   IPTC `2:120`, and XMP `dc:description["x-default"]` are the canonical mirror set; IPTC caps at
   2000 bytes.] ~~Exact canonical field identifiers per standard were not verified against specifications.~~
   The probe used `dc:description` (XMP) and IPTC-IIM `2:120` Caption-Abstract because they are
   the conventional caption fields, and they round-tripped. The full set the request implies —
   EXIF `ImageDescription` (0x010E) vs `UserComment` (0x9286) and its 8-byte character-code
   prefix, `photoshop:Headline`, `tiff:ImageDescription`, `exif:UserComment` in XMP, and the
   Metadata Working Group's guidance on which of these are supposed to mirror each other — was
   not confirmed against primary sources.
3. **[MOOT 2026-08-29 — exiftool writes IPTC into JPEG with byte-identical pixels (measured), so
   the `iptcembed()` route is no longer needed.]** ~~Whether `iptcembed()` can splice IPTC into an
   existing JPEG without re-encoding~~ was not tested. It operates on JPEG APP13 and would avoid the lossy Imagick round-trip, but its
   interaction with an already-present APP13 segment (replace vs. duplicate) is unverified.
4. **[CLOSED 2026-08-29 — exiftool writes EXIF+XMP into HEIC with identical pixels; `exif_read_data()`
   still cannot read it back. See the verification section.]** ~~HEIC write support beyond XMP is unresolved.~~ The IPTC profile was silently dropped on
   write; whether that is an ImageMagick delegate limitation or a format constraint was not
   determined.
5. **[CLOSED 2026-08-29 — measured: survives above the 256000-px threshold, fully stripped below.]**
   ~~Derivative propagation is unmeasured.~~ Whether metadata written to an original survives
   into derivatives above the 256000-pixel strip threshold depends on the imaging backend
   (Imagick vs GD, `admin/include/image.class.php`) and was not tested.
6. **No decision exists on write timing or conflict direction.** Whether a write-back happens on
   save, on a queue, or on demand — and what should happen when `sync_metadata()` later reads
   the same field back into the DB — is unspecified. This matters most if the new field is
   mapped into `$conf['use_iptc_mapping']`, where the file becomes authoritative on the next sync.
7. **Whether the field belongs on `piwigo_images` or in a plugin-owned side table** was not
   decided; both patterns exist in this fork (core columns vs. typetags' own table).
8. **Originals are not currently writable-by-design.** No permission, locking, or backup
   convention exists for mutating files under `upload/`; concurrent writes and failure recovery
   are unexamined.

---

## Follow-up Research 2026-08-29T06:04Z — Album-level freetext field

`git_commit: 91d3a820a0c7662678600ad201385bc9bb83e954` · `branch: master`

### Extended Research Question

> Free text field per album; text gets saved on album level, and every image gets it.
> For text on image level: same mechanism as the freetext field on single images that is
> already there (text gets saved on meta info).

Same rule as above: this section documents what exists. Everything that is a decision rather
than a fact is parked in **"For the Plan Phase"** at the end.

### Summary of the album-side findings

**1. An image belongs to many albums; nothing in the schema can hold a per-(image, album) value.**
`piwigo_image_category` has composite PK `(image_id, category_id)`
(`install/piwigo_structure-mysql.sql:179-185`) — many-to-many by construction. `piwigo_images`
carries a single nullable `storage_category_id` (`:234`) naming the one *physical* album an
image cannot be dissociated from, but that column is populated **only by filesystem sync**
(`admin/site_update.php:542`); images added through upload/API leave it NULL
(`include/functions.inc.php:2584` derives `add_method` from exactly this: `IF(storage_category_id IS NULL, 'api', 'sync')`).
Measured on this install: **all 76 images have `storage_category_id` NULL**, so there is no
"owning album" for any photo here. Display-time (`include/section_init.inc.php:303`) filters
purely on `category_id` in the join table and passes no per-image-per-album data to the template.

**2. Every album→descendant propagation in core is copy-down at set-time, not resolve-at-read.**
- `set_cat_visible()` — `admin/include/functions.php:748-779`: direct `UPDATE categories SET visible=… WHERE id IN (get_subcat_ids(…))`
- `set_cat_status()` — `admin/include/functions.php:787-…`: same shape, plus a permission-consistency sweep that `DELETE`s now-inconsistent access rows
- permissions — `admin/cat_perm.php:35-42` (status) and `:87-91` (group grants), both gated on an `apply_on_sub` checkbox that merges `get_subcat_ids()` into the target id list before writing; `add_permission_on_category()` at `admin/include/functions.php:2922` is the general-purpose version
- `apply_commentable_to_subalbums` — `include/ws_functions/pwg.categories.php:955-966`: `UPDATE categories SET commentable=… WHERE id IN (get_subcat_ids(…))`

Read-time confirms the absence of inheritance: `calculate_permissions()`
(`include/functions_user.inc.php:644-698`) diffs private category ids against **directly
granted** ids (`array_diff($private_array, $authorized_array)`, `:674`) — it never walks
`uppercats` to inherit a parent's grant.

The single exception is the representative picture (`include/category_cats.inc.php:69-127`):
resolved at read-time through a fallback chain, then opportunistically cached into
`user_representative_picture_id` via `mass_updates()` (`:121-126`, `:258-266`) when
`$conf['representative_cache_on_subcats']` (default `true`, `include/config_default.inc.php:221`).

**3. The album edit screen has no server-side save path and no filter hook.**
`admin/cat_modify.php` is display-only — included by `admin/album.php:70` for the `properties`
tab, after `admin/album.php` has run `check_status()` (`:18`), `check_input_parameter('cat_id', …)`
(`:20`) and loaded `$category` (`:24-29`). It contains no POST handling, no `check_pwg_token()`,
no `single_update`. Its only hooks are `trigger_notify('loc_begin_cat_modify')` (`:107`) and
`trigger_notify('loc_end_cat_modify')` (`:390`) — **both notify, neither a `trigger_change`
filter.** This is asymmetric to the photo side, which has `picture_modify_before_update`
(`admin/picture_modify.php:102`).

All album property saves go through **`pwg.categories.setInfo`**, posted by
`admin/themes/default/js/cat_modify.js:74-85` from `#cat-name` / `#cat-comment`. Handler at
`include/ws_functions/pwg.categories.php:891-981`: fixed allow-list
`$info_columns = array('name','comment','commentable')` (`:946`), `strip_tags()` unless
`$conf['allow_html_descriptions']` **and** a `pwg_token` was supplied (`:944-949`),
`single_update(CATEGORIES_TABLE, …)` (`:974`), `pwg_activity('album', …, 'edit', …)` (`:981`).
Registered `admin_only` + `post_only` at `ws.php:881-905`. No `trigger_change` anywhere in it.

**4. There is no server-side batch or queue mechanism in core.** No cron, no job table, no
`set_time_limit` outside vendored libraries. The established pattern for "operate on N photos
with a progress bar" is **client-side chunked serialized AJAX against `ws.php`**:
`admin/themes/default/js/batchManagerGlobal.js` chunks ids into blocks of
`min(round(n/2), 1000)`, drives them through `jQuery.manageAjax.create('queued', {queue:true, maxRequests:1})`,
and updates a progress bar per callback — used for `pwg.images.delete` (`:328-393`) and
`pwg.images.syncMetadata` (`:239-309`). The server branches for those actions
(`admin/batch_manager_global.php:379-410`) only render a flash message from tallies the client
posts back.

**5. The closest existing precedent for "one text value onto every photo of an album" is the
Batch Manager `author` action.** `admin/batch_manager_global.php:243-266`: builds
`array('id'=>$image_id, 'author'=>$_POST['author'])` for every id in `$collection` and calls
`mass_updates(IMAGES_TABLE, array('primary'=>array('id'),'update'=>array('author')), $datas)`.
`title` (`:268-289`) and `date_creation` (`:291-317`) are identical in shape. None pass
`MASS_UPDATES_SKIP_EMPTY`, so an empty value writes an explicit `NULL` — that is how the
"remove" checkboxes work (`:249`, `:271`). The album scoping is upstream and purely a filter:
`admin/batch_manager.php:468-497` builds `$page['cat_elements_id']` from
`IMAGE_CATEGORY_TABLE WHERE category_id IN (…)` (recursive via `get_subcat_ids()`), and the
action switch itself is generic over `$collection` with no category awareness.

**This is a one-time write, not a live association.** `add_tags` (`admin/batch_manager_global.php:117-126`)
behaves the same way: a photo added to the album afterwards does not retroactively receive the
tag. No persistent album→tag or album→text mapping exists anywhere in the tree.

### Detailed Findings

#### H. `piwigo_categories` — the album row

Schema `install/piwigo_structure-mysql.sql:42-63`; verified against the live database.

| Column | Type | Note |
|---|---|---|
| `id` | `smallint(5) unsigned auto_increment` | PK |
| `name` | `varchar(255) NOT NULL default ''` | free text |
| `id_uppercat` | `smallint(5) unsigned` | direct parent |
| `comment` | `text` | free text — the album description |
| `dir` | `varchar(255)` | NULL ⇒ virtual album |
| `rank`, `global_rank`, `image_order` | | ordering |
| `status` | `enum('public','private')` | |
| `visible` | `enum('true','false')` | "locked" is `visible='false'` |
| `commentable` | `enum('true','false')` | |
| `representative_picture_id` | `mediumint(8) unsigned` | |
| `uppercats` | `varchar(255) NOT NULL default ''` | comma list of ancestors incl. self |
| `permalink` | `varchar(64) binary` | UNIQUE |
| `lastmodified` | `TIMESTAMP … ON UPDATE CURRENT_TIMESTAMP` | |

There are exactly **two** free-text columns on an album: `name` and `comment`. No generic
extra-text column, no per-album key/value side table. The only other per-category tables are
the two permission link tables `piwigo_group_access` (`:115-124`) and `piwigo_user_access`
(`:399-408`).

Hierarchy helpers: `get_subcat_ids($ids)` — `include/functions_category.inc.php:301-323`,
regex-matches `uppercats` against `(^|,)$id(,|$)` to return self + all descendants;
`get_uppercat_ids($cat_ids)` — `admin/include/functions.php:965-987`, explodes the `uppercats`
strings to return self + all ancestors. Image-set helper:
`get_image_ids_for_categories($cat_ids, $mode='AND', …)` —
`include/functions_category.inc.php:617`, `IMAGES_TABLE INNER JOIN IMAGE_CATEGORY_TABLE WHERE category_id IN (…)`
with `get_sql_condition_FandF()` permission filtering.

**Live data on this install:** 1 album (`id=1`, `Erstes Album`, `id_uppercat` NULL, `dir` NULL
⇒ virtual, `comment` NULL), 76 images, 76 rows in `piwigo_image_category`, every image in
exactly 1 album. So the multi-album conflict case has **no local test data**.

#### I. The album admin screen

`admin/album.php` is the dispatcher: `check_status(ACCESS_ADMINISTRATOR)` `:18`,
`check_input_parameter('cat_id', $_GET, false, PATTERN_ID)` `:20`, `SELECT *` into `$category`
`:24-29`, `die("unknown album")` `:31-34`, tabsheet `:47-51`, then include by tab:
`properties`→`admin/cat_modify.php` (`:70`), `sort_order`→`admin/element_set_ranks.php` (`:73`),
`permissions`→`admin/cat_perm.php` (`:78`), else `admin/album_<tab>.php` (`:83`).

Tabs are declared in `admin/include/add_core_tabs.inc.php:27-32` (`case 'album'`):
`properties`, `sort_order`, `permissions`, `notification`. The tabsheet fires
`trigger_change('tabsheet_before_select', $this->sheets, $this->uniqid)`
(`admin/include/tabsheet.class.php:76`) with `uniqid = 'album'`, so a plugin *can* add a tab —
but `admin/album.php:83` resolves any unknown tab to a core-path include
`admin/album_<tab>.php`, and the only such file that exists is `admin/album_notification.php`.

Template `admin/themes/default/template/cat_modify.tpl`:

```smarty
{* Name, :136-137 *}
<label for="cat-name">{'Name'|@translate}</label>
<input type="text" id="cat-name" value="{$CAT_NAME}" maxlength="255">

{* Description, :141-142 *}
<label for="cat-comment">{'Description'|@translate} <span id="desc-zoom-square" …></span></label>
<textarea class="sync-textarea" resize="false" rows="5" name="comment" id="cat-comment">{$CAT_COMMENT}</textarea>
```

Note `#cat-name` has no `name=` attribute at all — the form is not a `<form>` submit. The save
button is `<span class="buttonLike" id="cat-properties-save">` (`:188`), wired in JS. The
description is duplicated into an expand-modal at `:197` (`id="cat-comment-modal"`). Booleans
follow `<input type="checkbox" name="X" id="cat-X" value="true" {if …}checked{/if}>`
(commentable `:156`, locked `:167`). Token reaches JS as `const pwg_token = '{$PWG_TOKEN}'`
(`:18`) from `$template->assign('PWG_TOKEN', get_pwg_token())` (`admin/cat_modify.php:388`).

Other album-property screens: `admin/cat_list.php` (tree/list, inline rename, bulk delete;
`check_pwg_token()` gated `:24-26`), `admin/cat_options.php` (bulk boolean toggler across many
albums via `cat_true`/`cat_false` + `falsify`/`trueify`, `check_pwg_token()` `:24`, actions at
`:35-99`), `admin/cat_perm.php` (per-album permissions, `check_pwg_token()` `:32`),
`admin/album_notification.php` (email notification form). **`admin/cat_move.php` does not
exist** — moving is `pwg.categories.move`.

#### J. Album WS surface

All `pwg.categories.*` methods are registered `admin_only`, and all writers additionally
`post_only`:

| Method | `ws.php` | Handler |
|---|---|---|
| `pwg.categories.calculateOrphans` | `:600-609` | `pwg.categories.php:1373` |
| `pwg.categories.getAdminList` | `:611-628` | `:599` |
| `pwg.categories.add` | `:633-654` | `:753` |
| `pwg.categories.delete` | `:656-669` | `:1146` |
| `pwg.categories.move` | `:671-683` | `:1214` |
| `pwg.categories.setRepresentative` / `deleteRepresentative` / `refreshRepresentative` | `:685-720` | `:991` / `:1044` / `:1090` |
| **`pwg.categories.setInfo`** | `:881-905` | `:891` |
| `pwg.categories.setRank` | `:908-924` | `:804` |
| `pwg.categories.getImages` / `getList` | | `:19` / `:245` |

`ws_categories_setInfo` in detail (`include/ws_functions/pwg.categories.php`):
- `:895-898` token check — **only enforced when a token is supplied**: `if (isset($params['pwg_token']) and get_pwg_token() != $params['pwg_token'])`
- `:901-909` re-fetch row, `PwgError(404)` if absent
- `:913-923` `status` validated against `array('private','public')`, applied via `set_cat_status()`
- `:929-933` `visible`/`commentable` validated `/^(true|false)$/i`; `:936-939` `visible` via `set_cat_visible()`
- `:941` `$info_columns = array('name', 'comment', 'commentable')`
- `:944-949` `strip_tags()` unless `$conf['allow_html_descriptions']` **and** `isset($params['pwg_token'])`
- `:955-966` `apply_commentable_to_subalbums` → `UPDATE … WHERE id IN (get_subcat_ids(…))`
- `:969-973` `single_update(CATEGORIES_TABLE, …)`
- `:981` `pwg_activity('album', …, 'edit', array('fields' => …))`

`pwg_activity()` is defined at `include/functions.inc.php:556` and writes to `piwigo_activity`
(table confirmed present in the live DB) — a per-object audit trail already covering album edits.

#### K. Where album text is displayed today

Current album's own description:
- assigned `include/section_init.inc.php:225-228` — `$page['comment']` = `trigger_change('render_category_description', $page['category']['comment'], 'main_page_category_description')`
- gated `index.php:341-345` — shown only when `$page['start']==0` or `$conf['album_description_on_all_pages']`, and not in a chronology view → `CONTENT_DESCRIPTION`
- rendered `themes/default/template/index.tpl:200-203` — `{if !empty($CONTENT_DESCRIPTION)}<div class="additional_info">{$CONTENT_DESCRIPTION}</div>{/if}`. modus has no `index.tpl`, so it inherits this.

Sub-album descriptions in a parent listing:
- assigned `include/category_default.inc.php:99-105` via `render_element_description($row, 'main_page_element_description')` (defined `include/functions_html.inc.php:546`)
- rendered `themes/default/template/mainpage_categories.tpl:40-42` (`<p>`), overridden by
  `themes/modus/template/mainpage_categories.tpl:78-79` (`<div>`) — same conditional, different wrapper

Filter hook: `render_category_description` is a `trigger_change` with signature
`(string category_description, string action)` — fired from `include/section_init.inc.php:226`,
`include/category_cats.inc.php:330`, and `include/ws_functions/pwg.categories.php:390` and `:682`.
Core already registers `pwg_nl2br` on it (`include/common.inc.php:358`). A sibling hook
`render_category_literal_description` also exists (`tools/triggers_list.php:729`).

**The album description does not appear on the single-photo page at all.** `picture.php:826-834`
assigns `COMMENT_IMG` from the *photo's* own `images.comment`, rendered at
`themes/default/template/picture.tpl:135-136`. The `#Categories` info row
(`picture.tpl:218-227`) lists album **names only**, built from `$related_categories`
(`picture.php:924-954` via `get_cat_display_name()`); no album description is fetched there.

#### L. How a photo enters an album, and what removes it

Every virtual link funnels through one insert: `associate_images_to_categories($images, $categories)`
— `admin/include/functions.php:2024-2102`, `mass_inserts()` at `:2094-2098`, skipping pairs
that already exist and assigning a per-category `rank`.

| Path | Call site |
|---|---|
| Upload (new + duplicate) | `admin/include/functions_upload.inc.php:458` (`add_uploaded_file_add_to_categories()`) |
| WS `pwg.images.add` / `addFile` / `addSimple` | `include/ws_functions/pwg.images.php:1364, 1464, 1611, 1872, 2108` → `add_uploaded_file()` |
| Batch Manager "associate" | `admin/batch_manager_global.php:171-174` |
| Batch Manager "move" | `admin/batch_manager_global.php:199` → `move_images_to_categories()` (`admin/include/functions.php:2151-2182`) |
| WS `pwg.images.setCategory` | `include/ws_functions/pwg.images.php:3031` (associate) / `:3039` (move) / `:3033` (dissociate) |
| FS sync | `admin/site_update.php:542` (`storage_category_id`) + `:551-555` (`$insert_links`) |

Removal: `dissociate_images_from_category()` (`admin/include/functions.php:2112-2141`) refuses to
break the storage link (`:2122-2125`: `category_id != storage_category_id OR storage_category_id IS NULL`);
`move_images_to_categories()` carries the same guard at `:2174`.

`delete_categories($ids, $photo_deletion_mode='no_delete')` — `admin/include/functions.php:53-151`:
expands via `get_subcat_ids()` (`:62`), always `delete_elements()` for photos physically stored
there (`:65-72`), optionally deletes orphaned/all virtually-linked photos (`:75-107`), deletes
`IMAGE_CATEGORY_TABLE` rows (`:110-115`), permission rows (`:118-130`), the category rows
(`:133-137`), permalinks + user cache (`:139-147`), then
`trigger_notify('delete_categories', $ids)` (`:149`) and `pwg_activity('album', …, 'delete')` (`:150`).

`delete_elements($ids, $physical_deletion=false)` — `:260-350`: fires
`trigger_notify('begin_delete_elements', $ids)` (`:266`), clears `COMMENTS_TABLE` (`:280-284`),
`IMAGE_CATEGORY_TABLE` (`:286-291`), `IMAGE_FORMAT_TABLE`, `IMAGE_TAG_TABLE` (`:300-305`),
`FAVORITES_TABLE`, `RATE_TABLE`, `CADDIE_TABLE`, then `IMAGES_TABLE` (`:328-333`), refreshes
representatives (`:336-346`), and fires `trigger_notify('delete_elements', $ids)` (`:348`).

Note the asymmetry: `delete_categories` fires a notify hook, so a plugin owning per-album rows
has a cleanup point. There is **no** hook on `associate_images_to_categories()` — a plugin
cannot observe a photo joining an album.

#### M. Batch Manager plugin surface

`admin/themes/default/template/batch_manager_global.tpl` declares
`element_set_global_plugins_actions` — an array of `{ID, NAME, CONTENT}` rendered as an
`<option>` in the action `<select>` (`:423-427`) and as a `<div id="action_{$action.ID}" class="bulkAction">`
panel (`:550-555`). The variable is **never assigned by core PHP**; it exists solely for a
plugin to `$template->append()` onto, from `loc_begin_element_set_global`
(`admin/batch_manager_global.php:32`) or `loc_end_element_set_global` (`:344`). Show/hide of
`.bulkAction` divs is generic jQuery in `admin/themes/default/js/batchManagerGlobal.js`, so a
plugin panel needs no extra JS. A third hook `element_set_global_action` fires at
`admin/batch_manager_global.php:429` with `$action` and `$collection`.

`mass_updates($tablename, $dbfields, $datas, $flags=0)` — `include/dblayer/functions_mysqli.inc.php:275`,
`MASS_UPDATES_SKIP_EMPTY` defined at `:265`. Under 10 rows it issues N single `UPDATE`s;
at 10+ it switches to a multi-table update. Without the flag, an empty value writes explicit `NULL`.

`plugins/typetags` registers handlers on `init`, `loc_end_picture`, `render_tag_name`,
`loc_end_tags`, `loc_begin_page_header`, `loc_begin_admin_page` (×2) and `ws_add_methods`
(`main.inc.php:16, 40, 46, 50, 53, 58-59, 65`) — **none at album level**. No plugin in this tree
alters `piwigo_categories` or owns a per-album table.

### Code References (album side)

- `install/piwigo_structure-mysql.sql:42-63` — `piwigo_categories`
- `install/piwigo_structure-mysql.sql:179-185` — `piwigo_image_category`, composite PK
- `install/piwigo_structure-mysql.sql:234` — `images.storage_category_id`
- `admin/album.php:18-83` — album admin dispatcher, tabs, includes
- `admin/include/add_core_tabs.inc.php:27-32` — the four core album tabs
- `admin/include/tabsheet.class.php:76` — `tabsheet_before_select`
- `admin/cat_modify.php:105-107`, `:388-390` — access check, `loc_begin/end_cat_modify`, `PWG_TOKEN`
- `admin/themes/default/template/cat_modify.tpl:136-142`, `:188` — name/description markup, save button
- `admin/themes/default/js/cat_modify.js:68-90` — the properties AJAX save
- `include/ws_functions/pwg.categories.php:891-981` — `ws_categories_setInfo`; allow-list `:946`; subalbum propagation `:955-966`
- `ws.php:881-905` — `pwg.categories.setInfo` registration, `admin_only` + `post_only`
- `admin/include/functions.php:748-779` — `set_cat_visible()`, copy-down
- `admin/include/functions.php:787-…` — `set_cat_status()`, copy-down + permission sweep
- `admin/cat_perm.php:35-42`, `:87-91` — the `apply_on_sub` checkbox pattern
- `admin/include/functions.php:2922` — `add_permission_on_category()`
- `include/functions_user.inc.php:644-698` — `calculate_permissions()`, no ancestor walk at read
- `include/category_cats.inc.php:69-127`, `:258-266` — representative resolve-then-cache
- `include/functions_category.inc.php:301-323` — `get_subcat_ids()`
- `include/functions_category.inc.php:617` — `get_image_ids_for_categories()`
- `admin/include/functions.php:965-987` — `get_uppercat_ids()`
- `admin/include/functions.php:2024-2102` — `associate_images_to_categories()` (no hook)
- `admin/include/functions.php:2112-2141`, `:2151-2182` — dissociate/move, storage-link guard
- `admin/include/functions.php:53-151` — `delete_categories()`, `trigger_notify('delete_categories')` at `:149`
- `admin/batch_manager.php:468-497` — album filter → `$page['cat_elements_id']`
- `admin/batch_manager_global.php:243-266` — the `author` bulk free-text write
- `admin/batch_manager_global.php:117-126` — `add_tags` bulk write
- `admin/batch_manager_global.php:32`, `:344`, `:429` — the three batch plugin hooks
- `admin/themes/default/template/batch_manager_global.tpl:423-427`, `:550-555` — `element_set_global_plugins_actions`
- `admin/themes/default/js/batchManagerGlobal.js:239-309`, `:328-393` — the chunked-AJAX progress pattern
- `include/dblayer/functions_mysqli.inc.php:265`, `:275` — `MASS_UPDATES_SKIP_EMPTY`, `mass_updates()`
- `include/section_init.inc.php:225-228`, `:303` — album description assign; per-album image filter
- `index.php:341-345`, `themes/default/template/index.tpl:200-203` — `CONTENT_DESCRIPTION`
- `include/category_default.inc.php:99-105`, `themes/default/template/mainpage_categories.tpl:40-42`, `themes/modus/template/mainpage_categories.tpl:78-79` — sub-album descriptions
- `include/functions.inc.php:556` — `pwg_activity()`
- `include/functions.inc.php:2584` — `add_method` derived from `storage_category_id`

### Architecture Documentation (album side)

**Piwigo denormalizes; it does not inherit.** With one exception (the representative picture,
which resolves then caches), every album-scoped property that "applies to sub-albums" is
physically written onto each descendant row at the moment the admin sets it. The read path has
no ancestor walk. A feature phrased as "the album's text, and every image gets it" therefore
maps onto an existing core idiom only if it too is a copy-down write; a resolve-at-read design
would be the first of its kind in this codebase for a text field.

**The album's write funnel is a WS method with a fixed allow-list and no filter hook.** Unlike
the photo side — where `picture_modify_before_update` (`admin/picture_modify.php:102`) lets a
plugin inject a column immediately before `single_update` — the album side offers only two
`trigger_notify` events on a display-only page, and `ws_categories_setInfo` hard-codes
`array('name','comment','commentable')` with no extension point. A plugin adding an album field
cannot ride the existing save; it needs its own WS method (the typetags handlers at
`plugins/typetags/main.inc.php:189-238` are the in-repo model) or its own admin page.

**Bulk photo writes are client-driven.** Core has no queue, cron, or job table. The one
established shape for an N-photo operation is: a WS method that takes a comma-joined id list,
plus JS that chunks into `min(round(n/2), 1000)`-sized blocks and serializes them through
`jQuery.manageAjax` with a progress bar. `pwg.images.syncMetadata` and `pwg.images.delete`
both work this way.

**Plugin extension points at album level, complete list found:**
`loc_begin_cat_modify` / `loc_end_cat_modify` (notify, no vars), `loc_begin_cat_list` /
`loc_end_cat_list`, `render_category_description` (change: description, action),
`render_category_literal_description` (change: description), `render_category_name` (change:
name, location), `delete_categories` (notify: ids), `tabsheet_before_select` (change: sheets,
uniqid), `loc_begin_index` / `loc_end_index`, `loc_begin_index_category_thumbnails` (notify) /
`loc_end_index_category_thumbnails` (change), `get_categories_menu_sql_where`,
`get_category_preferred_image_orders`, `get_index_album_derivative_params`. Note
`tools/triggers_list.php:81-85` records `delete_categories` as living in
`admin\include\functions.inc.php`; the actual file is `admin/include/functions.php` (call at `:150`).

### Open Questions (album side, factual gaps)

9. **Whether the E2E/integration fixtures can build a multi-album photo** was not checked.
   `plugins/typetags/tests/Support/FixtureBuilder.php` was not read in this pass, so it is
   unknown whether it can create a second album and a photo linked to both — the exact fixture
   the conflict case needs.
10. **Whether `pwg.images.setInfo`'s `multiple_value_mode` (`ws.php:875`) applies to categories**
    was noted in the registration text but the handler branch was not traced.
11. **[CLOSED — `false` (`include/config_default.inc.php:289`).** The album description shows only
    on page 1 unless enabled.]
12. **[CLOSED — it associates "only not already associated images": existing pairs are skipped and
    keep their rank; new pairs get `++max_rank` per category.** No duplicate rows, no rank churn.]
13. **Is adding a system package to the DDEV image acceptable?** exiftool needs
    `libimage-exiftool-perl` via `webimage_extra_packages` in `.ddev/config.yaml` — the one tracked
    file in `.ddev/`. Also unexamined: whether a production Piwigo host can be assumed to have it.
14. **Shell-out safety.** Piwigo core shells out nowhere today. Passing user-supplied provenance text
    to an exiftool command line makes argument escaping a security boundary; no existing pattern in
    this codebase to follow.
15. **[CLOSED 2026-08-29 — both probed: they write and read back cleanly via the XMP namespace.]**
    ~~`photoshop:Headline` and `tiff:ImageDescription` were not probed.~~ The semantic caveat stands:
    `Headline` is a short-title field, not a caption mirror in the MWG mapping.
16. **Scan-date and owner have no home in the schema.** `date_creation` and `date_available` exist;
    a third date does not. Whether "owner" is free text or a reference to the planned person-tagging
    system (`docs/backlog.md`) is undecided.
17. **[CLOSED 2026-08-29 — read directly; see resolution 8a. It manipulates tags on pre-existing
    rows only: no album creation, no image creation, no file placement.]** ~~Whether the fixture
    builder can construct the new cases~~ — a second album, a photo with
    provenance fields, a photo whose file already carries conflicting metadata —
    `plugins/typetags/tests/Support/FixtureBuilder.php` was still not read.

### Scoping decision recorded 2026-08-29 (mid-research)

The many-to-many finding above (§ "Summary of the album-side findings", point 1) is **parked, not
resolved**. Instruction received during this research pass:

> an image can be in many albums. That's the crux for "album text propagates to every image" —
> ignore for now; assume image → album is a 1:1 relationship.

So for the freetext-per-album feature, **assume every photo belongs to exactly one album.** The
codebase does not enforce this: `piwigo_image_category` has composite PK `(image_id, category_id)`
(`install/piwigo_structure-mysql.sql:179-185`) and every association path listed in §L can create a
second row for the same image. Making the constraint real is now tracked separately in
`docs/backlog.md` ("enforce a 1:1 photo-album relationship").

Facts that remain true and still bear on the plan under the 1:1 assumption:

- The assumption **matches this install today**: all 76 images sit in exactly 1 album (measured).
- It is **not enforced anywhere** — no unique index on `image_id`, no guard in
  `associate_images_to_categories()` (`admin/include/functions.php:2024-2102`), no hook to observe
  a photo joining a second album.
- `storage_category_id` is **not** a usable stand-in for "the one album": it is populated only by
  filesystem sync (`admin/site_update.php:542`) and is NULL for all 76 images here.
- Under 1:1, "which album's text applies to this photo" has one answer, so a per-photo *derived*
  value is well-defined. The open questions become about **timing and precedence** (below), not
  about ambiguity of source.

### Scoping decision recorded 2026-08-29 (mid-research) — UI shape

Instruction received after the album findings were written:

> `The album edit screen cannot be extended the way the photo screen can` — fine to have a button
> and a modal dialogue or the like.

This relaxes the UI constraint, and it changes the assessment. The earlier statement conflated two
separate things. Splitting them:

- **The album *save* path is genuinely closed.** `ws_categories_setInfo` hard-codes
  `$info_columns = array('name','comment','commentable')` (`include/ws_functions/pwg.categories.php:946`)
  and fires no `trigger_change`. There is no album equivalent of
  `picture_modify_before_update` (`admin/picture_modify.php:102`). A new album field cannot ride
  the existing save — it needs its own WS method. **This remains true.**
- **The album *screen* is fully injectable.** With a button + modal accepted, the existing prefilter
  convention covers it, and `plugins/typetags` already does exactly this on two other admin screens.

#### The injection path, verified

`admin.php` routing and dispatch:

- `admin.php:147-155` — `?page=album-134-properties` is rewritten to `$_GET['page']='album'`,
  `$_GET['cat_id']=134`, `$_GET['tab']='properties'`
- `admin.php:173` — `$page['page'] = $_GET['page']` (so `$page['page'] === 'album'`)
- `admin.php:406` — `trigger_notify('loc_begin_admin_page')`
- `admin.php:407` — `include(PHPWG_ROOT_PATH.'admin/'.$page['page'].'.php')`

The hook at `:406` fires **immediately before** the page include, so a handler can set a prefilter
that is in place by the time the album template is compiled. The compile happens inside the
included file:

- `admin/cat_modify.php:167` — `$template->set_filename('album_properties', 'cat_modify.tpl')`
- `admin/cat_modify.php:393` — `$template->assign_var_from_handle('ADMIN_CONTENT', 'album_properties')`

**The template handle is `album_properties`, not `cat_modify`** — that is the string a prefilter
must be registered against.

The in-repo precedent is `plugins/typetags/include/events_admin.inc.php:69-124`
(`typetags_admin_photo`): registered on `loc_begin_admin_page` (`main.inc.php:58-59`, guarded by
`defined('IN_ADMIN')`), returns early unless `in_array($page['page'], array('photo','batch_manager'))`
(`:72-76`), then calls `$template->set_prefilter('picture_modify', …)` (`:112`) or
`set_prefilter('batch_manager_global', …)` (`:116`). The same shape with `'album'` and
`'album_properties'` reaches the album screen.

#### The modal pattern already exists on this screen

`admin/themes/default/template/cat_modify.tpl` ships its own expand-modal for the description
field — no library, no framework:

- trigger: `<span id="desc-zoom-square" class="icon-resize-full tiptip" title="{'Expand'|@translate}">` (`:141`)
- markup: `<div class="desc-modal" id="desc-modal"> … <div class="desc-modal-content"> …
  <div class="desc-modal-header">/<div class="desc-modal-body">/<div class="desc-modal-footer">` (`:191-203`)
- body field: `<textarea class="sync-textarea" name="comment-modal" id="cat-comment-modal">{$CAT_COMMENT}</textarea>` (`:197`)
- close: `<p id="desc-modal-close" class="cat-modify-footer-see-out">` (`:200`)
- styling: a plain inline `<style>` block in the template itself, starting `:206`

Button convention on the same screen: `<span class="buttonLike" id="cat-properties-save"><i class="icon-floppy"></i> …</span>` (`:188`),
wired in JS rather than being a form submit.

So a button + modal on the album screen matches an existing pattern on that exact page, needs no
new dependency, and is reachable through a documented hook. The two constraints that remain are
unchanged by this decision: the write still needs its own WS method (the typetags handlers at
`plugins/typetags/main.inc.php:189-238` are the model — `is_a_guest()` → 401, manual token compare
→ 403, existence check → 404), and applying text to N photos still has no server-side batch
mechanism (§M), so it follows the client-chunked pattern.

Caveat carried from CLAUDE.md: `Template::set_prefilter()` hashes only the callback *name* into
Smarty's `compile_id` (`include/template.class.php:1060-1070`), so editing a prefilter body leaves
the previously compiled template in place — `_data/templates_c/` must be cleared after such an edit.

Also noted: `admin.php:326-338` calls `invalidate_user_cache()` on any POST to `page=album`. A
write routed through `ws.php` instead does not pass through `admin.php` at all, and `ws.php` does
not include `admin/include/functions.php`, so `invalidate_user_cache()` is unavailable there —
typetags works around this with a direct `UPDATE piwigo_user_cache` (documented in the original
Architecture section above).

---

---

## For the Plan Phase — Requirements Engineering

Everything below is elicitation, not research findings and not recommendations. It is parked here
because the research pass surfaced the questions but they are decisions for the plan, and several
of them change what gets built.

### Q1 — What is the album freetext field *for*?

The stated mechanism ("text on album, every image gets it, image-level text goes to file metadata")
describes a solution. The underlying need is not recorded anywhere in the repo. Candidate readings,
which lead to different designs:

- **(a) A default/shorthand** — "I don't want to type the same caption 200 times." Album text is a
  labour-saving seed; per-photo text is the real value.
- **(b) A shared provenance stamp** — "every photo from this shoot carries the same credit line."
  Album text is the authority; per-photo divergence is an error.
- **(c) Context that belongs in the file** — "the exported JPEG should say where this came from,"
  independent of what Piwigo shows.

Suggested answer if none is given: **(a)**, because it is the only reading the existing Batch
Manager `author` action (`admin/batch_manager_global.php:243-266`) already supports, which keeps
the feature inside a proven core idiom.

### Q2 — Copy-down or resolve-at-read?

Core is unambiguous here: `set_cat_visible`, `set_cat_status`, permissions, and
`apply_commentable_to_subalbums` all **copy down at set-time**. Only the representative picture
resolves at read and caches. So:

- **Copy-down**: on save, write the album's text onto every photo's own field. Matches core idiom,
  survives the photo leaving the album, is directly visible in `pwg.images.setInfo` and the Batch
  Manager. Cost: the album value and the photo values drift apart the moment either is edited, and
  "which is authoritative" becomes a live question (Q3).
- **Resolve-at-read**: store once on the album, look it up when rendering a photo. No drift. Cost:
  first text field in this codebase to work that way; the photo's *file metadata* still needs a
  materialized value at write time, so the resolve happens anyway at export.

Note the write-back requirement forces materialization regardless — a value that exists only as a
join cannot be embedded in a JPEG. The question is whether the DB also materializes it.

### Q3 — Precedence when both album text and photo text exist

Four possible rules, all defensible:

| Rule | Album text | Photo text | Result on the photo |
|---|---|---|---|
| Photo wins | "Wedding 2026" | "Cutting the cake" | "Cutting the cake" |
| Album wins | "Wedding 2026" | "Cutting the cake" | "Wedding 2026" |
| Concatenate | "Wedding 2026" | "Cutting the cake" | "Wedding 2026 — Cutting the cake" |
| Two separate fields | "Wedding 2026" | "Cutting the cake" | both, rendered separately |

This is the single highest-leverage unanswered question: it determines whether one field or two
exist on the photo, and therefore the whole data model. Suggested answer: **photo wins**, with the
album value acting as the seed (consistent with Q1(a)).

### Q4 — When does the album text reach the photos?

- On saving the album (immediate, synchronous — but there is no server-side batch mechanism; see §M)
- On an explicit "apply to all photos" button (matches the `apply_on_sub` checkbox idiom at
  `admin/cat_perm.php:35-42`, and the Batch Manager's chunked-AJAX progress pattern)
- Lazily, when each photo is next rendered or saved

Constraint from the research: core has **no queue and no cron**. A synchronous save touching N
photos and rewriting N image files is unbounded work in one request. The only established shape for
this is client-driven chunking (`admin/themes/default/js/batchManagerGlobal.js:239-309`).

### Q5 — Does a photo added *later* inherit the album text?

`add_tags` and the `author` bulk action both answer "no" — they are one-time writes over the
collection that existed at the moment the action ran. There is **no hook on
`associate_images_to_categories()`**, so a copy-down design cannot observe a late arrival without
adding one. Under resolve-at-read, late arrivals inherit for free.

### Q6 — What happens on edit, move, and delete?

- Album text is **edited** after propagation: do already-propagated photos update? Only those that
  still hold the old album value verbatim? None?
- Photo is **moved** to another album (`move_images_to_categories()`, `admin/include/functions.php:2151`):
  does it lose the old album's text, gain the new one, keep what it has?
- Album is **deleted** (`delete_categories()`, `admin/include/functions.php:53-151`, with
  `photo_deletion_mode='no_delete'` the photos survive): does their inherited text survive?
- Photo is **removed** from the album but not deleted (`dissociate_images_from_category()`).

Under 1:1 (Q's premise), "move" and "dissociate" are the only ways a photo changes album, which
narrows this considerably — but does not answer it.

### Q7 — Does the album field also write into files, or only the photo field?

The album has no file of its own. Two readings: album text is purely a UI convenience that never
touches bytes (only the derived per-photo value does), or writing the album text is itself the
point and it lands in every member file. Related: does editing album text alone trigger N file
rewrites?

### Q8 — Round-trip with `sync_metadata()`

Already flagged as Open Question 6 in the original research and unchanged by the album layer: if the
field is mapped into `$conf['use_iptc_mapping']`, the next `sync_metadata()` run
(`admin/include/functions_metadata.php:269`, fired on *every upload*,
`admin/include/functions_upload.inc.php:403`) reads the file back into the DB. With an album layer
on top there are now three potential writers of one value — album, photo, file — and no defined
precedence between them.

### Q9 — Non-functional and boundary questions

- **Field length**: album `comment` is `text`, photo `comment` is `text`, but photo `name` and
  album `name` are `varchar(255)`. IPTC-IIM `2:120` Caption-Abstract has its own length cap;
  XMP `dc:description` does not. What truncation is acceptable?
- **HTML**: `$conf['allow_html_descriptions']` gates `strip_tags()` in both
  `ws_categories_setInfo` (`:944-949`) and `ws_images_setInfo` (`pwg.images.php:2610`). HTML in a
  field destined for an EXIF/IPTC packet is a different question from HTML on a web page.
- **Encoding**: `get_iptc_data()` charset-converts from windows-1252/iso-8859-1
  (`include/functions_metadata.inc.php:99-113`); the DB is `utf8mb3`. What happens to a non-Latin-1
  album description?
- **Permissions**: `pwg.categories.setInfo` is `admin_only`. Is album text admin-only, or should
  non-admin users with album access be able to set it?
- **Audit**: `pwg_activity('album', …)` already logs album edits (`pwg.categories.php:981`). Should
  a propagation event be logged as one album action or N photo actions?
- **Failure mode**: if the file write fails for 3 of 200 photos, is the operation partially
  applied, rolled back, or retried? Note file writes cannot participate in a DB transaction, and
  the tables are MyISAM (no transactions at all).

---

## Decisions Recorded 2026-08-29 (answers to the open questions)

Answers given by the product owner. Recorded verbatim in intent, with the consequences the
research already established. **Four of these conflict with each other or with a measured
constraint** — flagged inline as ⚠ and collected at the end. Resolving them is plan-phase work;
this section only records what was decided and what it collides with.

| # | Question | Decision |
|---|---|---|
| 1 | Purpose | **Provenance metadata for scanned photos** — see below; this is not a single freetext field |
| 2 | Precedence | **(b) Two separate fields**, album-level and photo-level, independent |
| 3 | Propagation model | **(a) Copy-down at set-time** |
| 4 | Timing | (b) on album save — **narrowed by C2: DB only, file write-back separate** |
| 5 | Late arrivals inherit | **(b) Yes** |
| 6 | Album text edited after propagation | **(b) Re-apply to all photos, overwriting** |
| 7 | Photo moved / dissociated | **Ask the user at move time** |
| 8 | Album deleted, photos survive | **(c) Prompt the admin at delete time** |
| 9 | What is written where | **Photo-level text → image file metadata; album-level text → DB only** |
| 10 | `sync_metadata()` round-trip | (b) file wins — **narrowed by C3: album-sourced fields excluded from the mapping** |
| 11 | Per-photo storage | ~~(a) Reuse `images.comment`~~ → **superseded by C1: new typed columns** |
| 12 | Who may set album text | ~~(b) Any user with write access~~ → **superseded by C5: admin-only first slice** |
| 13 | Partial failure | **(a) Continue on error, report per-photo failures** |
| 14 | EXIF library gap | **(a) Run the survey now** — in progress, results appended separately |
| 15 | Standards to write | **(a) + (b)** — XMP `dc:description` + IPTC `2:120`, plus `photoshop:Headline` and `tiff:ImageDescription` |
| — | New requirement | **Audit trail / history for all texts** |

### Q1 restated — this is structured provenance, not a freetext field

> This Piwigo instance holds **scans of real photos**, obtained from people in physical albums.
> It shall hold meta information: **what physical album, which person owns it, when was it
> scanned**, etc.

This materially changes the feature's shape from what the research question described. Consequences:

- The unit is **several typed fields**, not one blob: an album/collection identifier, a person
  (owner/provider), a date (scanned-on), and an open-ended "etc.".
- "Which person owns it" and "when was it scanned" are **album-level facts** in the common case —
  a whole physical album comes from one person and is scanned in one session. That is precisely
  why album-level entry is wanted, and it is a stronger justification for propagation than the
  labour-saving reading (Q1a) originally assumed.
- `date_scanned` is a **date**, not text. Core already distinguishes `date_creation` (datetime,
  bulk-settable at `admin/batch_manager_global.php:291-317`) from `date_available`. A scan date is
  a third distinct date and has no home today.
- "Which person owns it" is a **person reference**. The uncommitted backlog already carries
  "build a person tagging system" (`docs/backlog.md`) — these two features overlap. Whether the
  owner is free text or a reference to that future system is undecided.
- ⚠ **This collides with Q11a.** See conflicts below.

### Q7 and Q8 — deferred to a user prompt

Both were answered "ask the user" rather than with a fixed rule. That is a decision to build a
confirmation step, and it has research consequences:

- **Q7 (move/dissociate)**: the move paths are `move_images_to_categories()`
  (`admin/include/functions.php:2151-2182`) and `dissociate_images_from_category()` (`:2112-2141`),
  reached from the Batch Manager (`admin/batch_manager_global.php:199`, `:229`) **and** from
  `pwg.images.setCategory` (`include/ws_functions/pwg.images.php:3031-3039`). A prompt is a UI
  affordance; the WS method has no UI. So the API path needs an explicit parameter, or it needs a
  documented default when unattended.
- **Q8 (album deletion)**: `delete_categories()` (`admin/include/functions.php:53-151`) already
  takes a `$photo_deletion_mode` argument and the admin UI already prompts for it — so there is an
  existing prompt to extend rather than a new one to invent. The cleanup hook
  `trigger_notify('delete_categories', $ids)` fires at `:149`, after the rows are gone.

### Q12 — ⚠ "write access to the album" does not exist in Piwigo

Verified directly: `piwigo_user_access` is `(user_id, cat_id)` and `piwigo_group_access` is
`(group_id, cat_id)` — **no verb column**. Album permissions grant *visibility only*;
`calculate_permissions()` (`include/functions_user.inc.php:644-698`) uses them solely to compute
`forbidden_categories`. There is no read/write distinction anywhere in core.

The only precedent for a non-admin edit right is `can_manage_comment($action, $comment_author_id)`
(`include/functions_user.inc.php:1605-1639`) — and it is **ownership-based, not album-based**:
`is_admin()` short-circuits true (`:1619-1622`), otherwise the user must be the comment's author
and `$conf['user_can_edit_comment']` must be on (`:1624-1629`).

So Q12b requires **inventing a per-album write-permission model** — a new column or table, plus
enforcement in the new WS method. `pwg.categories.setInfo` is registered `admin_only` + `post_only`
(`ws.php:881-905`), so nothing can be inherited from it. Scope this deliberately or fall back to
admin-only for a first slice.

### Conflicts to resolve in the plan

> **All four were resolved on 2026-08-29 — see "Conflict Resolutions Recorded" below.** The
> analysis is kept because it records *why* each resolution was needed.

**⚠ C1 — Q1 (several typed fields) vs Q11a (reuse `images.comment`).**
`images.comment` is one `text` column, already used for the photo's description and already
rendered on the picture page (`picture.php:826-836` → `picture.tpl:135-136`). Physical album +
owner + scan date cannot go into it without inventing an encoding inside a user-visible field.
The answer also explicitly anticipates "more per-image fields later", which a single reused column
does not accommodate. Q11b (new columns via the typetags `SHOW COLUMNS` + `ALTER TABLE` idiom,
`plugins/typetags/maintain.class.php:29-33`) or Q11c (side table) matches the stated need; Q11a
does not. **This is the single most consequential conflict** — it determines the schema.

**⚠ C2 — Q4b (propagate on album save) vs Q9 (photo text → file) vs measured write costs.**
If album save propagates to N photos, and photo-level values are written into files, then one
album save triggers N file rewrites. Research established: no queue, no cron, no server-side
chunking (§M); JPEG and HEIC writes are **lossy re-encodes** through Imagick (§C). A synchronous
album save over a few hundred photos is unbounded work in one request, and it silently degrades
image quality on every save for JPEG/HEIC.
Q9's wording ("on album level it's written to the DB") may mean propagation touches the DB only,
with file write-back happening on a separate trigger. If so, C2 dissolves — but that is not what
Q4b says on its face. **Needs one sentence of clarification.**

**⚠ C3 — Q10b (file wins on sync) vs Q3a/Q6b (DB copy-down overwrites).**
These two rules point in opposite directions and produce a revert loop:
1. Admin edits album text → Q6b overwrites every photo's DB value.
2. Files still hold the *previous* text (they are only rewritten on the photo-level path).
3. `sync_metadata()` runs — it fires on **every upload** (`admin/include/functions_upload.inc.php:403`),
   not just on demand — and per Q10b the file wins.
4. The photos' DB values revert to the old text.
`MASS_UPDATES_SKIP_EMPTY` (`admin/include/functions_metadata.php:320-339`) only protects against
*empty* parsed values overwriting populated columns; a non-empty stale value overwrites freely.
Ordering between the two writers is undefined. **Needs a tiebreak rule** — `date_metadata_update`
(Q10c) is the only field core already keeps for this purpose.

**⚠ C4 — Q5b requires a core patch.**
"Photos added later inherit the album text" needs a hook on the association path.
`associate_images_to_categories()` (`admin/include/functions.php:2024-2102`) fires **no trigger**,
and every insert path funnels through it (upload, Batch Manager associate/move, `pwg.images.setCategory`,
FS sync). This is a fork, so patching core is available — but it is a core change, not a plugin
change, and it is the only one on this list.

Note also that Q5b (late arrivals inherit) is in tension with Q3a (copy-down): copy-down is a
point-in-time write by definition. Making late arrivals inherit turns it into a standing rule that
must be enforced at every join — which is closer to resolve-at-read (Q3b) in effect, implemented
by writing at a different moment.

### New requirement — audit trail / history for all texts

> Foresee an audit trail / history for all texts.

Recorded as a first-class requirement. What core already provides, and what it does not, is
documented in the "Audit trail infrastructure" findings appended below.

---

## Conflict Resolutions Recorded 2026-08-29

Answers to the four conflicts (⚠ C1–C4) plus the five follow-on questions. With these, **no
conflict remains open** — the decisions below are mutually consistent. Two answers introduced
distinctions that were not among the offered options and are stated precisely here.

| # | Conflict | Resolution |
|---|---|---|
| C1 | Where per-photo provenance lives | **(a) New typed columns on `piwigo_images`**, idempotent `SHOW COLUMNS` + `ALTER TABLE` |
| C2 | Does album save write files | **(a) DB only**; file write-back is a separate explicit action |
| C3 | Which writer wins on sync | **Album wins for album-sourced info** — field-scoped, not timestamp-based (see below) |
| C4 | Late-joining photos | **(a) Patch core** — add a trigger to `associate_images_to_categories()` |
| C5 | Who may set album text | **(a) Admin-only for the first slice**; write-permission model deferred |
| C6 | Audit trail storage | **(a) Plugin-owned history table** with `text` columns |
| C7 | exiftool in the container | **(a) Yes** — `webimage_extra_packages` in `.ddev/config.yaml` |
| C8 | Shell-out safety | **(c) `-@ argfile`** — values written to a temp file, never a command line |
| C9 | Owner vs person-tagging | **Different entities** — no overlap (see below) |

### C1 — the schema takes typed columns

Resolved in favour of one column per fact on `piwigo_images`, added via the typetags idiom
(`plugins/typetags/maintain.class.php:29-33`: `SHOW COLUMNS` guard, then `ALTER TABLE … ADD`, with
`uninstall()` dropping them at `:51-57`). Consequences already established by the research:

- `date_scanned` becomes a real `DATE`, distinct from `date_creation` and `date_available`
  (`install/piwigo_structure-mysql.sql:220-221`). It is **not** on
  `get_sync_metadata_attributes()` (`admin/include/functions_metadata.php:124-150`), so
  `sync_metadata()` will not touch it — which is consistent with C3.
- `images.comment` is left alone, so the existing Description rendering
  (`picture.php:826-836` → `picture.tpl:135-136`) is unaffected.
- ⚠ New columns are **not** reachable through `pwg.images.setInfo`: its `$info_columns` allow-list is
  hard-coded to `array('name','author','comment','level','date_creation')`
  (`include/ws_functions/pwg.images.php:2596-2602`) with no hook. The new WS method must own these
  writes, exactly as the album side already requires.
- The Batch Manager's per-field bulk actions (`author` `:243-266`, `title` `:268-289`,
  `date_creation` `:291-317`) are each hard-coded to one column; new columns get no bulk UI for free.

### C3 — precedence is scoped by field origin, not by timestamp

The answer given was **"album wins for album infos"**, which is neither the timestamp tiebreak (a)
nor a blanket DB-wins (b). Stated precisely:

- **Album-sourced provenance fields** (physical album, owner, scan date): the **album/DB is
  authoritative**. The file never overwrites them. Mechanically this means keeping them **out of
  `$conf['use_iptc_mapping']`** (`include/config_default.inc.php:368-374`) and out of
  `$conf['use_exif_mapping']` (`:404-406`), so `get_sync_metadata()` never produces a value for
  them and `sync_metadata()` (`admin/include/functions_metadata.php:269-342`) cannot write them.
- **Photo-level fields** keep the Q10b answer (file wins on sync) insofar as they are mapped.

This **dissolves the revert loop** described in ⚠ C3: the loop required the album-sourced value to
be readable back out of the file, and under this rule it is not. The write direction for provenance
is one-way, DB → file, which is also the direction the original research found core already assumes.

⚠ One residual to settle in the plan: the fields are still *written into* the file (Q9/Q15), so a
third-party tool editing the file can make file and DB disagree with no mechanism to detect it.
`date_metadata_update` (`install/piwigo_structure-mysql.sql:229`, set by `sync_metadata()` at
`:339`) is the only field core keeps that could support a later reconciliation check.

### C4 — the core patch, and what it must cover

Adding `trigger_notify` to `associate_images_to_categories()`
(`admin/include/functions.php:2024-2102`) is the only core change on the list. Research established
this single function is the funnel for **every** virtual-link insert:

| Entry point | Reaches it via |
|---|---|
| Upload (new + duplicate) | `admin/include/functions_upload.inc.php:458` |
| WS `pwg.images.add` / `addFile` / `addSimple` | `pwg.images.php:1364, 1464, 1611, 1872, 2108` |
| Batch Manager associate | `admin/batch_manager_global.php:171-174` |
| Batch Manager move | `:199` → `move_images_to_categories()` (`functions.php:2151-2182`) |
| WS `pwg.images.setCategory` | `pwg.images.php:3031` (associate) / `:3039` (move) |

⚠ The one path it does **not** cover is filesystem sync, which inserts storage-album links directly
at `admin/site_update.php:551-555` without calling the helper. `admin/site_update.php` fires no
triggers at all (established in the original research), so FS-synced photos would not inherit.
Not a concern for this install today — all 76 photos were added via upload/API — but it is a real
hole in the rule.

The hook fires *after* the `mass_inserts()` at `:2094-2098`, so a handler sees committed rows.

### C6 — the history table, and what core cannot supply

A plugin-owned table with `text` columns for `old_value` / `new_value`. Justified by the measured
finding that `piwigo_activity.details` is `varchar(255)` with **nothing truncating or validating
before insert** (`include/functions.inc.php:648`) — a serialized array exceeding 255 bytes is cut
mid-string and becomes unreadable to `unserialize()`. Provenance text has no such bound.

What can be reused rather than rebuilt: the read path. `pwg.activity.getList`
(`include/ws_functions/pwg.php:453`) already filters by `object`, `object_id`, `date_min`, `date_max`,
and `admin/user_activity.php:176-209` already validates a `?photo=N` / `?album=N` filter against the
respective tables. A parallel history view can follow that shape.

⚠ Two things the research flagged that the plan must size:
- **No retention.** `piwigo_activity` has no purge anywhere in core; a new history table inherits
  that gap by default. Under C2+Q6b an album re-apply over N photos writes N history rows per save.
- **`pwg_activity()` writes one row per id** (`functions.inc.php:643-673`), so bulk operations
  already multiply rows; a value-level trail multiplies them again by field count.

### C8 — argfile, not command line

`-@ argfile` keeps user-supplied text off the command line entirely: exiftool reads arguments from a
file, one per line. Consequences to carry into the plan:

- Temp-file lifecycle becomes part of the operation — creation, permissions, and deletion —
  including **cleanup on failure**, which interacts with the C13/Q13 decision to continue on error.
- Piwigo has no temp-file convention for this; `_data/` is the writable working area
  (`_data/templates_c/`, `_data/combined/`, `_data/i/` all live there).
- Encoding matters: the argfile must be UTF-8 and exiftool told so (`-charset`), since the DB is
  `utf8mb3` and `get_iptc_data()` already does windows-1252/iso-8859-1 conversion on the read side
  (`include/functions_metadata.inc.php:99-113`).

### C9 — owner and tagged person are different entities

> "owner and person tagged are different categories/entities"

Recorded as a modelling fact, and it removes the overlap flagged earlier:

- **Owner** = who possesses / provided the physical photo or album. A provenance fact about the
  artefact.
- **Tagged person** = who is depicted in the image. A content fact, and the subject of the separate
  `docs/backlog.md` item "build a person tagging system".

They are not the same field and must not share storage. Concretely: `owner` is a provenance column
under C1 and does **not** route through `piwigo_tags` / `piwigo_image_tag`, which is where a person
tagging system would live. The earlier open question "is owner free text or a reference to the
person system" is therefore **closed — neither; it is its own entity**.

⚠ Left open: whether `owner` should itself become a reference to some future *people* table (so
"all photos provided by X" is queryable) is a separate question from person-tagging, and unanswered.
Free text makes that query impossible without a later migration.

### Net effect on the acceptance scenarios

- "Correcting the album text" no longer needs a sync-survival assertion — C3 removes the revert
  loop for album-sourced fields.
- "A photo joining the album afterwards" becomes implementable, via the C4 core patch, **except for
  filesystem-synced photos**.
- A new scenario is implied by C2: applying text to photos and writing it into files are now two
  distinct operations, and the second one needs its own coverage.

---

## Follow-on Resolutions Recorded 2026-08-29 (second round)

| # | Question | Resolution |
|---|---|---|
| 1 | FS-synced photos can't inherit | **(b) Patch `admin/site_update.php` too** — a second core change |
| 2 | Batch Manager reach for new columns | **(a) Not needed** — album-level entry *is* the bulk path |
| 3 | `owner` as a people reference | **(a) Free text now**; table added to `docs/backlog.md` as low priority |
| 4 | File-vs-DB divergence detection | **(a) None in v1**; detection added to `docs/backlog.md` as low priority |
| 5 | Argfile location and cleanup | **(a) `_data/` subdirectory, deleted in a `finally`** |
| 6 | History table retention | **(a) No purge in v1**; growth path recorded |
| 7 | exiftool `_original` backups | **(b) Keep as a safety net**, with cleanup |
| 8 | Fixtures for the new cases | **(a) Extend `FixtureBuilder`** to seed albums, photos, and non-PNG files |
| 9 | Public display of album provenance | **(b) New `#Provenance` row** via prefilter, typetags precedent |
| 10 | IPTC 2000-byte cap | **(a) Full text to XMP/EXIF, truncate for IPTC, log it** |

### 1b — the second core patch, and why FS sync is the harder one

`admin/site_update.php` inserts storage-album links directly (`:551-555`, `$insert_links[]` with
`'image_id'` and `'category_id'`) and — established in the original research — **fires no triggers
anywhere in the file**. So unlike `associate_images_to_categories()`, there is no existing
`trigger_*` call to sit beside; the patch introduces the first one into that file.

⚠ Ordering constraint: the links are accumulated into `$insert_links` and bulk-inserted later, not
written per-file. A handler must therefore fire after the `mass_inserts()`, with the full id set,
rather than inside the scan loop — otherwise it observes rows that do not exist yet.

Note this path is currently **unexercised on this install**: all 76 photos have
`storage_category_id` NULL, i.e. all came via upload/API (`include/functions.inc.php:2584` derives
exactly this distinction). The patch will therefore ship without production evidence unless a
fixture drives it.

### 7b — `_original` backups are safe from FS sync, but not from disk growth

exiftool's default backup names the file `<original name>_original` — e.g. `foo.png_original`.
Checked against `$conf['file_ext']` (`include/config_default.inc.php:54-57`):

```php
$conf['picture_ext'] = array('jpg','jpeg','png','gif','webp');
$conf['file_ext'] = array_merge($conf['picture_ext'],
  array('tiff','tif','mpg','zip','avi','mp3','ogg','pdf','svg','heic'));
```

The resulting extension is `png_original`, which is **not** in either list. So a `_original` sidecar
is invisible to filesystem sync and cannot be mistaken for a new photo — the risk that made
suppression attractive does not materialise. This is what makes 7b viable.

⚠ What remains real: **disk usage roughly doubles** for every file written, and the backups
accumulate across repeated writes unless cleaned. Under Q6b (album edit re-applies to all photos) a
re-save rewrites every member file, so the growth is per-save, not one-off. The cleanup story is
therefore load-bearing, not optional — and it must not delete a backup before the new write is
confirmed good, or the safety net is gone precisely when it is needed.

### 5a — argfile placement

`_data/` is the established writable working area (`_data/templates_c/`, `_data/combined/`,
`_data/i/` all live there, and `.gitignore` excludes `_data` wholesale). A per-operation
subdirectory keeps concurrent operations from colliding.

⚠ Interaction with Q13 (continue on error): a `finally` that deletes the whole operation directory
runs even on the failure path, which is correct for cleanup but conflicts with the project rule that
cleanup "must not run in a way that erases the evidence of a failure"
(`.claude/rules/test-design.md`). The per-photo failure detail must therefore be captured into the
history table or the error report **before** the directory is removed.

Encoding: the argfile must be UTF-8 with `-charset` set explicitly. The DB is `utf8mb3`, and the
existing read path already converts from windows-1252/iso-8859-1
(`include/functions_metadata.inc.php:99-113`), so encoding is a known-live concern in this codebase,
not a theoretical one.

### 9b — the public `#Provenance` row

The typetags precedent applies directly. `themes/default/template/picture.tpl:172-301` is a uniform
sequence of rows inside `<dl id="standard" class="imageInfoTable">`, each shaped:

```smarty
{if $display_info.author and isset($INFO_AUTHOR)}
<div id="Author" class="imageInfo"><dt>{'Author'|@translate}</dt><dd>{$INFO_AUTHOR}</dd></div>
{/if}
```

`themes/modus` does **not** override `picture.tpl`, so one injection covers both themes. Injection
happens on `loc_end_picture` (`picture.php:1019` — after all assigns, before `pparse`), via
`set_prefilter('picture', …)`, matching `plugins/typetags/include/events_public.inc.php:131-190`.

Two details carried from the earlier research:

- ⚠ A new info row wanting a **visibility toggle** needs a key in `$conf['picture_informations']`,
  a serialized bool map seeded at `install/config.sql:52-57` and edited at
  `admin/configuration.php:280-283,527`. Without one, the row renders unconditionally.
- ⚠ **Clear `_data/templates_c/` after editing the prefilter.** `Template::set_prefilter()` hashes
  only the callback *name* into Smarty's `compile_id` (`include/template.class.php:1060-1070`), so
  editing the body leaves the old compiled template in place — the page keeps showing the previous
  injection with no error.

Note this is *album-sourced* provenance shown on a *photo* page. Under C1 the values live on
`piwigo_images`, and `picture.php:461-465` loads the whole row with `SELECT *`, so they are already
reachable as `{$current.<column>}` with no extra query.

### 10a — truncation is a logged event, not a silent one

MWG caps IPTC-IIM `2:120` at 2000 bytes; XMP `dc:description` and EXIF `ImageDescription` have no
comparable limit. Full text goes to XMP and EXIF, IPTC gets a truncated copy, and the truncation is
recorded.

⚠ This makes the three standards **deliberately disagree** for long values — which is a direct,
knowing exception to the MWG guidance that the three fields "are mapped together". The exception is
forced by the standard's own limit, not a choice, but any later reconciliation logic must expect it
rather than treat the mismatch as corruption.

The history table (C6) is the natural place for the truncation record, since it already stores
old/new values as `text`.

### 8a — what `FixtureBuilder` can and cannot do today

Read directly (`plugins/typetags/tests/Support/FixtureBuilder.php`, 346 lines). It manipulates
**tags on pre-existing rows** and nothing else:

| Capability | Present? |
|---|---|
| Assign / clear tags on an image (`assign()` `:233`, `clearTags()` `:228`) | yes |
| Force + assert tag state (`assertState()` `:243`) | yes |
| Snapshot / restore (`exportState()` `:284`, `importState()` `:293`, `restore()` `:320`) | yes |
| **Create an album** | **no** |
| **Create an image row** | **no** |
| **Place a file on disk** | **no** |
| **Link an image to a second album** | **no** |

⚠ `anyImageId()` (`:96-99`) is `SELECT id FROM piwigo_images ORDER BY id LIMIT 1` — precisely the
"`SELECT … LIMIT 1` off whatever the database happens to hold" pattern that
`.claude/rules/test-design.md` names as forbidden, since a fixture must **force** its precondition
and assert it took effect. It is defensible for the existing tag tests (any image will do), but it
cannot support provenance tests, where the album a photo belongs to *is* the thing under test.

So 8a is a genuine extension, not a tweak. What it must gain:

- create an album, and a photo linked to it (forcing `piwigo_image_category`)
- create a photo in **two** albums — the case with no data on this install at all
- place real files of each format under test; the install is **100% PNG**, so JPEG, TIFF and HEIC
  have no on-disk sample, and the IPTC/EXIF capability matrix (§C) differs per format
- assert the forced precondition before the test body, per the project rule

⚠ Anti-vacuity, from the same rules file: any assertion counting bytes or occurrences in a written
file needs a lower-bound guard first — a metadata scan that reads nothing must fail loudly rather
than pass. Recall the measured PNG trap: XMP lands in a **compressed `zTXt` chunk**, so a raw
byte-grep for `dc:description` returns a **false negative** on PNG even when the write succeeded.
Verification must go through Imagick profiles or exiftool, never a substring search on the file.

---

## Audit Trail Infrastructure (findings for the new requirement)

### The decisive fact: core stores no old values, anywhere

**Piwigo has no revision, version, or field-diff mechanism.** Checked exhaustively: 37 `CREATE TABLE`
statements in `install/piwigo_structure-mysql.sql`, none of them a revision or audit-value table;
greps for `revision` / `old_value` / `previous_value` / `audit_trail` return nothing.
`piwigo_upgrade` (`:387-392`) tracks applied *schema* migrations (`id`, `applied`, `description`),
not record content.

So "an audit trail for all texts" is **new infrastructure**, not a configuration of something
existing. What exists is two logs, neither of which records what a value used to be.

### `piwigo_activity` — who did what, not what changed

Schema `install/piwigo_structure-mysql.sql:12-24`, verified against the live DB:

| Column | Type |
|---|---|
| `activity_id` | `int unsigned AUTO_INCREMENT` PK |
| `object` | `varchar(255)` — `user` / `photo` / `album` / `tag` / `group` / `system` |
| `object_id` | `int unsigned` |
| `action` | `varchar(255)` — `add` / `edit` / `delete` / `move` / `login` / `config` / … |
| `performed_by` | `mediumint unsigned` |
| `session_idx` | `varchar(255)` |
| `ip_address` | `varchar(50)` |
| `occured_on` | `timestamp DEFAULT CURRENT_TIMESTAMP` |
| **`details`** | **`varchar(255)`** — a `serialize()`d PHP array |
| `user_agent` | `varchar(255)` |

`pwg_activity($object, $object_id, $action, $details=array())` — `include/functions.inc.php:556-676`.
Accepts a single id or an array, inserting one row per id via `mass_inserts(ACTIVITY_TABLE, …)`
(`:675`). `$details` is `serialize()`d then escaped at `:648`.

Three properties that bear directly on the requirement:

1. **`details` is `varchar(255)` and nothing truncates or validates before insert.** A serialized
   array longer than 255 bytes is cut by MySQL mid-string, producing a value `unserialize()` cannot
   read. Storing before/after text in this column is therefore unsafe for anything but very short
   strings — and the fields in question (physical album, owner, free text) have no such bound.
   Measured on this install: 2295 rows, longest `details` **108 bytes**, so the ceiling has never
   been approached in practice and the failure mode is unexercised.
2. **Photo edits record no detail at all.** `admin/picture_modify.php:160`,
   `include/ws_functions/pwg.images.php:1106` and `:2662` all call `pwg_activity('photo', …, 'edit')`
   with **no `$details` argument** — the row says a photo was edited and nothing more.
   `ws_categories_setInfo` is the sole exception, recording changed field *names*:
   `array('fields' => implode(',', array_keys($update)))` (`pwg.categories.php:981`).
3. **No retention policy.** No purge, no autopurge config key, no maintenance action —
   greps for `DELETE FROM … ACTIVITY_TABLE` and `activity_autopurge` return nothing. The table grows
   unbounded (live: `AUTO_INCREMENT=2296` on a dev instance whose traffic is 1124 logins + 76 photo adds).

Coverage is otherwise good: album `add`/`edit`/`delete`/`move` are all logged
(`admin/include/functions.php:151`, `:1419`, `:1587`; `pwg.categories.php:981`, `:1032`, `:1079`, `:1125`;
`admin/cat_options.php:73`, `:110`), and photo `add`/`edit`/`delete` likewise
(`admin/batch_manager_global.php:265`, `:291`, `:321`, `:342`; `admin/include/functions.php:349`;
`admin/include/functions_upload.inc.php:393`, `:554`).

### The activity UI already supports per-object filtering

`admin/user_activity.php` — `check_status(ACCESS_ADMINISTRATOR)` (`:20`). It builds filter metadata
server-side (users with activity `:105-140`; date range `:150-174`; the active object filter from
`$_GET['photo']` / `['album']` / `['group']`, validated against the respective tables `:176-209`;
action counts `:217-244`) and fetches the paginated listing client-side from
`ws.php?format=json&method=pwg.activity.getList` (`admin/themes/default/js/user_activity.js:5-13`).

Handler `ws_getActivityList()` — `include/ws_functions/pwg.php:453`. Supports `object`, `id`
(→ `AND object_id = …`), `date_min`, `date_max`, and `$conf['activity_display_connections']`
(default `'all'`, `include/config_default.inc.php:819`). It groups consecutive same-session rows
into one line with a counter (`pwg.php:566-573`).

**So a per-photo and per-album history view already exists and is reachable** — the gap is entirely
in what gets *written*, not in what can be read back. `admin/maintenance_sys.php:36+` renders the
`object='system'` slice, webmaster-only.

Related but separate: `admin/cat_modify.php:270-285` reads a single `ACTIVITY_TABLE` row
(`object='album' AND action='add'`) purely to show the album's creation date — not a feed.

### `piwigo_history` is page views, not data changes

`install/piwigo_structure-mysql.sql:141-156` — columns `date`, `time`, `user_id`, `IP`, `section`
(enum: categories/tags/search/list/favorites/…), `category_id`, `search_id`, `tag_ids`, `image_id`,
`image_type`, `format_id`, `auth_key_id`. Written by `pwg_log()`
(`include/functions.inc.php:424-542`), gated by `do_log()` (`:398-415`) and `$conf['log']` /
`$conf['history_admin']` / `$conf['history_guest']`.

It records **what a visitor looked at**. It has no field, old value, or new value. Unlike
`piwigo_activity`, it *does* have retention: `$conf['history_autopurge_every'] = 1021`
(`include/config_default.inc.php:652`) drives `history_autopurge()`
(`admin/include/functions_history.inc.php:384+`), and `history_summarize(50000)`
(`functions.inc.php:531-534`) rolls detail rows into `piwigo_history_summary`
(`install/piwigo_structure-mysql.sql:163+`). None of that touches `ACTIVITY_TABLE`.

### What the requirement implies, given the above

Facts, not recommendations:

- A trail that answers *"what did this text used to say"* needs storage core does not have.
  `piwigo_activity.details` at `varchar(255)` cannot safely hold before/after values of unbounded text.
- The read side is largely built: `pwg.activity.getList` already filters by object + object_id +
  date range, and `admin/user_activity.php` already renders it.
- Every write path that would need to log is already calling `pwg_activity()` — but the photo-edit
  call sites pass no `$details`, so they log the fact of a change and discard its content.
- With Q6b (album edit overwrites every photo) and Q10b (file wins on sync), a single album save can
  change N photo values, and a later sync can change them back. **Without a value-level trail, neither
  event is reconstructable** — which is likely why the requirement was raised, and it interacts
  directly with conflict ⚠ C3.
- ⚠ Retention: an unbounded, never-purged table plus one row per photo per bulk operation is a growth
  path worth sizing. A 200-photo album re-apply writes 200 rows per save under the current
  one-row-per-id behaviour of `pwg_activity()` (`functions.inc.php:643-673`).

---

## Metadata-Writing Library Survey (closes Open Question 1)

Web research, answering the gap left by the failed sub-agent in the first pass. Primary sources
cited. **Version numbers and release dates below should be re-verified at implementation time** —
the survey flagged several as unconfirmed, and they are marked as such rather than smoothed over.

### The headline: only exiftool can write EXIF into PNG

This install is **100% PNG** (76/76 images, measured). Combined with the earlier measurement that
**Imagick cannot write EXIF at all**, that makes the tool choice nearly forced:

| Tool | Licence | Writes EXIF? | Formats | Status |
|---|---|---|---|---|
| **exiftool** (binary) | Artistic / GPL | **Yes — including PNG** | JPEG, PNG, TIFF, HEIC, + hundreds | v13.59, actively maintained |
| lsolesen/pel | GPL-2.0 | Yes, **JPEG/TIFF only** | JPEG, TIFF | **Repo archived 2023-06-23; Packagist marks it abandoned** |
| FileEye/pel (fork) | GPL | Yes, **JPEG/TIFF only** | JPEG, TIFF | active fork, release cadence unconfirmed |
| exiftool/wrapper | MIT | via the binary | as exiftool | released 2025-09-02; only ~2.9k installs |
| alchemy-fr/PHPExiftool | MIT | via the binary | as exiftool | README self-describes as **"not suitable for production"** |
| dchesterton/image | MIT | claims XMP/IPTC; **EXIF-in-PNG explicitly unsupported** | multiple | **pre-alpha, "much of it… simply not working"** (own README) |
| PNGMetadata | GPL-3.0 | **No — read only** | PNG | — |
| mosen/phpxmp | — | read/extract only | — | — |

**No pure-PHP library writes EXIF into PNG.** PEL — the only pure-PHP option that writes real EXIF
at all — covers JPEG and TIFF only, and its canonical repository is archived; using it means
depending on the `FileEye/pel` community fork.

Sources: https://github.com/lsolesen/pel (archived), https://packagist.org/packages/lsolesen/pel
(abandoned), https://github.com/FileEye/pel, https://exiftool.org/,
https://github.com/joserick/PNGMetadata, https://github.com/dchesterton/image,
https://github.com/alchemy-fr/PHPExiftool.

### exiftool specifics

- **Licence**: dual Artistic / GPL ("same terms as Perl itself") — confirmed via the project `.spec`
  and the Homebrew formula (`Artistic-1.0-Perl OR GPL-1.0-or-later`).
  https://github.com/exiftool/exiftool/issues/321 · https://formulae.brew.sh/formula/exiftool
- **Not installed in this container** (measured in the first pass; `perl` *is* present at
  `/usr/bin/perl`). Debian/Ubuntu package name is **`libimage-exiftool-perl`** — `apt install exiftool`
  resolves to it. https://packages.debian.org/libimage-exiftool-perl
- **Writes metadata in place without re-encoding pixel data.** This is its core design: it rewrites
  only the metadata segments/chunks. That directly removes the **lossy JPEG/HEIC re-encode** measured
  for the Imagick route in §C — the single worst property of the previously-available tooling.
  It writes a `_original` backup by default unless `-overwrite_original` is passed.
- **Multi-standard write in one invocation**, with group-qualified tags:
  ```
  exiftool -EXIF:ImageDescription="caption" \
           -IPTC:Caption-Abstract="caption" \
           -XMP-dc:Description="caption" file.png
  ```
  Unqualified tag names fan out by exiftool's internal group priority (EXIF → IPTC → XMP); explicit
  group prefixes are the reliable form when all three must be written.
  https://iptc.atlassian.net/wiki/spaces/PMD/pages/649330691
- ⚠ **Invoking it means shelling out from PHP** (`proc_open` / `shell_exec`, or a thin wrapper).
  Piwigo core shells out nowhere today. This introduces argument-escaping as a security boundary —
  user-supplied text reaching a command line — and a new runtime dependency the Docker image must carry.

### MWG guidance (closes Open Question 2)

*Guidelines For Handling Image Metadata*, Version 2.0, November 2010, Metadata Working Group
(Adobe, Apple, Canon, Microsoft, Nokia, Sony), section 5.2 "Description", p. 36.
https://s3.amazonaws.com/software.tagthatphoto.com/docs/mwg_guidance.pdf

Verbatim:

> "Information for the description property is available in the following properties: Exif
> ImageDescription (270, 0x010E); IPTC Caption (IIM 2:120, 0x0278); XMP (dc:description["x-default"])."
> "Exif ImageDescription, IPTC Caption, and XMP (dc:description) are mapped together."

So the three fields the research probed **are** the canonical mirror set, and `ImageDescription`
(not `UserComment`) is the EXIF member. Two constraints fall out:

- ⚠ **IPTC-IIM caps this field at 2000 bytes.** A longer value written to EXIF/XMP cannot be
  mirrored losslessly into IPTC `2:120`. This is the concrete answer to the field-length question
  raised in Q9 of the requirements section.
- `dc:description` is a **language-alternative structure**; MWG restricts cross-standard sync to the
  `"x-default"` value.

### Consequences for the recorded decisions

- **Q14a is answered: the survey is done, and it changes the feasible design.** Adding
  `libimage-exiftool-perl` to the DDEV image makes Q15 (a+b) fully achievable *and* removes the
  lossy-re-encode problem for JPEG/HEIC. Without it, EXIF is unreachable on PNG — i.e. unreachable
  for every photo currently in this install.
- **Q15's (b) half is partly unverified.** `photoshop:Headline` and `tiff:ImageDescription` were not
  probed in the container and are not part of the MWG description mapping — `Headline` is a distinct
  short-title field in the IPTC schema, not a caption mirror. Writing them is possible via exiftool;
  whether they *should* mirror the caption is a semantic choice, not a technical constraint.
- **A new Open Question**: is adding a system package to the container acceptable, given `.ddev/`
  carries only `config.yaml` in git (per CLAUDE.md) and a `webimage_extra_packages` entry would be a
  tracked change to the dev environment definition?

## Empirical Verification of exiftool (2026-08-29, measured in this container)

Closes the last material research gap: the design rests on exiftool, which had only ever been
researched from web sources. Everything below was **measured**, in the DDEV web container, reading
back **in a separate process** with Piwigo's own reader functions.

Setup: `sudo apt-get install -y libimage-exiftool-perl` → **exiftool 13.25**.
⚠ Note the web survey reported 13.59 (current upstream); Debian trixie ships **13.25**. The
version claim in the survey section above is upstream's, not what an `apt` install yields.
⚠ This install was made **inside the running container** and does **not survive `ddev restart`**.
Persisting it needs `webimage_extra_packages` in `.ddev/config.yaml` (decision C7a).

Test subject: a real gallery file, `upload/2026/04/19/20260419142031-496f8727.png`, 509×767
(390,403 px — deliberately above the 256,000 strip threshold), which carried **no metadata at all**
to begin with. Values written included non-ASCII (`Müller`) and a non-latin-1 character (`—`,
U+2014), via an **argfile** (`-@ args.txt`) per decision C8c.

### Result 1 — all five fields write, in one invocation

```
exiftool -@ args.txt out.png
```
with the argfile carrying `-EXIF:ImageDescription`, `-IPTC:Caption-Abstract`, `-XMP-dc:Description`,
`-XMP-photoshop:Headline`, `-XMP-tiff:ImageDescription`. All five read back correctly through
exiftool. **The argfile mechanism works**, including UTF-8 values — C8c is viable.

Resulting PNG chunk inventory: `IHDR(13) zTXt(134) iTXt(934) eXIf(78) IDAT(4096)`.
exiftool writes a genuine **`eXIf` chunk** (PNG 1.5+), which is what no pure-PHP library could do.

⚠ One warning on PNG: `Warning: [minor] Creating non-standard IPTC in PNG`. The write succeeds
(exit 0) and Imagick sees the profile, but IPTC-in-PNG is outside the PNG specification.

### Result 2 — exiftool never re-encodes pixels

| Format | Pixel signature vs. pre-write |
|---|---|
| PNG | **IDENTICAL** |
| JPEG | **IDENTICAL** |
| TIFF | **IDENTICAL** |
| HEIC | **IDENTICAL** |

Confirmed via `Imagick::getImageSignature()` and RMSE 0 on PNG. This **eliminates the lossy
JPEG/HEIC re-encode** measured for the Imagick route in §C — the single worst property of the
previously available tooling. It is the strongest argument for the exiftool decision, and it is
now measured rather than claimed.

### Result 3 — ⚠ PHP cannot read back EXIF from PNG or HEIC

The critical finding. exiftool writes EXIF successfully into all four formats, but **Piwigo's own
reader cannot see it** in two of them:

| Format | `exif_read_data()` | `getimagesize()` APP13 → `iptcparse()` | Imagick profiles |
|---|---|---|---|
| **PNG** | **FALSE** | absent | `exif,iptc,xmp` |
| **JPEG** | ✅ `ImageDescription` | ✅ `2#120` | `8bim,exif,iptc,xmp` |
| **TIFF** | ✅ `ImageDescription` | absent | `iptc,xmp` |
| **HEIC** | **FALSE** | absent | `exif,xmp` |

So on PNG — **100% of this install** — the EXIF is genuinely in the file (Imagick lists the profile,
exiftool reads it), but `exif_read_data()` returns `false`. PHP's exif extension does not parse the
PNG `eXIf` chunk.

Consequence: writing EXIF is now possible, but **round-tripping it through Piwigo's own reader is
not, on PNG or HEIC**. This does not break the design — decision C3 makes the DB authoritative for
album-sourced provenance and keeps these fields out of the sync mapping, so Piwigo never needs to
read them back. It does mean any *verification* of a PNG write must go through exiftool or Imagick,
never `exif_read_data()`.

### Result 4 — the IPTC encoding trap, and why it turns out fine

exiftool's **default** IPTC output is latin-1, and it does not write the `1:90 CodedCharacterSet`
marker. Measured on the default write: `Müller` stored as `0x4d 0xfc 0x6c...` and `—` as `0x97` —
both valid **windows-1252**.

With characters outside windows-1252, the default write **loses them irreversibly**:

```
$ exiftool ... -IPTC:Caption-Abstract=Fotoalbum Łódź Ω 日本 Müller
Warning: Some character(s) could not be encoded in Latin
→ IPTC reads back:  Fotoalbum ?ód? ? ?? Müller       (mangled)
→ XMP  reads back:  Fotoalbum Łódź Ω 日本 Müller      (intact)
→ EXIF reads back:  Fotoalbum Łódź Ω 日本 Müller      (intact)
```

Adding `-charset iptc=UTF8` preserves everything. The question was then whether Piwigo's reader
copes — and it does. `clean_iptc_value()` (`include/functions_metadata.inc.php:78-117`) does **not**
convert unconditionally: it calls `qualify_utf8()` (`include/functions.inc.php:150`) and branches
(`:93-98`). Measured, running Piwigo's own functions against both variants:

| File | `qualify_utf8()` | `clean_iptc_value()` output | Valid UTF-8 |
|---|---|---|---|
| latin-1 IPTC (exiftool default) | **-1** → windows-1252 branch | `Fotoalbum Oma Müller — gescannt 2026` | YES |
| UTF-8 IPTC (`-charset iptc=UTF8`) | **1** → utf-8 branch | `Fotoalbum Łódź Ω 日本 Müller` | YES |

**Both round-trip correctly.** So the encoding dilemma dissolves: use `-charset iptc=UTF8`, which
preserves every character in the file *and* is read correctly by Piwigo's auto-detecting reader.

⚠ Recorded correction: an earlier note in this document assumed `clean_iptc_value()` converts from
windows-1252 unconditionally. It does not — it detects. The auto-detection is what makes UTF-8 IPTC
safe here.

### Result 5 — derivative propagation, both sides of the threshold

Closes Open Question 5 from the original research. Mimicking `i.php:615-617`
(`$conf['derivatives_strip_metadata_threshold'] = 256000`, `include/config_default.inc.php:979`):

| Derivative | Pixels | vs. threshold | Profiles | `exif ImageDescription` | APP13 |
|---|---|---|---|---|---|
| 600×900 | 540,000 | **above** → no strip | `8bim,exif,iptc,xmp` | ✅ present | present |
| 400×600 | 240,000 | **below** → `stripImage()` | **(none)** | absent | absent |

So provenance **survives into derivatives above the threshold and is completely removed below it**.
Since the public gallery serves derivatives, a photo's provenance is visible in the file a visitor
downloads only for the larger sizes. Note this is Piwigo behaving as designed, not a defect.

⚠ Implementation detail: `i.php:617` calls `$image->strip()`, which is Piwigo's own wrapper
(`admin/include/image.class.php:504` → `stripImage()` on the Imagick backend). Raw Imagick has no
`strip()` method — a test mimicking this must call `stripImage()`.

### Result 6 — `_original` backups do not accumulate

Measured across repeated writes to the same file: exiftool **does not overwrite an existing
`_original`**. After a second and third write, `foo.jpg_original` still held the state from before
the first backed-up write, not the previous state.

⚠ Recorded correction: an earlier note in this document stated backups "accumulate across repeated
writes… the growth is per-save, not one-off." **That is wrong.** Disk grows by roughly one extra
copy per file, **once**, regardless of how many times the file is later rewritten. This makes
decision 7b substantially cheaper than assessed — and the backup is a true "restore to before we
ever touched it" snapshot rather than an undo of the last edit.

Also confirmed: `-overwrite_original_in_place` suppresses backup creation entirely, should it ever
be wanted per-operation.

Re-confirmed from the earlier analysis: the backup filename is `foo.png_original`, whose extension
`png_original` is absent from `$conf['file_ext']` (`include/config_default.inc.php:54-57`), so
filesystem sync cannot mistake it for a photo.

### What this changes

- **The exiftool decision (C7a) is validated.** It writes all five fields in one pass, into all four
  formats, with byte-identical pixels.
- **Q15 (a+b) is achievable**: `photoshop:Headline` and `tiff:ImageDescription` both write and read
  back cleanly via the XMP namespace.
- **`-charset iptc=UTF8` is required**, not optional — without it, non-latin-1 text is silently
  mangled in the IPTC copy.
- **PNG/HEIC EXIF is write-only from Piwigo's perspective.** Harmless under C3, but it constrains
  how tests verify a write.
- **7b is cheaper than assessed**; **derivative behaviour is now known** rather than assumed.

### Remaining unverified

- **Concurrency and locking on originals.** Two simultaneous writes to the same file, or a write
  racing derivative generation, were not tested. No locking convention exists in Piwigo for files
  under `upload/`. This is the one item from the earlier "still open" list that remains open.
- HEIC was exercised only through an Imagick-produced file, not a camera-produced one.

## Concurrency and Locking on Originals (2026-08-29, measured)

Closes the last open item. Measured in the DDEV web container against a real gallery PNG
(`upload/2026/04/19/20260419142031-496f8727.png`, 638,507 bytes).

### ⚠⚠ Finding 1 — concurrent exiftool writes DESTROY the original file

This is the most serious finding in this document. **Twelve concurrent `exiftool` writes to the
same file deleted the file outright in 5 of 6 runs.**

```
run 1: *** FILE LOST ***      run 4: *** FILE LOST ***
run 2: *** FILE LOST ***      run 5: *** FILE LOST ***
run 3: survived (value=w10)   run 6: *** FILE LOST ***
=== 12 concurrent writers, 6 runs: survived=1  destroyed=5 ===
```

Error breakdown from a single 12-way run:

```
  1  1 image files updated
  1  Error renaming temporary file to c.png
 10  Error: Temporary file already exists: c.png_exiftool_tmp
```

**Root cause: exiftool's temp filename is fixed, not randomized** — it is always
`<filename>_exiftool_tmp`. Concurrent processes collide on that one name; the losers abort, and the
interleaving of their cleanup with the winner's rename leaves **no file at the original path**.
Not a corrupted file, not a stale file — *no file*. The photo is gone from disk while the
`piwigo_images` row still points at it.

Contention threshold, measured:

| Concurrent writers | Outcome |
|---|---|
| 2 | file survives; **lost update** (one writer's value wins, others silently discarded) |
| 3 | file survives; lost update |
| 5 | file survives; lost update |
| 12 | **file destroyed in 5 of 6 runs** |

Even at low contention the behaviour is a silent lost update: every writer reports
`1 image files updated`, but only one value persists.

⚠ This is directly reachable by the feature as designed. Decision Q6b re-applies album text to every
photo, and Q13 continues on error. Two admins pressing "apply" on overlapping albums, or an apply
racing a per-photo save, produces exactly this contention on the shared files.

### Finding 2 — `flock` fully mitigates it

Same test, each write wrapped in an exclusive `flock` on a per-file lock:

```
=== with flock, 12 writers, 6 runs: survived=6  destroyed=0 ===
=== per-writer outcomes ===
     12  1 image files updated
```

**6 of 6 runs survived, zero errors, all twelve writes applied in sequence.** Serialization is
sufficient; no exiftool flag is needed and none exists for this.

PHP's `flock()` is the in-language equivalent. ⚠ Note the lock must be on a **separate lock file**,
not on the image itself — exiftool replaces the image file via rename, so a lock held on the old
inode would not exclude anything after the first write.

### Finding 3 — the write is atomic, which protects readers

Measured via inode identity across a write:

| Mode | Inode before → after | Mechanism |
|---|---|---|
| default (keeps `_original`) | 535791 → **535798**; backup takes 535791 | original *renamed* to `_original`, new file renamed into place — **atomic** |
| `-overwrite_original` | 535791 → **535797** | temp file + rename — **atomic** |
| `-overwrite_original_in_place` | 535797 → **535797** | truncate and rewrite in place — **not atomic** |

The mode chosen by decision 7b (default, keeping `_original`) is the atomic one, and the backup is
literally the original inode renamed aside.

Reader-during-write test — 200 `Imagick` opens against a file being rewritten 25 times:

| Writer mode | Reads OK | Reads failed | File missing at open |
|---|---|---|---|
| default / atomic rename | 200 | 0 | 0 |
| `-overwrite_original_in_place` | 200 | 0 | — |

⚠ **Honest limitation**: the in-place mode also showed no reader failures, but that is my test
failing to hit a narrow window, **not evidence that it is safe**. A metadata-only rewrite of a
638 KB file is fast, so the truncated-file window is very short. The atomic rename path is
*structurally* safe — a reader holding the path sees either the complete old file or the complete
new one, never a partial one. The in-place path has no such guarantee and merely a small window.
Only the first row of that table is a safety claim; the second is an unhit race.

Practical consequence: **derivative generation (`i.php`) racing a metadata write is safe**, provided
writes use the atomic mode. That closes the reader half of the concern.

### What this means for the plan

- **Per-file locking is mandatory, not advisory.** Without it the feature can delete photos from
  disk under ordinary multi-user operation. This is the one finding in this document that describes
  data loss rather than inconvenience.
- The lock must be **per image file**, on a **separate lock file**, held across the whole exiftool
  invocation.
- ⚠ Piwigo has **no locking convention at all** for files under `upload/` — this is new
  infrastructure, and `_data/` is the natural home for lock files by existing convention.
- A lock also serializes the lost-update case, giving last-write-wins deterministically rather than
  by race.
- ⚠ Interaction with Q13 (continue on error): a writer that cannot acquire the lock within a timeout
  must be recorded as a per-photo failure, not skipped silently — otherwise "applied to 200 photos"
  is reported when fewer were written.
- ⚠ The 12-way contention that triggers destruction is not exotic: the chunked-AJAX pattern
  (`admin/themes/default/js/batchManagerGlobal.js`) issues requests through
  `jQuery.manageAjax.create('queued', {queue:true, maxRequests:1})` — serialized **per browser tab**.
  Two tabs, or two admins, are two independent queues hitting the same files.

## Shared-Hosting Feasibility (ALL-INKL PrivatPlus)

Question raised 2026-08-29: does the design — exiftool in particular — work on the production
hosting package, ALL-INKL.COM **PrivatPlus**?

⚠ The captured resource file
`docs/agents/resources/Alte Tarife (nicht mehr bestellbar)  ALL‑INKL.COM.md` contains **only the
SSL certificate pricing block**, not the tariff's technical specification. The package's actual
capabilities (SSH, PHP `disable_functions`, Perl, extensions) are not in the repo and were
researched separately.

### Finding A — ⚠ Correction: Piwigo core *does* shell out, extensively

An earlier statement in this document — "Piwigo core shells out nowhere today" — is **wrong** and is
corrected here. Core calls `exec()` in at least these places:

| Location | Purpose |
|---|---|
| `admin/include/image.class.php:380` | `command -v magick` — probe for the binary |
| `admin/include/image.class.php:403` | `magick -version` — capability detection |
| `admin/include/image.class.php:604`, `:751` | the actual resize / rotate calls |
| `admin/include/functions_upload.inc.php:601` | upload-time image processing |
| `admin/maintenance_actions.php:329`, `admin/include/functions.php:3730` | version reporting |
| `include/ws_functions/pwg.php:213`, `:245` | `du -sk` for cache size reporting |

Piwigo ships an **external-ImageMagick graphics backend** driven by shell-out, selected via
`$conf['graphics_library'] = 'auto'` (`include/config_default.inc.php:260`) with a configurable
binary path `$conf['ext_imagick_dir'] = ''` (`:264`).

This materially changes the shared-hosting risk assessment: shelling out is not a foreign concept
being introduced, it is an existing core mechanism with an established detection-and-fallback
pattern.

### Finding B — the exact degradation pattern to copy

`pwg_image::is_ext_imagick()` (`admin/include/image.class.php:393-410`):

```php
static function is_ext_imagick()
{
  global $conf;
  if (!function_exists('exec'))     // ← disable_functions check FIRST
  {
    return false;
  }
  @exec($conf['ext_imagick_dir'].pwg_image::get_ext_imagick_command().' -version', $returnarray);
  if (is_array($returnarray) and !empty($returnarray[0]) and preg_match('/ImageMagick/i', $returnarray[0]))
  { ... return true; }
```

Three properties worth copying verbatim for an exiftool integration:

1. **`function_exists('exec')` is checked before anything else** — the graceful answer when a host
   has `exec` in `disable_functions`.
2. **Capability is probed by running `-version` and matching the output**, not assumed from a path.
3. **The directory is configurable** (`ext_imagick_dir`), so a binary in a user's home directory is
   supported without the binary being on `PATH`.

### Finding C — ⚠⚠ exiftool is a Perl script, not a compiled binary (measured)

This is the decisive fact for shared hosting.

```
$ file $(which exiftool)
/usr/bin/exiftool: Perl script text executable      (8026 lines)
```

It requires **no root, no apt, no compilation**. Measured directly: copying the script plus its
`Image/` module tree into an ordinary home directory and running it with `PERL5LIB` set works
completely —

```
$ mkdir -p ~/et/lib && cp /usr/bin/exiftool ~/et/ && cp -r /usr/share/perl5/Image ~/et/lib/
$ PERL5LIB=~/et/lib perl ~/et/exiftool -ver
13.25
```

and a **full write through that self-contained copy succeeded** on a real gallery PNG, with EXIF,
IPTC and XMP all reading back correctly (including `Łódź`, verified with `-charset iptc=UTF8` on
both write and read):

```
[EXIF]  ImageDescription  : Oma Müller Łódź
[IPTC]  Caption-Abstract  : Oma Müller Łódź
[XMP]   Description       : Oma Müller Łódź
```

So on **any** host that offers Perl and permits `exec`, ExifTool can be deployed by uploading files
into the web space — no package manager and no shell access required for installation.

⚠ Read/write symmetry: `-charset iptc=UTF8` must be passed on **reads as well as writes** when
reading via exiftool, or UTF-8 IPTC is re-interpreted as latin-1 and displays as mojibake. This does
not affect Piwigo, which reads IPTC via `iptcparse()` + `clean_iptc_value()` (which auto-detects),
but it will bite anyone verifying by hand.

### Finding E — the PrivatPlus tariff specification (fetched from the source page)

Fetched from `https://all-inkl.com/tarifinfo/?tnr=91d0dbfd38d950cb716c4dd26c5da08a&lang=de`
(the tariff the repo's resource file was captured from — that file holds only the SSL pricing block).
Tariff is listed under "Alte Tarife (nicht mehr bestellbar)", i.e. a legacy package still running but
no longer sold.

| Feature | Value |
|---|---|
| Tariff | **all-inkl PrivatPlus** |
| **SSH-Zugang** | **nein** |
| **Perl** | **"Eigene CGI's sind möglich"** — custom CGI scripts supported |
| PHP | "PHP inklusive vieler Module ist installiert" (no version stated on this page) |
| **Cronjobs** | **25 included** (more purchasable: 1 job €0.95/mo … 10 jobs €6.95/mo) |
| Speicherplatz | 100 GB |
| MySQL-Datenbanken | 25 |
| SSI | included |
| `.htaccess` | available |
| ImageMagick / `exec` | **not mentioned** |
| Memory / execution limits | **not stated** |

Three things follow directly:

- ⚠ **No SSH.** So the container-style `apt-get install libimage-exiftool-perl` route (decision C7a)
  does **not** apply to production. But per Finding C this does not block ExifTool: it is a Perl
  script that installs by **uploading files via FTP** into the web space, needing no shell.
- ✅ **Perl with custom CGI is explicitly supported.** ExifTool's own runtime requirement is
  therefore satisfied by the tariff as documented.
- ⚠ **25 cron jobs — but see Finding F.3.** ALL-INKL cron jobs are configured by entering a **URL
  to fetch**, not a shell command, so cron cannot invoke ExifTool directly; it schedules an HTTP
  request to a PHP script and inherits PHP's restrictions. Still useful for scheduling deferred DB
  work (which suits decision C2), but **not** an escape hatch from `disable_functions`.

⚠ **The decisive unknown is not answered by the tariff page**: whether PHP's `exec()` /
`shell_exec()` / `proc_open()` are permitted. SSH access and PHP `disable_functions` are independent
settings — many shared hosts disable interactive SSH while still permitting PHP to spawn processes.
The tariff page says nothing about `disable_functions`, and this cannot be settled from documentation
alone.

**How to settle it definitively** — one file uploaded to the production web space:

**File name and extension.** A plain **`.php`** file, uploaded by FTP into the web root — the same
place `index.php` lives. Two practical points:

- ⚠ **Give it an unguessable name and delete it afterwards** (e.g. `probe-8f3a1c.php`). Its output
  discloses PHP version, loaded extensions, `disable_functions` and paths — the kind of
  reconnaissance detail that should not sit at a predictable URL such as `/test.php` or `/info.php`.
- ⚠ **To test the PHP-CGI mode claim (F.1) the extension matters.** The reported workaround runs PHP
  through a CGI handler, which ALL-INKL historically bound to a **different extension** (`.phpx`) or
  to `.php` via an `.htaccess` `AddHandler` line. So a `.php` file measures **mod_php** — the default
  mode. If mod_php reports `exec` disabled, a **second copy of the same probe under the CGI handler**
  is what tests whether the workaround actually restores it. Both results matter; one alone does not
  answer the question.

```php
<?php
// probe-<random>.php  — delete after reading
header('Content-Type: text/plain');
var_dump(function_exists('exec'), function_exists('proc_open'), function_exists('shell_exec'));
var_dump(ini_get('disable_functions'));
var_dump(extension_loaded('imagick'), extension_loaded('exif'), extension_loaded('gd'));
var_dump(PHP_VERSION, PHP_SAPI, ini_get('memory_limit'), ini_get('max_execution_time'));
echo @shell_exec('perl -v') ?: "perl not reachable via shell_exec\n";
echo @shell_exec('which exiftool') ?: "exiftool not on PATH\n";
```

`PHP_SAPI` is included deliberately: it reports which mode answered the request
(`apache2handler` for mod_php, `cgi-fcgi` or similar under CGI), so the two probe results can be
told apart.

This is a five-minute check that resolves the entire question, and it is the recommended first
action of the plan phase. Note Piwigo's own admin already surfaces part of this: the
`is_ext_imagick()` probe (Finding B) reports whether the external ImageMagick backend is usable,
which is exactly a `function_exists('exec')` + binary-probe result.

⚠ **Fallback if `exec` is disabled**: ExifTool can still be reached as a **Perl CGI**, which the
tariff explicitly supports — the script is invoked over HTTP by the web server rather than spawned
by PHP. That is an unusual deployment and carries its own access-control burden (the CGI must not be
callable by anyone who can reach the URL), and it was **not tested here**. The simpler degraded mode
remains Imagick-only XMP + IPTC (Finding D).

### Finding F — ⚠⚠ the two capabilities the design needs are both in doubt on this host

Web research across ALL-INKL's own pages and German-language forums. **Confidence is marked
per claim**, because the load-bearing facts are *not* in official documentation.

#### F.1 `exec()` family — reportedly disabled by default `[USER-SOURCED]`

Multiple independent forum threads spanning years and different CMSes (CMS Made Simple, TYPO3,
Joomla-era, the Gallery project) consistently report the same `disable_functions` list under
ALL-INKL's **default mod_php** mode:

```
exec, system, passthru, shell_exec, popen, escapeshellcmd, proc_open, proc_nice
```

- https://forum.cmsmadesimple.org/viewtopic.php?t=35147
- https://www.typo3forum.net/discussion/50807/all-inkl-php-cgi-und-imagemagick

⚠ **No official ALL-INKL document states this**, and none states `disable_functions` per tariff.
The evidence is convergent user reports, which is suggestive but not authoritative.

Two things cut against it:

- ALL-INKL publishes a tutorial *"How to execute shell scripts"* whose example is literally
  `exec("/bin/bash <path> 2>&1", $out, $result)`. It says only that "some commands are locked due
  to security reasons" and that large scripts may hit resource limits — it does **not** say which
  PHP mode or tariff this requires.
  https://all-inkl.com/en/support/tutorials/scripts/miscellaneous/via-script/how-to-execute-shell-scripts_304.html
- A second-hand report of an ALL-INKL support statement says the functions **can be re-enabled by
  switching from mod_php to PHP-CGI mode** (`.htaccess` handler change), at the cost of "stricter
  resource restrictions". `[USER-SOURCED, unconfirmed by any primary document]`

**Net: genuinely ambiguous, leaning disabled-by-default.** Not safe to assume either way.

#### F.2 ⚠ Imagick reportedly unavailable under PHP 8 `[USER-SOURCED]`

Reports indicate **Imagick is not supported on ALL-INKL under PHP 8**, with users directed to GD
instead. Primary confirmation could not be opened; treat as user-level.

This is the more damaging of the two findings, and it **invalidates the fallback described in
Finding D**. That fallback assumed Imagick could still write XMP + IPTC when `exec` was
unavailable. If Imagick is absent:

- **GD cannot write image metadata at all** — it has no profile API whatsoever.
- Piwigo's own graphics layer degrades to GD (`$conf['graphics_library']`), which is why the site
  still works, but nothing in that path can carry a metadata write.

⚠ **Worst case, if both F.1 and F.2 hold: file metadata write-back is impossible on this host by
any available mechanism.** Not degraded — impossible.

#### F.3 Cron is URL-based, not shell-based `[OFFICIAL]`

PrivatPlus's 25 cron jobs are configured in KAS by **entering a URL to fetch**, not an arbitrary
shell command.

⚠ This corrects an over-optimistic reading recorded in Finding E: cron does **not** provide a way to
invoke ExifTool directly. It schedules an HTTP request to a PHP script, so it inherits whatever
restrictions PHP is already under. It remains useful for *scheduling* deferred DB work (which suits
decision C2), but it is not an escape hatch from `disable_functions`.

#### F.4 Other specifics

| Item | Finding | Confidence |
|---|---|---|
| SSH on PrivatPlus | **not included** (Premium and Business only) | `[OFFICIAL]` — plans comparison |
| Perl / custom CGI | supported; scripts in `cgi-bin`, ASCII upload, `chmod 755` | `[OFFICIAL]` product page + `[USER]` detail |
| Perl version | one 2011-era report cites 5.8.8 — almost certainly stale | `[UNKNOWN]` |
| ExifTool preinstalled | **no evidence either way**; search returned nothing ALL-INKL-specific | `[UNKNOWN]` |
| PHP versions | selectable per (sub)domain in KAS; 5.6 … 8.2 referenced in migration docs | `[OFFICIAL]` |
| `gd` / `exif` / `mbstring` / `iconv` | not enumerated anywhere; GD implied available | `[UNKNOWN]` |
| `memory_limit`, `max_execution_time` | not settable via KAS; since PHP 8 must use `.user.ini`, not `.htaccess` | `[USER]` |
| Package | 100 GB, 5 domains, 25 MySQL, 25 cron, ~€7.95/mo | `[OFFICIAL]` |

#### F.5 What this means

The honest position: **this cannot be resolved from public sources.** The two facts the design
depends on — whether `exec` is permitted, and whether Imagick exists — are both `[USER-SOURCED]`
and both point the wrong way.

The probe script in Finding E answers both at once and should run before any planning proceeds. Add
one line to it:

```php
var_dump(extension_loaded('imagick'));   // ← the fallback depends entirely on this
```

Possible outcomes, and what each costs:

| `exec` | `imagick` | Write-back capability |
|---|---|---|
| ✅ | either | **Full** — EXIF + IPTC + XMP via uploaded ExifTool (Finding C) |
| ❌ | ✅ | **Degraded** — XMP + IPTC only, no EXIF; lossy re-encode on JPEG/HEIC |
| ❌ | ❌ | **None** — no mechanism can write metadata; DB-only feature |

⚠ Note the third row does **not** kill the feature: per Finding D, every other component (schema,
propagation, audit trail, admin modal, public row, WS methods, locking) is pure PHP + MySQL. What is
lost is Q9/Q15 — the write-into-the-file requirement — which is the feature's original premise.
Whether the feature is still worth building without it is a product decision, not a technical one.

⚠ Also worth flagging for the plan: the PHP-CGI switch (F.1) is a **global change to how the whole
Piwigo install is served**, not a per-feature toggle, and carries a reported penalty of stricter
resource limits. That is a decision about the hosting of the entire site, made in service of one
feature.

### Finding D — the design splits cleanly along the dependency

Only one component of the feature depends on exiftool at all:

| Component | Depends on | Works without `exec`? |
|---|---|---|
| Album provenance fields (schema, C1) | MySQL, PHP | ✅ |
| Propagation to photos (C2, Q6b) | MySQL, PHP | ✅ |
| Audit/history table (C6) | MySQL, PHP | ✅ |
| Admin button + modal (prefilter) | PHP / Smarty | ✅ |
| Public `#Provenance` row (9b) | PHP / Smarty | ✅ |
| New WS method, core trigger patches | PHP | ✅ |
| Per-file locking (`flock`) | PHP `flock()` | ✅ |
| **File metadata write-back (Q9, Q15)** | **exiftool + `exec`** | ❌ |
| — XMP + IPTC only, as a fallback | Imagick | ✅ *(measured in §C)* |
| — EXIF | exiftool only | ❌ |

So a host that forbids `exec` costs **the EXIF half of the write-back** — *provided Imagick is
present*. Imagick was measured in the first pass as able to write XMP and IPTC profiles (but never
EXIF), so that degraded mode retains XMP `dc:description` — which the MWG treats as a first-class
member of the caption mirror set — plus IPTC.

⚠ **This fallback is itself in doubt on ALL-INKL** — Imagick is reported unavailable under PHP 8
there (Finding F.2). GD, the alternative, cannot write metadata at all. See F.5 for the full
outcome matrix.

⚠ Caveat on the degraded mode: the Imagick write path **re-encodes pixels**, measured lossy for
JPEG and HEIC (§C). On a host without `exec`, write-back for those two formats trades image quality
for metadata. PNG and TIFF re-encode losslessly.

## Production Host Probe — RESULT (2026-08-29, measured on the live account)

The capability probe was run on the production ALL-INKL PrivatPlus account. **It overturns the
forum-sourced findings in Finding F entirely.** Verbatim output:

```
=== PHP mode ===
PHP_VERSION:                       8.4.16-nmm1
PHP_SAPI:                          fpm-fcgi
memory_limit:                      384M
max_execution_time:                60

=== Process execution ===
exec():                            YES
shell_exec():                      YES
proc_open():                       YES
system():                          YES
passthru():                        YES
popen():                           YES
disable_functions:                 (empty)

=== Imaging and metadata extensions ===
imagick:                           YES
gd:                                YES
exif:                              YES
mbstring:                          YES
iconv:                             YES
ImageMagick version:               ImageMagick 6.9.12-98 Q16 x86_64 18038 https://legacy.imagemagick.org
Imagick XMP write test:            OK (fallback usable)

=== Perl and ExifTool ===
perl:                              5.038002
exiftool on PATH:                  /usr/bin/exiftool
exiftool version:                  12.76

=== Verdict for the write-back requirement ===
FULL: ExifTool can be uploaded and invoked.
```

### ⚠ Corrections to Finding F — the forum reports were wrong (or stale)

| Finding F claim | Confidence then | Measured reality |
|---|---|---|
| `exec`/`shell_exec`/`proc_open` disabled by default | `[USER-SOURCED]` | **All enabled. `disable_functions` is EMPTY.** |
| Imagick unavailable under PHP 8 | `[USER-SOURCED]` | **Imagick present and working** — the live XMP write test passed |
| ExifTool preinstalled | `[UNKNOWN]` | **Preinstalled at `/usr/bin/exiftool`, v12.76** |
| PHP-CGI switch needed to re-enable exec | `[USER-SOURCED]` | **Moot** — no switch required |

The likely explanation for the discrepancy: `PHP_SAPI` is **`fpm-fcgi`**, not `apache2handler`.
The forum reports describe ALL-INKL's older **mod_php** configuration, where the restrictive
`disable_functions` list applied. The account now runs **PHP-FPM**, where it does not. Those reports
were not wrong when written; they are stale.

**This closes the blocker.** No upload of ExifTool is needed, no CGI-mode workaround, no degraded
mode. The `blocked_on` field in this document's frontmatter is cleared.

### New constraints the probe revealed

Three facts that were unknown before and that do bear on the plan:

**⚠ 1. `max_execution_time: 60` seconds.** This is a hard per-request ceiling in production, where
the local DDEV container has none (`max_execution_time: 0`). Every design measured locally must fit
inside 60 s per request or be chunked.

This **strongly validates decisions C2 and Q4**: file write-back as a separate, chunked operation
rather than synchronous work on album save. It also puts a number on the chunk sizing that the
Batch Manager pattern (`admin/themes/default/js/batchManagerGlobal.js`, blocks of
`min(round(n/2), 1000)`) leaves open.

⚠ Relevant sizing fact not yet measured: each `exiftool` invocation pays Perl interpreter startup.
ExifTool can take **many files in a single invocation**, and offers `-stay_open` for long-running
use — so per-file process spawning is not the only option. Neither the per-invocation startup cost
nor the throughput of a batched invocation was measured; both matter for choosing a chunk size
against a 60 s ceiling.

**⚠ 2. ImageMagick 6.9.12 (legacy), not 7.1.1.** The production host runs the IM6 series; all local
measurements in §C and the exiftool verification used IM 7.1.1.

- Piwigo already handles the difference: `get_ext_imagick_command()`
  (`admin/include/image.class.php:370-391`) probes `command -v magick` and falls back to `convert`,
  which is the IM6 binary name.
- The Imagick **fallback path was live-tested on the host** by the probe (write an XMP profile to a
  PNG, read it back) and passed — so IM6's profile handling is confirmed working there, not assumed.
- ⚠ Not verified on IM6: the IPTC-profile write and the pixel-fidelity figures from §C. Those
  measurements stand for IM7 only. They matter only if the Imagick fallback is ever used, which the
  probe result makes unnecessary.

**3. ExifTool 12.76, not 13.25.** The production version is older than the one everything was
measured against. **Checked against the changelog and cleared** — every mechanism used has been
stable since 2017 or earlier. See the version comparison below. One HEIC-specific caveat is recorded
there; it does not affect a PNG collection.

**Other environment facts recorded:** PHP 8.4.16-nmm1 (close to the local 8.4.20, so no PHP version
gap), `memory_limit` 384M, Perl 5.038002, and `exif`/`mbstring`/`iconv` all present — the extensions
`get_exif_data()` and `clean_iptc_value()` depend on
(`include/functions_metadata.inc.php:132-135`, `:99-113`).

### ExifTool 12.76 vs 13.25 — every verified behaviour is present in the production version

All local measurements ran on 13.25; production has **12.76** (released 2024-01-31). Checked against
the official changelog (`Image::ExifTool::Changes`, the source from which `exiftool.org/history.html`
is generated — https://raw.githubusercontent.com/exiftool/exiftool/master/Changes).

| Capability used in this design | Introduced | In 12.76? |
|---|---|---|
| **PNG `eXIf` chunk write** | **10.42** (2017-02-10) — "Added ability to read/write PNG eXIF and zXIF chunks"; final `eXIf` casing in **10.59** (2017-07-07) | ✅ |
| IPTC-in-PNG write (with the "non-standard" warning) | warning added **9.69** (2014-07-27); capability predates it | ✅ |
| `-@ argfile` | **5.18** (2005-05-16) | ✅ |
| `-charset iptc=UTF8` | **7.90** (2009-08-24), fixed **7.92** | ✅ |
| `-overwrite_original` | **5.19** (2005-05-17) | ✅ |
| `-overwrite_original_in_place` | **6.02** (2006-02-26) | ✅ |
| `-XMP-dc:`, `-XMP-photoshop:`, `-XMP-tiff:` namespaces | core XMP tables, long predate v10 | ✅ |

**Every mechanism this design depends on has been stable since 2017 or earlier.** 12.76 was itself a
small patch release (Sony ARW raw-data duplication, HtmlDump offsets), not a feature release.

Changes between 12.76 and 13.25 (~13 months, ~50 releases including the 13.00 bump) that touch
metadata writing:

- **PNG** — additive read-side only (GainMapImage extraction, Samsung trailer reading, a couple of
  Stable Diffusion tags). **No fix or behaviour change to the core EXIF/IPTC/XMP PNG-writing path.**
- **JPEG** — maker-note/vendor decode fixes (Vivo, OnePlus, Nikon Z lens names); nothing in core writing.
- **TIFF** — routine tag additions only.
- **WebP / GIF** — writer fixes, irrelevant here.
- ⚠ **HEIC** — 13.25 carries: *"IMPORTANT: Fixed issue which could corrupt HEIC images from newer
  iPhones under certain conditions when writing QuickTime:Rotation."*

⚠ **The one caveat worth carrying**: that HEIC corruption bug is present in 12.76. It is scoped to
writing `QuickTime:Rotation` on newer-iPhone HEIC files — something this design does not do (it
writes only description/headline fields) — and the collection is **100% PNG** today. But if HEIC
ingestion ever becomes real, the production ExifTool version becomes a live concern and should be
re-checked then.

No PNG-writing regression existing in 12.76 and fixed later was found; all PNG "Fixed" entries in the
changelog predate 12.76 by years.

**Conclusion: the production ExifTool version introduces no risk for this design.** The measurements
taken on 13.25 transfer to 12.76 for PNG, JPEG and TIFF.

## For the Plan Phase — 3 Amigos Session (imagined)

Three perspectives on the same story, run as if in a room. Recorded so the plan does not have to
rediscover the disagreements. Scenarios follow the Given/When/Then conventions in the project rules
(`.claude/rules/` and the `given-when-then` skill): Given = past-tense context, When = the one
event, Then = observable outcome.

**Story under discussion**
> As a gallery owner, I want to write one piece of text on an album so that every photo in it
> carries that text, including in the photo's own file metadata.

### Business / Product

*"The point is I type it once."* Pushes for Q1(a) and Q3 "photo wins". Wants the album field to
look and behave like the existing album Description field so nobody has to learn anything new.
Raises: what does the user see on the photo page — one merged line, or a visibly-inherited value
with a hint of where it came from?

Concern surfaced: if the album text is silently copied into 200 photos, and the owner then fixes a
typo in the album text, they will expect all 200 to fix themselves. Copy-down does not do that
unless explicitly designed to. **This is the scenario most likely to generate a bug report.**

### Development

*"There is no mechanism here to hang this on."* Points at the concrete blockers found in research.
Revised after the UI scoping decision above — the list is shorter than it first looked:

Resolved:
- ~~The album screen cannot be extended.~~ It can: `loc_begin_admin_page` (`admin.php:406`) fires
  before the page include, `$page['page'] === 'album'`, and `set_prefilter('album_properties', …)`
  reaches `cat_modify.tpl` before it compiles at `admin/cat_modify.php:393`. A button + modal is
  accepted, and that exact pattern already ships on the page (`cat_modify.tpl:141`, `:188`, `:191-203`).

Still real:
- `ws_categories_setInfo` has a hard-coded `$info_columns` allow-list (`pwg.categories.php:946`)
  and no `trigger_change` — an album field cannot ride the existing save, and needs its own WS method.
- Plugin album *tabs* are declarable (`tabsheet_before_select`) but not includable
  (`admin/album.php:83` resolves to a core path) — so the field lives on the properties tab, not a
  tab of its own.
- No queue, no cron, no server-side chunking. N file rewrites must be client-driven.
- Imagick cannot write EXIF at all, and JPEG/HEIC writes are lossy re-encodes (measured, §C).
- `ws.php` cannot call `invalidate_user_cache()`; a direct `UPDATE piwigo_user_cache` is the
  in-repo workaround.

Wants Q4 answered before anything else, because "on album save" and "explicit apply button" are
different features — and with a modal in play, the second is now the cheaper one to build.

### Testing / QA

*"Show me the state machine."* Notes that under `.claude/rules/test-design.md` every fixture must
**force** its precondition and assert it took effect — and that the local install has exactly one
album and one photo-album shape, so almost every interesting case has no fixture yet. Wants
`[ST]` state-transition coverage for Q6, and flags that the 1:1 assumption is currently a *hope*,
not a constraint, so a test must assert it rather than rely on it.

Also: mutation testing is explicitly not a per-task step (`.claude/rules/mutation-testing.md`) —
reserve it for the end of the plan or a critical phase.

### Scenarios the three of them agreed are the acceptance criteria

```gherkin
Scenario: Album text reaches a photo that has no text of its own
  Given an album with no description text
    And a photo in that album with no text of its own
   When the owner saves the album text "Iceland 2026"
   Then the photo's text reads "Iceland 2026"

Scenario: A photo's own text is not overwritten by album text
  Given an album containing a photo whose text reads "Puffin on a cliff"
   When the owner saves the album text "Iceland 2026"
   Then the photo's text still reads "Puffin on a cliff"

Scenario: The text is written into the image file
  Given a photo whose text reads "Iceland 2026"
   When the write-back runs for that photo
   Then reading the file's metadata returns "Iceland 2026"

Scenario: Correcting the album text
  Given an album whose text "Iceladn 2026" has already reached its photos
   When the owner corrects the album text to "Iceland 2026"
   Then every photo in the album reads "Iceland 2026"

Scenario: A photo joining the album afterwards
  Given an album whose text "Iceland 2026" has already reached its photos
   When a new photo is added to that album
   Then that photo reads "Iceland 2026"

Scenario: A photo moved out of the album
  Given a photo whose text was inherited from album "Iceland 2026"
   When the photo is moved to another album
   Then the owner is asked whether to keep or replace the inherited text
```

**Updated 2026-08-29** — all six scenarios now have a Then, resolved by the recorded decisions
(Q6b, Q5b, Q7). Revised again after the conflict resolutions:

- "Correcting the album text" overwrites per-photo edits (Q6b). The sync revert loop no longer
  applies — C3 keeps album-sourced fields out of the sync mapping, so no `sync_metadata()`
  survival assertion is needed.
- "A photo joining the album afterwards" is implementable via the C4 core patch, and the
  filesystem-sync gap is closed by the second core patch (resolution 1b) — so the scenario holds
  for every entry path, with no carve-out.

Two further scenarios are implied by the recorded decisions and have no coverage yet:

```gherkin
Scenario: Album deleted while its photos survive
  Given an album whose text has already reached its photos
    And the admin chose not to delete the photos
   When the album is deleted
   Then the admin is asked what happens to the inherited text

Scenario: A photo-level value reaches the image file
  Given a photo whose provenance text was set
   When the write-back runs
   Then reading the file returns that text from XMP dc:description
    And reading the file returns that text from IPTC 2:120
    And reading the file returns that text from EXIF ImageDescription

Scenario: Applying to photos does not itself touch the files
  Given an album whose provenance text has just been saved
   When the text is applied to the album's photos
   Then the photos' database values are updated
    And no image file has been modified

Scenario: Concurrent writes to the same photo never destroy it
  Given a photo whose file is being written by one metadata operation
   When a second metadata operation targets the same photo
   Then both operations complete
    And the image file still exists and is readable

Scenario: A file edited elsewhere does not overwrite album-sourced provenance
  Given a photo whose owner was inherited from its album
    And whose file was later edited by another tool to a different owner
   When metadata synchronisation runs
   Then the photo's owner still reads the album's value
```

```gherkin
Scenario: Provenance is visible on the photo page
  Given a photo that inherited provenance from its album
   When a visitor opens the photo page
   Then the info panel shows a Provenance row with that text

Scenario: Text too long for IPTC is preserved where the standard allows
  Given provenance text longer than 2000 bytes
   When the write-back runs
   Then the file's XMP dc:description holds the full text
    And the file's IPTC 2:120 holds a truncated copy
    And the truncation is recorded in the history

Scenario: A photo added by filesystem sync inherits the album text
  Given an album whose provenance text has already reached its photos
   When a new file is discovered in that album by filesystem synchronisation
   Then that photo carries the album's provenance text
```

The scenarios above are the direct consequences of C2 (apply and write-back are separate
operations), C3 (album-sourced fields are excluded from the sync mapping), 9b, 10a and 1b. All are
behaviours that regress **silently** — none produces a visible error when it breaks, which is
precisely why each needs a named test rather than a hand-check.

⚠ Two of them cannot run against this install's data as it stands: the IPTC-truncation scenario
needs a non-PNG file (the install is 100% PNG, and IPTC is invisible to Piwigo's own reader on PNG,
§C), and the filesystem-sync scenario needs a photo with a non-NULL `storage_category_id` (all 76
are NULL). Both depend on the `FixtureBuilder` extension in resolution 8a.

### Handover note to the plan phase

The single decision that unblocks the most downstream work is **Q3 (precedence)**, because it
determines whether the photo gets one field or two, which determines the schema, which determines
everything else. Q4 (timing) is second, because it decides whether this is one feature or two.
