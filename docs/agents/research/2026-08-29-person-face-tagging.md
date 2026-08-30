---
date: 2026-08-29T20:35:43+00:00
git_commit: 0cad900420e132f8fea17f957dc21c2973a26cea
branch: feat/provenance-metadata
topic: "Person/face tagging: click a face, name it, search existing people"
tags: [research, codebase, tags, picture-page, derivatives, web-services, plugins, xmp, mwg-regions, ux]
status: complete
---

# Research: Person / Face Tagging

## Research Question

Build a person tagging system — like the existing tags, but for persons in a photo, with a
marker: click a person/face in the image, then either enter a new person (saved for reuse) or
select from a list, with partial search. Follow the UI/UX standards of market leaders.

Research only — this document describes what exists today, in this codebase and outside it. It
proposes nothing.

## Summary

Everything the feature needs already exists in the repo as separate, working pieces; none of them
are currently connected to each other.

- **A rectangle-on-a-photo editor ships in core.** `admin/picture_coi.php` +
  `admin/themes/default/template/picture_coi.tpl` use **Jcrop 0.9.12** (vendored) to let an
  administrator drag a box over a photo, and store it as a *normalized* rect in `images.coi`.
- **An autocomplete widget with create-on-the-fly ships in core.** **Selectize 0.11.2**, driven by
  `TagsCache` in `admin/themes/default/js/LocalStorageCache.js`, backed by one bulk fetch of
  `pwg.tags.getAdminList` into `localStorage`, filtered client-side. There is no server-side
  partial-search endpoint for tags.
- **An interactive widget injected into `picture.php` via a Smarty prefilter plus
  plugin-registered web-service methods ships in this fork** — `plugins/typetags`.
- **A plugin owning its own schema and writing metadata to the file with exiftool ships in this
  fork** — `plugins/provenance`.
- **The join table has no room for a region.** `piwigo_image_tag` is `PRIMARY KEY (image_id,
  tag_id)` with no other columns, so a per-region record needs its own table regardless of whether
  persons reuse `piwigo_tags` or get a table of their own.
- **The de-facto interchange format for face regions is MWG Regions (`XMP-mwg-rs`)**, and the
  container's exiftool 13.25 reads and writes it with **no custom config** — unlike the provenance
  plugin's own namespace, which needed `pwgprov.config`. Verified in-container, 2026-08-29.
- **Two maintained Piwigo extensions already do this**, released 2026-08-16 and 2026-08-18, and
  both are metadata-native: regions live in the image's XMP, the database holds only bookkeeping.

The main friction the codebase presents is not storage — it is **coordinate systems**. Three
independent transforms sit between a stored region and a box drawn on the screen: EXIF/DB
rotation, the derivative actually being displayed, and modus's runtime re-selection of that
derivative on load and on every resize.

## Detailed Findings

### 1. The existing tag data model

**`piwigo_tags`** — `install/piwigo_structure-mysql.sql:360-368`:

| Column | Type |
|---|---|
| `id` | `smallint(5) unsigned auto_increment` |
| `name` | `varchar(255)` |
| `url_name` | `varchar(255) binary` |
| `lastmodified` | `TIMESTAMP … ON UPDATE CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `tags_i1 (url_name)`, `lastmodified`. MyISAM. Note `id` is a **smallint** —
65,535 tags maximum.

**`piwigo_image_tag`** — `:205-210`: `image_id mediumint unsigned`, `tag_id smallint unsigned`,
`PRIMARY KEY (image_id, tag_id)`, `KEY image_tag_i1 (tag_id)`. A pure join table: no extra columns,
no foreign keys, no `lastmodified`. **A region cannot be attached here** — and the composite
primary key also means one image cannot carry the same tag twice, which a "two photos of the same
person in one frame" case would need.

Only two migrations ever touched these tables: `install/db/140-database.php:17` (dropped `binary`
from `tags.name`) and `install/db/141-database.php:13-29` (added `lastmodified`).

Read side, `include/functions_tag.inc.php`: `get_available_tags()` (`:40`) counts distinct images
per tag joined through `IMAGE_CATEGORY_TABLE`, applies `get_sql_condition_FandF()` permission
filtering (`:51-59`), caches per user in `$persistent_cache` (`:77-88`), and passes every name
through `trigger_change('render_tag_name', …)` (`:117`). `get_all_tags()` (`:125`) is the unfiltered
admin variant. `get_image_ids_for_tags()` (`:214`) does AND mode with
`HAVING COUNT(DISTINCT tag_id) = N` (`:252`). Canonical sort is `tag_alpha_compare()`
(`include/functions_html.inc.php:242`), comparing `pwg_transliterate($tag['name'])`.

Write side, `admin/include/functions.php`:

| Function | Line | Behaviour |
|---|---|---|
| `set_tags_of($tags_of)` | `:1779` | the only real writer: delete-all-then-mass-insert per image, diff before/after, `update_images_lastmodified()`, `invalidate_user_cache_nb_tags()` |
| `set_tags($tags, $image_id)` | `:1602` | one-image wrapper over the above |
| `add_tags($tags, $images)` | `:1613` | additive; pre-deletes the exact pairs it is about to insert to dodge PK collisions |
| `delete_tags($tag_ids)` | `:1661` | fires `trigger_notify('delete_tags', …)` and `pwg_activity()` |
| `tag_id_from_tag_name($name)` | `:1709` | find-or-create; three-stage lookup (exact `name`, then `url_name`, then plugin-supplied where clauses from `get_tag_name_like_where`) |
| `create_tag($name)` | `:2378` | API-facing creator; `strip_tags`, rejects an exact duplicate `name`, does **not** consult `url_name` |
| `get_tag_ids($raw_tags, $allow_create=true)` | `:2874` | the `~~id~~` convention: `~~34~~` means existing tag 34, anything else is a name to create |
| `get_orphan_tags()` | `:430` | `lastmodified < SUBDATE(NOW(), INTERVAL 1 DAY)` — the reason `tags.lastmodified` exists |

`url_name` is never stored by the caller; it always comes from
`trigger_change('render_tag_url', $name)`, whose default handler is `str2url()`
(`include/functions.inc.php:347-360`), registered at `include/common.inc.php:362`.

### 2. The existing tag UI

**Admin tag manager** — `admin/tags.php` + `admin/themes/default/template/tags.tpl` +
`admin/themes/default/js/tags.js` (1085 lines). The only server-side action in the PHP is
`?action=delete_orphans`; add / rename / duplicate / delete / merge all go over `ws.php` from JS.
The full tag set is shipped to the client in `data-tags='{$data|@json_encode}'`
(`tags.tpl:190-215`) and only the first 100 are server-rendered; search and pagination are
client-side.

**Photo-level tag editing** — `admin/picture_modify.php`. The widget is one line
(`picture_modify.tpl:197-202`):

```smarty
<select data-selectize="tags" data-value="{$tag_selection|@json_encode|escape:html}"
  placeholder="{'Type in a search term'|translate}"
  data-create="true" name="tags[]" multiple style="width:calc(100% + 2px);"></select>
```

wired up at `:25-33`:

```smarty
var tagsCache = new TagsCache({ serverKey: '{$CACHE_KEYS.tags}', serverId: '{$CACHE_KEYS._hash}', rootUrl: '{$ROOT_URL}' });
tagsCache.selectize(jQuery('[data-selectize=tags]'), { lang: { 'Add': '{'Create'|translate}' }});
```

`TagsCache` (`admin/themes/default/js/LocalStorageCache.js:259-297`) fetches
`ws.php?format=json&method=pwg.tags.getAdminList` **once**, rewrites each `id` into the `~~id~~`
form, stores it under the localStorage key `tagsAdminList`, and hands it to Selectize with
`searchField: ['name']`, `sortField: 'name'`, `plugins: ['remove_button']`. Cache freshness is the
`CACHE_KEYS` pair from `get_admin_client_cache_keys()` (`admin/include/functions.php:3243`), each
key being `UNIX_TIMESTAMP(MAX(lastmodified))_COUNT(*)` of the table. **So "partial search" today is
Selectize filtering an in-browser copy of the whole list — there is no search endpoint.**
`data-create="true"` is what makes inline creation work, via the `~~id~~` convention documented at
`admin/include/functions.php:2865-2872`.

The same widget appears in the Batch Manager: `batch_manager_global.tpl:472-487` (add uses
`data-create="true"`, delete does not and is seeded from `associated_tags`),
`batch_manager_unit.tpl:219-222`, and the filter panel
`include/batch_manager_filter.inc.tpl:130-139`.

**Front-end display** — `picture.php:897-921` builds `related_tags` from
`get_common_tags(array($page['image_id']), -1)`; `themes/default/template/picture.tpl:210-217`
renders it read-only as comma-separated links inside `<dl id="standard">`. There is no front-end
tag editing in core.

Vendored but unused: `chosen.jquery.min.js`, `jquery.tokeninput.js` in
`themes/default/js/plugins/` — nothing in core references them.

### 3. Core already draws a normalized rectangle on a photo

`admin/picture_coi.php` is the "center of interest" editor, reached at
`admin.php?page=picture_coi&image_id=N` (linked from `picture_modify.php:321` and registered as a
photo tab at `admin/include/add_core_tabs.inc.php:128`).

The template loads **Jcrop 0.9.12** (`themes/default/js/plugins/jquery.Jcrop.min.js`,
`jquery.Jcrop.css`) and converts pixel selections to fractions in the browser
(`picture_coi.tpl`, footer script):

```js
function to_coi(v, total) { return v/total; }
function jOnChange(sel) {
  var $img = jQuery("#jcrop");
  jQuery("#l").val( to_coi(sel.x,  $img.width()) );
  jQuery("#t").val( to_coi(sel.y,  $img.height()) );
  jQuery("#r").val( to_coi(sel.x2, $img.width()) );
  jQuery("#b").val( to_coi(sel.y2, $img.height()) );
}
```

The server encodes the four fractions into a **4-character string** via `fraction_to_char()`
(`include/derivative_params.inc.php:67`) and stores it in `images.coi`
(`admin/picture_coi.php:26-35`); `char_to_fraction()` (`:56`) reads it back. The rect is
`l, t, r, b` (two corners), normalized, top-left origin. It is consumed by the cropping derivatives
in `DerivativeParams::crop_h()` / `crop_v()` (`derivative_params.inc.php:118, 152`).

Two things this precedent establishes in-repo: a normalized rect is the existing idiom for
"a region of a photo", and Jcrop is already available on the admin side with no new dependency.
`picture_coi.php` also deletes the affected derivatives after a save (`:44-49`).

### 4. How the photo is actually displayed — the hard part

The `<img>` is **not** in `picture.tpl`. It comes from the `render_element_content` filter
(`picture.php:967-974`), whose result is assigned as `ELEMENT_CONTENT`. Two handlers exist:

- core `default_picture_content()` — registered `picture.php:125`, parses
  `themes/default/template/picture_content.tpl`
- **modus `modus_picture_content()`** — registered at priority `NEUTRAL-1` in
  `themes/modus/themeconf.inc.php:369`, parses `themes/modus/template/picture_content_asize.tpl`.
  Since it runs first and core bails on non-empty content (`picture.php:137-140`), **this is what
  renders on this install.**

The element (`picture_content_asize.tpl:22`) is `id="theMainImage"`, class
`file-ext-<ext> path-ext-<ext>`, `usemap="#map<type>"`. Containers, from
`themes/default/template/picture.tpl` (modus does **not** override `picture.tpl`):
`#content` (`:3`) → `#theImageAndInfos` (`:131`) → `#theImage` (`:132`) → `{$ELEMENT_CONTENT}`.
**No container declares `position: relative`** (`themes/modus/css/picture.css.tpl:34, 121-134`;
`hf_base.css:542`).

Three moving parts sit between a stored region and screen pixels:

1. **Rotation.** `images.rotation` is a code `0..3`, not degrees
   (`install/piwigo_structure-mysql.sql:238`). `SrcImage::__construct()`
   (`include/derivative.inc.php:74-92`) does `$this->rotation = intval($infos['rotation']) % 4`
   and **swaps width/height when the code is odd**. So the stored `images.width`/`height` are the
   *raw file* dimensions before rotation, while everything the display path uses is the post-swap
   pair. `INFO_DIMENSIONS` on the picture page is built from the raw columns *without* the swap
   (`picture.php:875-880`), so it can already disagree with what is rendered.
2. **Derivative sizing.** `DerivativeImage::get_size()` (`include/derivative.inc.php:430`) computes
   the size rather than storing it, from `SizingParams::compute()`. Templates can read
   `get_size()`, `get_size_htm()`, `get_size_css()`, `get_size_hr()`, `get_scaled_size()`,
   `get_type()`, `is_cached()`. **`get_size_htm()` is only emitted on the `is_cached()` branch** —
   on a cache miss the img carries no width/height at all, only `data-src` and a spinner.
   Sizes: `IMG_SQUARE`…`IMG_4XLARGE` (`include/derivative_std_params.inc.php:14-25`), defaults
   `:272-282`, with `3xlarge`/`4xlarge` disabled by default (`:61`).
3. **Runtime derivative swapping.** `themes/modus/js/photo.autosize.js` is driven by the `RVAS`
   array the template emits (`picture_content_asize.tpl:3-9`). `rvas_choose()` (`:35-100`)
   **rewrites `src`, `width`, `height` and `usemap` on `#theMainImage` in place**, on load and on
   every window resize (`:145`), and removes `usemap` entirely on HiDPI (`:63`). It also writes the
   measured viewport into the `phavsz` cookie (`:31`), which the server reads next request
   (`themes/modus/themeconf.inc.php:402-403`).

Also already competing for clicks on that element: the `<map>`/`<area>` navigation rects generated
per derivative (`picture_content_asize.tpl:32-43`), and a click handler on `#theMainImage` that
navigates prev/next/up by fractional click position when no `usemap` is set
(`photo.autosize.js:148-166`).

No zoom, lightbox or swipe library exists in either theme.

### 5. Plugin extension surface

Both in-repo plugins are the working reference.

**Structure.** `main.inc.php` with the metadata header parsed out of the first 2048 bytes
(`admin/include/plugins.class.php:347`), a `<plugin_id>_maintain` class in `maintain.class.php`
extending `PluginMaintain` (`include/functions_plugins.inc.php:23`), pure helpers in
`include/functions.inc.php` (which is what makes a unit layer possible), event handlers split
across `include/events_public.inc.php` / `events_admin.inc.php`, templates and assets under
`template/`, and an `index.php` stub in every directory.

**Own schema.** `plugins/provenance/maintain.class.php` reads its column definitions from the pure
file (`provenance_album_columns()`, `provenance_image_columns()`), guards every `ALTER` with
`SHOW COLUMNS … LIKE` so `install()` is re-entrant, uses `CREATE TABLE IF NOT EXISTS`, and
delegates `update()` straight to `install()`. It also seeds a key into core's
`picture_informations` config map so its picture-page row is visible on install without a second
step (`:98-123`) — and leaves an existing key alone, so a version bump cannot silently re-enable a
row the administrator turned off.

**Injecting into core templates.** `Template::set_prefilter($handle, $callback, $weight=50)`
(`include/template.class.php:1015-1019`) hands the callback the template *source before
compilation*, so injected text may itself contain Smarty syntax. Anchors are held as constants in
one place — `plugins/provenance/include/functions.inc.php:585-605`,
`plugins/typetags/include/events_public.inc.php:4-5` — and the injection is a `str_replace` around
the anchor. Registration happens from `loc_end_picture` (public) or `loc_begin_admin_page` (admin,
switching on `$page['page']`).

The compile-cache trap is documented at `include/template.class.php:1060-1070`: only the
callback's *name* is hashed into `compile_id`, not its body, so editing a prefilter leaves the
previously compiled template in place until `_data/templates_c/` is cleared.

**Web-service methods.** `PwgServer::run()` fires `trigger_notify('ws_add_methods', array(&$this))`
(`include/ws_core.inc.php:279`). `addMethod($name, $callback, $params, $description, $include_file,
$options)` (`:316`) — the fifth argument makes the implementation file load lazily, so it is never
included on a normal page request. Only three options are read by the dispatcher: `admin_only`
(`:515-518`, `!is_admin()` → `PwgError(401)`), `post_only` (`:510-513` → 405), `hidden`
(`:616-617`). There is **no `webmaster_only`** — a webmaster passes `admin_only`, and methods
documented "Webmaster only" re-check `is_webmaster()` inside the handler.

**CSRF.** `check_pwg_token()` is **never called anywhere under `ws.php`**. WS handlers declare
`'pwg_token' => array()` in the signature (making it required, so the missing-param check fires
first) and compare by hand:

```php
if (get_pwg_token() != $params['pwg_token']) { return new PwgError(403, 'Invalid security token'); }
```

`get_pwg_token()` is `hash_hmac('md5', session_id(), $conf['secret_key'])`
(`include/functions.inc.php:2163-2168`) — per session, stable across it.

**`ws.php` does not include `admin/include/functions.php`.** Every handler needing an admin helper
includes it itself (`include/ws_functions/pwg.tags.php:227, 255, 287, 359, 509`).

**Parameter declaration gotchas** (`ws_core.inc.php:318-350, 529-584`): declaring `'default'`
implicitly ORs in `WS_PARAM_OPTIONAL`; a provided-but-empty-string value is treated as missing;
`'maxValue'` **silently clamps** rather than erroring; `'info'` is documentation only.

**Assets.** Both plugins load CSS/JS from inside the injected `.tpl`, never from PHP, using
`{combine_css}` / `{combine_script id=… load='footer' require='jquery' path=$PLUGIN_PATH|cat:'…'}`
and `{footer_script}` for inline runtime code.

**Relevant core events** (`tools/triggers_list.php`):

| Event | Type | Where |
|---|---|---|
| `loc_end_picture` | notify | `picture.php:1019` — both plugins register their `picture` prefilter here |
| `render_element_content` | change | `picture.php:969` — the filter modus overrides to swap the img markup |
| `picture_pictures_data` | change | `picture.php:620` |
| `loc_begin_admin_page` | notify | `admin.php:350` — generic; `$page['tab']` is **not** set yet, read `$_GET['tab']` |
| `loc_end_picture_modify` | notify | `admin/picture_modify.php` |
| `begin_delete_elements` / `delete_elements` | notify | `admin/include/functions.php:266` / `:348` |
| `delete_tags` | notify | `admin/include/functions.php` |
| `merge_tags` | notify | `include/ws_functions/pwg.tags.php:511` |
| `render_tag_name` / `render_tag_url` | change | ~7 call sites |
| `loc_end_section_init` | notify | `include/section_init.inc.php:707` |

That last one matters for browse-by-person: **`$page['section']` values are hardcoded** in
`include/section_init.inc.php` (`tags` at `:332`, `search`, `favorites`, `recent_pics`, `list`, …)
and `parse_section_url()` (`include/functions_url.inc.php:465`) has no filter. A plugin that wanted
its own `/person/12-jane` URLs would have to post-process `$page` from `loc_end_section_init`, or
reuse the existing `tags` section.

### 6. Fork precedents already recorded

- `docs/agents/decisions/0003-no-post-only-on-ws-methods.md` — plugin WS methods answer to GET as
  well as POST; `pwg_token` is treated as carrying the CSRF guard, and `post_only` was rejected as
  a compatibility break for external callers.
- `0004-unscoped-tag-cache-invalidation-accepted.md` — `UPDATE user_cache SET nb_available_tags =
  NULL` with no `WHERE` is accepted; over-invalidation is always safe.
- `0005-tag-assignment-permission-model.md` — typetags' assignment methods gate on `is_a_guest()`
  plus `pwg_token` and deliberately carry no `admin_only`, because `pwg.images.setInfo` is
  `admin_only` (`ws.php:878`) and using it would have made the feature admin-only. The decision
  explicitly records an **open question**: neither method checks per-image visibility, so a
  logged-in user can tag an image in an album they cannot browse.
- `0014-provenance-is-its-own-plugin.md` — the reasoning for a new plugin over core changes: this
  checkout tracks upstream, so every line in `admin/`, `include/` or `themes/` is a future merge
  conflict. Core surface was held to two `trigger_notify()` calls.
- `docs/agents/research/2026-04-24-picture-page-tag-assignment.md` — prior research on the picture
  page, the same injection point.

### 7. Prior art: existing Piwigo face-tagging extensions

`piwigo.org/ext` has no working text search; the list below was enumerated via the PEM API the
core plugin manager uses (`get_revision_list.php?category_id=12`, 4,698 revision records) and
grepped for face/facial/visage/person/people/portrait/region. Piwigo core has none
([Piwigo#1159](https://github.com/Piwigo/Piwigo/issues/1159)).

**Maintained — and both are metadata-native:**

- **Face Tag** — [eid 1051](https://piwigo.org/ext/index.php?eid=1051), v2.3 (2026-08-16), Piwigo
  16/15, ~3,149 downloads, [source](https://github.com/Charles69-piwigo/face_tag). Viewer only:
  draws face frames on the picture page (Bootstrap Darkroom, Modus, Elegant). Reads regions
  **directly out of the JPEG's XMP** — `lib/face_xmp_extraction.php` walks JPEG APP segments itself
  and parses both **MPReg** and **MWG-RS**. Its two tables hold bookkeeping only; no coordinates in
  the DB.
- **Face Tag Editor** — [eid 1053](https://piwigo.org/ext/index.php?eid=1053), v2.3 (2026-08-18),
  2,062 downloads, [source](https://github.com/Charles69-piwigo/face_tag_editor) (repo created
  2026-08-18, no license file). Create/delete/move/resize frames, rename on double-click, access
  gated to webmaster/admin/`FaceTag` group. Writes back into the image's XMP
  (`lib/metadata_writer.php`): MS RegionInfo + mwg-rs Regions plus `dc:subject`,
  `lr:hierarchicalSubject`, `digiKam:TagsList`, `digiKam:CatalogSets`. Uses **PHP Imagick or
  external ImageMagick, explicitly not exiftool**; v2 switched from template-rebuild to DOM merge
  because the old path silently wiped unrelated XMP (darktable history, Extended XMP GUID,
  ACDSee/Microsoft keywords). Its own documented limits: without ExifTool it cannot rebuild a lost
  Extended XMP block, and it caps the packet at 60,000 bytes for the JPEG APP1 limit. Keeps
  `<image>.original` backups next to the photo with an admin pruning screen — the same shape as
  exiftool's `_original` sidecars.

**Unmaintained:**

- **MugShot** — [eid 910](https://piwigo.org/ext/index.php?eid=910) v2.0.3 (2022-02-12),
  [source](https://github.com/cccraig/MugShot) (MIT, last push 2023-12-13). Group-gated tagging,
  mass update, optional Imagick face crops for future DeepFace training (never shipped). **DB
  only**: `face_tag_positions(image_id, tag_id, top, lft, width, height, image_width,
  image_height)`, names as ordinary Piwigo tags, plus a MySQL trigger on tag delete.
- **facetag** — the original, [eid 845](https://piwigo.org/ext/extension_view.php?eid=845) v0.0.3
  (2017-03-19), incompatible with current Piwigo, 8,403 downloads,
  [source](https://github.com/pommes-frites/piwigo-facetag). Own tables with top/left/width/height,
  no write-back. Has published SQL injection and stored-XSS exploits
  ([42094](https://www.exploit-db.com/exploits/42094),
  [42098](https://www.exploit-db.com/exploits/42098)).
- **Facial** — [eid 1008](https://piwigo.org/ext/index.php?eid=1008), registered but never
  published (0 revisions, 0 downloads), [source](https://github.com/jamestrichardson/facial-plugin).
  Detection against a self-hosted CompreFace server.
- **piwigo-tagthings** — [repo](https://github.com/xbgmsharp/piwigo-tagthings), single commit 2015,
  never listed. Dead.

Related: [forum: Facial Recognition](https://piwigo.org/forum/viewtopic.php?id=27598),
[forum: Face Tag Editor](https://piwigo.org/forum/viewtopic.php?id=34591),
[wiki: Picasa face tags to Piwigo](https://piwigo.org/doc/doku.php?id=user_documentation:tools:picasa_face_tags_to_piwigo).

The split is clean: every abandoned extension kept regions in its own tables; both live ones keep
them in the file. The live pair's weak point is its Imagick write path, which its own author
documents as strictly weaker than exiftool for XMP preservation.

### 8. Metadata standards for face regions

#### MWG Regions — `XMP-mwg-rs`

Namespaces: `mwg-rs` = `http://www.metadataworkinggroup.com/schemas/regions/` (**.com**, though
the group's site was .org), `stArea` = `http://ns.adobe.com/xmp/sType/Area#`, `stDim` =
`http://ns.adobe.com/xap/1.0/sType/Dimensions#`.

```
mwg-rs:Regions -> RegionInfo
  AppliedToDimensions -> Dimensions (stDim:w, stDim:h, stDim:unit="pixel")
  RegionList          -> rdf:Bag of RegionStruct
      Area         required  -> stArea:x, y, w, h, d, unit
      Type         optional  -> Face | Pet | Focus | BarCode
      Name         optional
      Description  optional
      FocusUsage   required if Type=Focus
      BarCodeValue optional
      Extensions   optional
```

Confirmed locally in the container's exiftool source: `%sArea` is `x, y, w, h, d, unit`
(`/usr/share/perl5/Image/ExifTool/XMP.pm:398-407`), referenced by `%sRegionStruct`
(`MWG.pm:417-420`).

**`x,y` is the CENTER of the box, not the top-left corner.** Three independent confirmations:

1. The MWG 2.0 spec's normative "Region Coordinates" section (p.55): *"This is stored as the center
   point, along with a diameter or width and height to match the point, circle or rectangle. The
   center MUST be stored as a normalized value where its range is [0...1]."* Same section: *"A
   Creator or Changer MUST express region coordinates, width and height relative to the stored
   image, prior to the application of the Exif Orientation tag. In other words, the origin of the
   image is the upper left."*
2. ExifTool's own conversion code,
   [convert_regions.config](https://github.com/exiftool/exiftool/blob/master/config_files/convert_regions.config):
   `X => $rect[0] + $rect[2]/2; Y => $rect[1] + $rect[3]/2`.
3. An independent implementation writeup:
   [mamclain Picasa XMP face extractor](https://mamclain.com/?page=Blog_Programing_Python_Picasa_XMP_Face_Extractor).

Also normative: the Creator MUST write `AppliedToDimensions`, and a Consumer MUST compare it
against the image's actual size and SHOULD ignore regions whose ratio changed significantly.
Omitting it is a real interop bug — [digiKam 429219](https://bugs.kde.org/show_bug.cgi?id=429219).
Out-of-bounds handling: a center outside [0..1] SHOULD be ignored; a center inside but a box
extending past SHOULD be **clipped**, not discarded.

Spec erratum: the Face and Pet samples (p.57) use `<mwg-rs:Title>`, which is not in the
RegionStruct field table. The correct element is `<mwg-rs:Name>`. ExifTool implements `Name`.

ExifTool tag names (`exiftool.org/TagNames/MWG.html` now **404s**; docs moved to
[exiftool.sourceforge.net/TagNames/MWG.html](https://exiftool.sourceforge.net/TagNames/MWG.html)):
`RegionInfo` (struct; tag ID `Regions`), `RegionAppliedToDimensionsW/H/Unit` (not list-type — one
per file), `RegionList`, `RegionArea{X,Y,W,H,D,Unit}`, `RegionName`, `RegionType`,
`RegionDescription`, `RegionExtensions`. `RegionRotation` exists in ExifTool but is **not part of
MWG 2.0**. There is **no `RegionRectangle` in MWG** — that name belongs to Microsoft.

The spec itself: metadataworkinggroup.org is dead; canonical archive
[web.archive.org mwg_guidance.pdf](https://web.archive.org/web/20180919181934/http://www.metadataworkinggroup.org/pdf/mwg_guidance.pdf),
live mirror [tagthatphoto S3 copy](https://s3.amazonaws.com/software.tagthatphoto.com/docs/mwg_guidance.pdf).
There is **no formally designated successor body**; the functional successor for regions is IPTC
Extension 1.5's `ImageRegion` (`RegionBoundary` with `RbShape/RbUnit/RbX/RbY/RbW/RbH`), which uses
**top-left** origin.

**Writing.** The documented approach is structure serialization
([struct.html](https://exiftool.sourceforge.net/struct.html)) — read format under `-struct` equals
write format:

```
RegionInfo : {AppliedToDimensions={H=3000,Unit=pixel,W=2233},RegionList=[{Area={H=0.086,Unit=normalized,W=0.1156,X=0.5954,Y=0.155},Name=John Doe,Type=Face},{...}]}
```

A third form exists and is the friendliest from PHP: `exiftool -json=region.json photo.jpg`, which
sidesteps ExifTool's brace/escape rules entirely (the `SourceFile` entry must be edited or removed
when copying to a different image).

**Flattened tags do not work for multi-region data.** They read back as parallel comma lists
associated only by position; plain `-RegionName="A"` **deletes every existing region name** and
writes one. ExifTool's own documentation says *"this is very difficult to do properly using
flattened tags"*. Asked directly how an application should write face regions, Phil Harvey answers
only *"See the Serialization section of the Structured information page"*.

#### Microsoft Photo 1.2 — `XMP-MP`

Namespaces `MP` = `http://ns.microsoft.com/photo/1.2/`, `MPRI` = `…/t/RegionInfo#`, `MPReg` =
`…/t/Region#`. Trap: the current
[Microsoft Learn page](https://learn.microsoft.com/en-us/windows/win32/wic/-wic-people-tagging)
renders these as `https://`; that is a docs-wide link rewrite and it is wrong — every real file
uses `http://`.

Confirmed locally: `%sRegions` is `Rectangle`, `PersonDisplayName`, `PersonEmailDigest`,
`PersonLiveIdCID`, `PersonSourceID` (`/usr/share/perl5/Image/ExifTool/Microsoft.pm:147-160`) — note
`Rectangle` is a **single string**, not typed fields.

Microsoft Learn, verbatim: *"four comma-delimited decimal values… The first two values specify the
top left coordinate… The decimal values must be normalized to 1."* So **top-left origin**,
normalized, no unit field, and **no `AppliedToDimensions` equivalent at all** — nothing records a
reference image size, so a later resize leaves no way to detect staleness. Second documentation
trap: the prose says "height and width" but the actual order is **width, height** (`x, y, w, h`) —
proven by ExifTool's conversion and by Microsoft's own landscape sample
`0.790650, 0.441734, 0.209350, 0.279133`.

ExifTool comment worth knowing (`Microsoft.pm`): the `MP` suffix on `RegionInfoMP` exists *"only to
avoid conflict with XMP-mwg-rs:RegionInfo"* — but the **child flattened tags drop the suffix**,
putting MWG's `RegionName`/`RegionArea*` and MS's `RegionRectangle`/`RegionPerson*` in one flat
namespace. Always qualify writes with `-XMP-mwg-rs:` or `-XMP-MP:`.

Conversion between them: `MWG.X = MS.x + MS.w/2`, `MWG.Y = MS.y + MS.h/2`, w/h unchanged;
`AppliedToDimensions` fabricated going MS→MWG and discarded the other way.

#### Picasa

**Picasa wrote MWG regions, not Microsoft ones** — ExifTool's
[picasa_faces.config](https://github.com/exiftool/exiftool/blob/master/config_files/picasa_faces.config)
describes the target as "MWG region tags… used by Picasa". Embedding was **off by default**
(Picasa 3.9 → Tools > Options > Name Tags > "Store Name Tags in Photo", JPEG only); without it the
data stayed in the proprietary database.

`.picasa.ini` is a per-directory INI with `[Contacts]`/`[Contacts2]` and one section per filename;
faces are `faces=rect64(<hex>),<contact_id>` repeated, **semicolon-separated**, with
`ffffffffffffffff` as the sentinel for a detected-but-unnamed face
([gist](https://gist.github.com/fbuchinger/1073823)). `contacts.xml` maps id → name.

`rect64()`: the hex is a 64-bit integer split into four unsigned 16-bit fields in order **left,
top, right, bottom** — *not* x/y/w/h. Each divided by **65535** (ExifTool's divisor; the
widely-circulated gist says 65536) for 0-1 top-left coordinates. Picasa **omits leading zeros**, so
the string can be shorter than 16 chars. For raw files Picasa wrote regions as if unrotated;
`picasa_faces.config` carries a `RotateRegion()` fixup applied only to raw types.

#### IPTC Extension `PersonInImage`

`XMP-iptcExt:PersonInImage` is a flat list of names with no region at all. Verified writable in
the container. It is what Lightroom-style keyword round-trips survive on when regions do not.

#### Verified in-container capability (2026-08-29)

The DDEV web image's **exiftool 13.25** writes and reads MWG regions with **no custom config** —
XMP carries its own namespace URI, and `mwg-rs` is built into ExifTool, unlike the provenance
plugin's private namespace which needed `exiftool/pwgprov.config`:

```
$ exiftool -RegionAppliedToDimensionsW=400 -RegionAppliedToDimensionsH=300 \
    -RegionAppliedToDimensionsUnit=pixel -RegionName="Jane Doe" -RegionType=Face \
    -RegionAreaX=0.42 -RegionAreaY=0.31 -RegionAreaW=0.12 -RegionAreaH=0.16 \
    -RegionAreaUnit=normalized -XMP-iptcExt:PersonInImage="Jane Doe" test.jpg
    1 image files updated

$ exiftool -G1 -a -struct -XMP-mwg-rs:all -XMP-iptcExt:PersonInImage test.jpg
[XMP-mwg-rs]  Region Info : {AppliedToDimensions={H=300,Unit=pixel,W=400},
                RegionList=[{Area={H=0.16,Unit=normalized,W=0.12,X=0.42,Y=0.31},
                Name=Jane Doe,Type=Face}]}
[XMP-iptcExt] Person In Image : [Jane Doe]
```

(Single-region write via flattened tags, which is safe; multi-region needs the struct or JSON
form. Test image and artifacts were removed.)

### 9. What other applications actually do with regions

| App | Reads? | Writes? | Namespaces | Evidence |
|---|---|---|---|---|
| digiKam | Yes (incl. MS People Tags) | Yes, on demand | mwg-rs **and** MP (MWG wins on conflict) | [KDE UserBase](https://userbase.kde.org/Digikam/Tutorials/Setup_of_digiKam_for_Windows_compatibility), [manual](https://docs.digikam.org/en/setup_application/metadata_settings.html) |
| Lightroom Classic / Bridge | Contested — see below | Yes, on Save Metadata / export | XMP-mwg-rs | [community thread](https://community.adobe.com/t5/lightroom-classic-discussions/face-image-region-data-written-according-to-xmp-mwg-rs-but-not-read-by-lr/td-p/7144723), [People view & Metadata](https://community.adobe.com/questions-675/people-view-metadata-979548) |
| Apple Photos | No | No | DB-only | [photosmeta](https://github.com/RhetTbull/photosmeta), [Apple Communities](https://discussions.apple.com/thread/252374332) |
| Synology Photos | No evidence found | No (community-sourced) | DB-only | [SynoForum](https://www.synoforum.com/threads/where-does-facial-recognition-meta-data-go.13398/) |
| Immich | Yes, opt-in (default off) | **No** | MWG-rs only | [PR #6455](https://github.com/immich-app/immich/pull/6455), [config docs](https://docs.immich.app/install/config-file/) |
| PhotoPrism | Yes, opt-in | No | mwg-rs + MP + ACDSee, `PersonInImage` as name fallback | [#5712](https://github.com/photoprism/photoprism/issues/5712), [#1570](https://github.com/photoprism/photoprism/issues/1570), [xmp.md](https://github.com/photoprism/photoprism-docs/blob/develop/docs/developer-guide/metadata/xmp.md) |
| Nextcloud Memories + recognize / facerecognition | No | No | DB-only | [recognize #761](https://github.com/nextcloud/recognize/issues/761) (open since 2023-03), [facerecognition #321](https://github.com/matiasdelellis/facerecognition/issues/321) (open since 2020-08) |

**digiKam is the reference implementation.** It dual-writes MWG + Microsoft and reads both, but
*"digiKam prioritizes 'MWG regions', any changes you make in Microsoft software will be
DISCARDED."* Writing is gated by one checkbox — Settings > Configure digiKam > Metadata >
*Behavior* > **"Face Tags (including face areas)"** — and triggered by write-to-file / lazy sync,
not on every edit. Face regions are **not** user-remappable in the *Advanced* namespaces tab (that
covers Caption, Color Label, Rating, Tags, Title only), so the dual write and MWG-first read order
are hardcoded. A hierarchical `People/<name>` keyword is created alongside the region. One
asymmetry worth knowing: digiKam **ignores a person tag that carries no region** — an MWG Face
name, MS People Tag or IPTC Person Shown without a rectangle does not appear in the People view
([KDE Bug 432048](https://www.mail-archive.com/kde-bugs-dist@kde.org/msg525429.html)).

**Lightroom Classic's read behaviour is contested and should not be treated as settled.** That it
*writes* `XMP:RegionInfo` (MWG) on Save Metadata to File and on export — unless Export > Metadata >
"Remove Person Info" is checked — is well supported. Whether it *reads* foreign regions is not:
the widely-cited thread title asserts it does not, while johnrellis (Adobe Community Legend) states
in that same thread that LR "correctly imports the region and the name attached to the region" from
Picasa-written files but attaches no keyword, because LR represents named faces using **both** MWG
regions and keywords. No Adobe employee statement exists either way, and no 2025/2026 source
confirms a change. The best-supported reading is "regions are read, but the People view is tied to
keywords"; treat it as unresolved. Face names are apparently **not** written to
`Iptc4xmpExt:PersonInImage` — there is an open feature request asking for exactly that.

**Immich imports but does not export**, and the import is `metadata.faces.import`, **default
false**. Its reader takes exiftool's `RegionInfo` (`AppliedToDimensions` + `RegionList`, each
entry's `Name` and `Area{X,Y,W,H}`) and marks the resulting faces `sourceType = Exif`
(`server/src/services/metadata.service.ts`). It reads **neither** `RegionInfoMP` **nor**
`PersonInImage`. Assets carrying face metadata are skipped by face detection entirely and do not
participate in recognition; grouping is by name. Still actively maintained —
[PR #29333](https://github.com/immich-app/immich/pull/29333) (merged 2026-06-29) fixed
high-precision coordinates arriving as strings and producing NaN inserts. Support for other
dialects (ACDSee) was declined in favour of MWG, with an external converter
([bugfest/exif-companion](https://github.com/bugfest/exif-companion)) as the answer.

**PhotoPrism** reads mwg-rs, Microsoft `MP:RegionInfo` and `acdsee-rs:RegionList` from embedded XMP
and `.xmp` sidecars, plus `Iptc4xmpExt:PersonInImage` as a name fallback, gated by
`PHOTOPRISM_XMP_FACES`. Its own developer guide states the reader "is read-only. It does not write
`.xmp` sidecars back out; round-tripping or generating sidecars is out of scope."

**Synology**: the KB page "What metadata standards does Synology Photos support?" would not render,
so there is **no official statement either way** — record this as no evidence found, not a
documented "no". Community tests consistently show face data staying in the internal Synofoto
PostgreSQL database, with downloaded photos carrying no people metadata and sidecars untouched.

On Immich's missing write-back: it is an
[open discussion opened by a maintainer](https://github.com/immich-app/immich/discussions/15083)
(Jan 2025), and the one implementation
([PR #30704](https://github.com/immich-app/immich/pull/30704), opt-in `metadata.faces.export`,
orientation-aware) was **opened and auto-closed the same day, 2026-08-11, under the repo's
`auto-closed:llm` policy — not on technical grounds**. A reviewer there raised the design point
that matters most for this feature: merge with existing third-party regions rather than overwrite.

Others: **XnView MP** writes MWG but had a real interop bug (`AppliedToDimensions` emitted outside
`RegionInfo`), nominally fixed in 1.7.1, still reported imperfect Apr 2024. **ACDSee** uses
proprietary `XMP-acdsee-rs`. **Capture One** has no named-person recognition. **darktable** has
none in core.

### 10. UI/UX conventions of market leaders

| | Box creation | Input position | Autocomplete | Boxes visible | Keys | Touch |
|---|---|---|---|---|---|---|
| **Facebook** | click-to-place fixed box (drag as alt.) | popup anchored on box | list pre-populated by recency, filters as you type | hover / in tagging mode | type + click | full mobile flow |
| **Google Photos** | none — auto only | group header / info panel | choose from suggestions (also the merge mechanism) | face chips in info panel | — | primary platform |
| **Apple Photos** | click-to-place circle, drag to reposition (macOS only) | under the circle | inline names as you type | `Show/Hide Face Names` toggle | Return | iOS names only, no add |
| **Immich** | default 112×112 box spawned, then drag/resize | floating, repositions to minimize overlap | searchable picker, auto-focused | hover, both directions, + dimming | Esc | none |
| **PhotoPrism** | drag-to-draw, 16 px min, corner handles | on-canvas confirm/cancel; sidebar for naming | free text + reject-to-relearn | markers in lightbox | Enter | — |
| **digiKam** | **drag-to-draw**, or Ctrl+drag | "Who Is This?" overlay on the box | tag-tree dropdown + free text | toggle button in preview | Enter commit, Esc cancel | — |

**Facebook.** The best source is its own patent,
[US7945653B2](https://patents.google.com/patent/US7945653B2/en) (Zuckerberg/Sittig/Marlette, filed
Oct 2006), which describes the interaction almost exactly: the user clicks a point and *"the region
selection component places a border around the selected region"*, or may *"click on a point with
the cursor and drag the cursor to another point"*; region size *"may be fixed, may be determined by
the user, or may be automatically determined"*. Then *"a tag list pops-up upon clicking on the
selected region… includes a text entry window and a list of previously used tags"*, and *"As text
is entered in the text entry window, the list of previously used tags may be culled to include only
those that match."* So the list is **visible before typing**, recency-ordered, and narrows on
input — not an empty box. Tagging is a **mode** ("Tag Photo" → click → name → repeat → "Done
Tagging", max 50 tags). Face grouping with "who is this?" shipped Oct 2010, recognition-filled Tag
Suggestions Dec 2010, retired Sept 2019
([Meta](https://about.fb.com/news/2019/09/update-face-recognition/)). Weakly sourced: that boxes
are hover-only outside tagging mode — every walkthrough says so, no first-party statement found.
Microsoft's [EP2318911A1](https://patents.google.com/patent/EP2318911A1/en) is the same three-part
pattern with a "That's Me" entry pinned at the top of the list.

**Google Photos** ([help](https://support.google.com/photos/answer/6128838)). No manual box
drawing, ever; faces are clustered and named at the *group* level. Merging is done by giving two
groups the same name and is **irreversible**. The per-photo "Add faces" only offers faces the
detector already found but failed to group — you cannot select an area without a face and tag it.

**Apple Photos** is the one mainstream product with a genuine manual add-a-face, and only on macOS
([guide](https://support.apple.com/guide/photos/find-and-name-people-and-pets-phtad9d981ab/mac)).
`View > Show Face Names`, click Add Faces, a **circle** appears which you drag onto the face, then
type in the Name field under it and press Return, choosing from names that appear as you type. A
persistent toggle rather than hover. Control-click gives "This is Not [name]" and Rename. On iOS
you can only name a detected face; Mac-added faces sync down.

**Immich** — the actual component is
[FaceEditor.svelte](https://github.com/immich-app/immich/blob/main/web/src/lib/components/asset-viewer/face-editor/FaceEditor.svelte).
Entering edit mode spawns a **112×112 px** box at a fixed offset which the user drags/resizes
(Fabric.js, circular corner handles, `lockRotation: true`). The person picker is **floating and
collision-avoiding** — it tests four candidate positions and picks the one overlapping the face
rectangle least, which is the one refinement nobody else has. The search input auto-focuses; a
**Create Person** button appears when nothing matches; selection raises a confirmation dialog.
**Escape** closes; no arrow-key navigation, no Enter-to-confirm, no touch handling. Shipped
[v1.127.0](https://github.com/immich-app/immich/releases/tag/v1.127.0) (Feb 2025). Boxes are
**hover-only bidirectionally**: hovering a person thumbnail draws its box, and
[PR #26667](https://github.com/immich-app/immich/pull/26667) added the reverse so you can sweep the
photo to check everyone is tagged; [#27401](https://github.com/immich-app/immich/pull/27401)
highlights the matching thumbnail, [#27402](https://github.com/immich-app/immich/pull/27402) dims
outside the hovered box. Known gap: naming a person *elsewhere* has **no autocomplete**, causing
duplicate spellings ([#26946](https://github.com/immich-app/immich/discussions/26946)). Scale
caveat from PR review: the picker loads people in batches of 250 assuming the target is in the top
10-20.

**PhotoPrism** ([docs](https://docs.photoprism.app/user-guide/organize/people/)). Historically no
manual box; naming happens in the People tab or the photo's edit dialog, with the full-screen
viewer's Info Sidebar as *"the only place where you can manually mark a face that PhotoPrism
missed"*. Rejecting a match lowers that face's similarity threshold in the background. Newer and
closest to the interaction asked about: [PR #5505](https://github.com/photoprism/photoprism/pull/5505)
(merged to develop 2026-04-27) adds **drag-to-draw** on the PhotoSwipe viewport with a **16 px
minimum**, corner handles, on-canvas confirm/cancel, and named markers rendered in an accent colour
and **read-only by default**, renaming gated behind an explicit "eject" affordance.

**digiKam** ([People View docs](https://docs.digikam.org/en/left_sidebar/people_view.html)). True
**drag-to-draw**: *"draw a rectangle around the face while holding the left mouse button followed
by entering the person's name and pressing Enter"*, via the Add a Face Tag icon, the preview
context menu, or **Ctrl+drag**. The overlay is labelled **"Who Is This?"** and accepts free text
(creating a tag) or a hierarchical tag-tree dropdown — a combobox, not a flat list. Enter commits,
Esc exits. Boxes are toggled by a button in the preview corner, not hover. Confirm/reject/delete
affordances appear **on hover over unconfirmed faces only**; once confirmed, *"digiKam only shows
the name without the buttons"*.

**Cross-product conventions:**

1. **Drag-to-draw is the modern convention** where detection is absent or fallible (digiKam,
   PhotoPrism, Immich-with-adjustment); only Facebook and Apple use click-to-place-fixed.
2. **The input is anchored to the box, not a sidebar**, in every product that has manual boxes.
   Immich's four-candidate collision-avoidance is the only solution anyone ships for "the input
   covers the face you are looking at".
3. **Enter commits, Esc cancels** is universal where documented. **Arrow-key list navigation is
   documented nowhere in any of the six** — a real gap against generic autocomplete guidance
   ([uxpatterns.dev](https://uxpatterns.dev/patterns/forms/autocomplete)).
4. **A create-new-person escape hatch inside the picker** exists in Immich, digiKam and PhotoPrism;
   its absence in Google Photos' per-photo flow is exactly where users complain.
5. **Confirmed and unconfirmed faces get different affordances** — digiKam's
   hover-buttons-on-unconfirmed and PhotoPrism's read-only-until-eject both guard against
   overwriting correct data.
6. **Hover-reveal beats always-on** for existing boxes; Immich's outside-the-box dimming makes
   verification scannable.

## Code References

### Tags
- `install/piwigo_structure-mysql.sql:360-368` — `piwigo_tags` schema (`id` is a smallint)
- `install/piwigo_structure-mysql.sql:205-210` — `piwigo_image_tag`, composite PK, no other columns
- `install/piwigo_structure-mysql.sql:217-251` — `piwigo_images`, incl. `width`, `height`, `coi`, `rotation`
- `include/functions_tag.inc.php:40` — `get_available_tags()`, permission-filtered + cached
- `include/functions_html.inc.php:242` — `tag_alpha_compare()`, the canonical sort
- `admin/include/functions.php:1779` — `set_tags_of()`, the single write path
- `admin/include/functions.php:1709` — `tag_id_from_tag_name()`, three-stage find-or-create
- `admin/include/functions.php:2874` — `get_tag_ids()` and the `~~id~~` convention
- `admin/include/functions.php:2865-2872` — that convention documented
- `admin/include/functions.php:430` — `get_orphan_tags()`, the 1-day grace window

### Tag UI
- `admin/themes/default/js/LocalStorageCache.js:259-297` — `TagsCache`, the bulk-fetch + Selectize wiring
- `admin/themes/default/template/picture_modify.tpl:25-33, 197-202` — the whole tag widget
- `admin/themes/default/template/batch_manager_global.tpl:472-487` — add (`data-create`) vs delete
- `themes/default/js/plugins/selectize.min.js` — Selectize 0.11.2, vendored
- `themes/default/template/picture.tpl:210-217` — read-only tag row on the picture page
- `admin/themes/default/js/tags.js:180, 315, 345, 399, 695, 751` — every tag mutation, all over `ws.php`

### Rectangle on a photo (existing precedent)
- `admin/picture_coi.php:26-35` — normalized rect encoded into `images.coi`
- `admin/themes/default/template/picture_coi.tpl` — Jcrop wiring, `to_coi`/`from_coi`
- `include/derivative_params.inc.php:56, 67` — `char_to_fraction()` / `fraction_to_char()`
- `include/derivative_params.inc.php:118, 152` — where `coi` is consumed
- `themes/default/js/plugins/jquery.Jcrop.min.js` — Jcrop 0.9.12, vendored
- `admin/include/add_core_tabs.inc.php:128` — the "Center of interest" photo tab

### Display pipeline
- `picture.php:967-974` — `render_element_content`, the filter that produces the `<img>`
- `themes/modus/themeconf.inc.php:369, 486-488` — modus overriding it at priority NEUTRAL-1
- `themes/modus/template/picture_content_asize.tpl:22` — the actual `#theMainImage` markup
- `themes/default/template/picture.tpl:131-141` — `#theImageAndInfos` / `#theImage` containers
- `themes/modus/js/photo.autosize.js:35-100, 145` — runtime src/width/height rewriting
- `themes/modus/js/photo.autosize.js:148-166` — the existing click-to-navigate handler on the img
- `include/derivative.inc.php:74-92` — rotation code 0..3 and the width/height swap
- `include/derivative.inc.php:430-483` — `get_size()`, `get_size_htm()`, `get_scaled_size()`
- `include/derivative_std_params.inc.php:14-25, 61, 272-282` — sizes, defaults, disabled types
- `picture.php:875-880` — `INFO_DIMENSIONS` built from raw columns without the rotation swap

### Plugin surface
- `include/template.class.php:1015-1019` — `set_prefilter()`
- `include/template.class.php:1060-1070` — the compile-cache trap
- `include/ws_core.inc.php:279` — `ws_add_methods`
- `include/ws_core.inc.php:316-358` — `addMethod()` signature and normalization
- `include/ws_core.inc.php:501-609` — `invoke()`: post_only, admin_only, param validation
- `include/functions.inc.php:2163-2168` — `get_pwg_token()`
- `include/section_init.inc.php:332, 707` — hardcoded `tags` section; `loc_end_section_init`
- `plugins/typetags/include/events_public.inc.php:4-5, 170-190` — anchors + picture-page injection
- `plugins/typetags/main.inc.php:67-115` — plugin-registered WS methods, incl. one without `admin_only`
- `plugins/provenance/maintain.class.php` — re-entrant schema install, core `$conf` key seeding
- `plugins/provenance/include/exiftool.inc.php:88, 163, 205` — write-back, locking, argfiles
- `plugins/provenance/exiftool/pwgprov.config` — the custom-namespace pattern (not needed for mwg-rs)

### Decisions
- `docs/agents/decisions/0003-no-post-only-on-ws-methods.md`
- `docs/agents/decisions/0005-tag-assignment-permission-model.md` — and its open visibility question
- `docs/agents/decisions/0014-provenance-is-its-own-plugin.md`
- `docs/agents/research/2026-04-24-picture-page-tag-assignment.md`

## Architecture Documentation

**Where a region could live.** Three shapes are represented in the wild and one is already in this
repo:

- Region rows in the plugin's own table, names as ordinary Piwigo tags — MugShot's shape, and the
  only one that keeps `piwigo_tags`' existing browse/count/permission machinery for free. Costs:
  `tags.id` is a smallint, `image_tag`'s composite PK forbids the same person twice on one image,
  and person names would share a namespace with subject tags.
- A separate `persons` table plus a region table — full control, no reuse of tag browsing,
  permalinks and section routing.
- Regions only in the file's XMP, database holding bookkeeping — the shape both *maintained* Piwigo
  extensions chose, and the one that makes the data portable to digiKam/Lightroom.

These are not exclusive: digiKam holds regions in its DB and writes them to the file on demand,
which is also the shape `plugins/provenance` already implements for its own fields.

**Coordinate transforms that would have to be composed.** Region (normalized, MWG center-origin,
pre-Exif-orientation) → apply `images.rotation` (code 0..3, odd codes swap w/h,
`derivative.inc.php:74-92`) → map onto the derivative currently displayed
(`DerivativeImage::get_size()`, computed not stored) → map onto the element's *rendered* box, which
modus rewrites on load and on every resize (`photo.autosize.js:35-100, 145`). The
`AppliedToDimensions` the MWG spec requires is exactly the guard against step 1 having gone stale.

**Existing extension seams if the marker were added to core screens rather than a new page:**
`loc_end_picture` + a `picture` prefilter for the public photo page (both plugins already do it);
`loc_begin_admin_page` + a `picture_modify` prefilter for the admin photo screen; and
`admin/themes/default/js/batchManagerUnit.js:380-386`, which already merges plugin-declared extra
keys into the per-photo `pwg.images.setInfo` save.

**Permission shape available.** `admin_only` is the only gate the WS dispatcher enforces, and it
resolves through `is_admin()`, so a webmaster passes it and there is no `webmaster_only`. Anything
open to non-admins follows typetags' pattern — `is_a_guest()` plus a hand-checked `pwg_token` —
which decision 0005 records as deliberate, along with the unanswered per-image-visibility question
that pattern leaves open.

## Open Questions — answered 2026-08-30

These were put to the user as a numbered list with three ranked options each; the answers below
are the ones chosen. They are decisions of direction recorded here, not yet implemented, and not
yet written up as `docs/agents/decisions/` files.

1. **Where do the regions live? → File-only.** Regions are stored in the image's XMP; the database
   holds only bookkeeping. This is what both *maintained* Piwigo extensions do (Face Tag, Face Tag
   Editor) and it makes the data portable to digiKam and Lightroom with no export step. It is not
   the shape `plugins/provenance` uses for its own fields, which keeps columns in the DB and treats
   the file as an export target.

2. **Persons: own table.** A `persons` table plus a region table, not rows in `piwigo_tags`.
   `tags.id` is a `smallint` (65,535 ceiling), `piwigo_image_tag`'s `PRIMARY KEY (image_id,
   tag_id)` forbids the same person appearing twice in one image, and persons need fields tags do
   not have. The cost is that tag browsing, counting, permalinks, permission filtering and the
   Batch Manager are not inherited and would need their own equivalents.

3. **Who may tag? Any logged-in non-guest, plus a per-image visibility check.** Follows decision
   0005's precedent on *which users*, and closes the question that decision explicitly left open on
   *which images* — neither typetags method checks whether the caller can actually see the image.
   Faces are personal data, so the gap matters more here than it does for colored tags.

4. **Public page and admin page, both.** Not staged. Both surfaces get the marker UI.

5. **Browse-by-person: yes, reusing the existing `tags` section.** No new routing. `$page['section']`
   is hardcoded in `include/section_init.inc.php` and the only extension point is post-processing
   `$page` from `loc_end_section_init`; a real `/person/12-jane` section is deferred.

6. **Write-back format: MWG regions + `XMP-iptcExt:PersonInImage`.** MWG is what every reader that
   reads anything reads. PersonInImage costs nothing extra and survives readers that ignore regions
   (it is what Lightroom-style keyword round-trips rely on). Microsoft `MP:RegionInfo` is **not**
   written — digiKam dual-writes it, but it records no `AppliedToDimensions` and adds surface for
   one legacy reader.

7. **Merge, never replace.** Read the existing `RegionList`, merge the new regions into it, write
   the whole struct back. This is the review point that closed Immich's write-back PR and the
   reason the Piwigo Face Tag Editor rewrote its writer from template-rebuild to DOM merge. It
   requires the struct or `-json=` form — flattened tags cannot express it, and a plain
   `-RegionName="A"` deletes every existing region name.

8. **No detection.** Manual boxes only. The click-a-face-then-name-it interaction is the manual
   path all six surveyed products keep as a fallback; no ML dependency and no false-positive UX to
   design. Ingesting regions other tools already wrote is not part of this either.

9. **Derivative unknowns deferred.** Face crops for a people page (whether to reuse `IMG_CUSTOM`)
   and what happens to stored regions when an administrator rotates a photo or changes
   `images.coi` are recorded as follow-on concerns, not resolved here.

### Consequences these answers create

Three follow directly from the combination and are worth stating before any planning starts.

- **1b + 5a: browse-by-person needs an index that file-only storage does not provide.** Reusing the
  `tags` section means resolving "which images have person N" as a SQL query, but the regions —
  and therefore the person-to-image association — live in XMP. Either the `persons`/region tables
  from answer 2 carry a derived image index kept in sync with the files, or every browse parses
  files. This is the one place where "file-only" and "browse-by-person" pull against each other,
  and it is unresolved.
- **4c: two coordinate implementations land at once.** The admin photo screen has Jcrop available
  and a static image; the public picture page has modus rewriting `src`, `width` and `height` on
  `#theMainImage` on load and on every resize (`themes/modus/js/photo.autosize.js:35-100, 145`),
  plus an `<area>` map and a click-to-navigate handler already bound to that element. Both must
  compose the same three transforms (rotation code, derivative size, rendered box) correctly.
- **7a + 1b: the file is the only copy.** With regions stored nowhere else, a failed or partial
  merge loses data outright. exiftool's `_original` sidecars sit beside the image in `upload/` or
  `galleries/` and are the only pre-write bytes — the same property already documented for
  `plugins/provenance` in CLAUDE.md.
