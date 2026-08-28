---
date: 2026-04-24T15:43:29+00:00
git_commit: e01ef19da76ee94b633acf9cbdf38a23dc9f1bc4
branch: master
topic: "Picture page: inline colored tag assignment UI"
tags: [research, codebase, tags, typetags, picture-page, colored-tags]
status: complete
---

# Research: Picture Page Inline Colored Tag Assignment

## Research Question

On the picture page (`picture.php?/70/category/1`), beneath the grey info box, display all available colored tags that are NOT currently assigned to the shown image. Clicking an unassigned tag assigns it to the image: the tag moves into the "Schlagworte" section inside the grey box and disappears from the unassigned list.

## Summary

The feature requires coordinating three existing systems: (1) Piwigo's core tag infrastructure (tag-to-image associations via `IMAGE_TAG_TABLE`), (2) the Colored Tags / typetags plugin (color rendering via `TYPETAGS_TABLE` and `TAGS_TABLE.id_typetags`), and (3) the picture page template (`picture.tpl`) and its PHP controller (`picture.php`). All necessary building blocks exist: web service methods for tag assignment, functions to query all/assigned tags, and the plugin's color rendering pipeline. The work centers on wiring these together with a new UI component below the info box.

## Detailed Findings

### 1. Picture Page: How Tags Are Currently Displayed

**Controller:** `picture.php:897-922`

Tags for the current image are fetched via:
```php
$tags = get_common_tags( array($page['image_id']), -1);
```

Each tag is appended to the Smarty `related_tags` variable with `URL` and `U_TAG_IMAGE` keys.

**Template:** `themes/default/template/picture.tpl:210-217`

```smarty
{if ($display_info.tags and isset($related_tags))}
<div id="Tags" class="imageInfo">
    <dt>{'Tags'|@translate}</dt>
    <dd>
    {foreach from=$related_tags item=tag name=tag_loop}
      {if !$smarty.foreach.tag_loop.first}, {/if}
      <a href="{$tag.URL}">{$tag.name}</a>
    {/foreach}
    </dd>
</div>
{/if}
```

The `{$tag.name}` value has already been processed through `trigger_change('render_tag_name', ...)` in `get_common_tags()` (`include/functions_tag.inc.php:302`), so when the typetags plugin's `show_all` config is enabled, the tag name is already wrapped in a colored `<span>` badge.

### 2. Colored Tags Plugin (typetags): How Colors Work

**Database schema** (`plugins/typetags/maintain.class.php:35-43`):
- `TYPETAGS_TABLE` (`piwigo_typetags`): `id`, `name`, `color` (hex string like `#FF0000`)
- `TAGS_TABLE` gets an added column: `id_typetags SMALLINT(5) DEFAULT NULL` -- FK to `TYPETAGS_TABLE.id`

**Color rendering** (`plugins/typetags/include/events_public.inc.php:7-88`):

The function `typetags_render($tag_name, $tag)` is hooked to `render_tag_name` (priority 0). It:
1. Loads all colors from `TYPETAGS_TABLE` into `$typetags_cache['colors']`
2. Loads tag-to-color mapping from `TAGS_TABLE JOIN TYPETAGS_TABLE` into `$typetags_cache['color_of_tag']`
3. Looks up the color by `id_typetags`, then by tag `id`, then by tag `name`
4. Wraps the tag name in `<span style="background-color:...;color:...;padding:2px 8px;border-radius:12px;display:inline-block;">tag_name</span>`

**Activation:** `plugins/typetags/main.inc.php:44-47` -- Only active when `$conf['TypeTags']['show_all']` is true AND `script_basename() != 'tags'`.

**Commented-out picture page handler:** `main.inc.php:37-41` and `events_public.inc.php:93-122` -- There is an old, commented-out `typetags_picture()` function that used to color tags on the picture page by injecting CSS color into the URL attribute. This approach has been superseded by the `render_tag_name` hook which handles coloring globally.

### 3. Core Tag Functions Relevant to This Feature

**Getting all available tags:** `include/functions_tag.inc.php:40-118`
```php
function get_available_tags($tag_ids=array())
```
Returns all tags visible to the current user (respecting permissions), each with `id`, `name`, `counter`, `url_name`, and crucially `id_typetags` (since it does `SELECT *` from `TAGS_TABLE`).

**Getting tags for current image:** `include/functions_tag.inc.php:269-307`
```php
function get_common_tags($items, $max_tags, $excluded_tag_ids=array())
```
Returns tags linked to given image IDs via `IMAGE_TAG_TABLE`, with `SELECT t.*` so `id_typetags` is included.

**Adding tags to an image:** `admin/include/functions.php:1613-1654`
```php
function add_tags($tags, $images)
```
Takes array of tag_ids and array of image_ids. Handles deduplication internally.

**Setting (replacing) tags for an image:** `admin/include/functions.php:1602-1605`
```php
function set_tags($tags, $image_id)
```
Replaces all tags for a single image.

### 4. Web Service API for Tag Assignment

**`pwg.images.setInfo`** (`ws.php:851-875`, handler in `include/ws_functions/pwg.images.php:2568-2730`)

- Admin-only method
- Accepts `image_id` and `tag_ids` (comma-separated IDs)
- `multiple_value_mode` parameter: `"append"` (add to existing) or `"replace"` (overwrite)
- Requires `pwg_token` for CSRF protection (optional parameter, but validated if present)

This is the primary API endpoint for assigning tags to images from JavaScript.

**`pwg.tags.getList`** (`include/ws_functions/pwg.tags.php:15-46`)

- Returns all tags available to the connected user, with `id`, `name`, `counter`, `url`
- Can sort by counter

**`pwg.tags.getAdminList`** (`include/ws_functions/pwg.tags.php:56-65`)

- Admin-only, returns all tags including orphans

### 5. Identifying Colored vs Non-Colored Tags

To show only colored tags that are unassigned, the implementation needs to:

1. Get all tags that have a color (`TAGS_TABLE.id_typetags IS NOT NULL`)
2. Get tags already assigned to the current image (`get_common_tags()` result)
3. Subtract assigned from all-colored to get unassigned colored tags

**Query to get all colored tags:**
```sql
SELECT t.id, t.name, t.url_name, t.id_typetags, tt.color
FROM piwigo_tags AS t
INNER JOIN piwigo_typetags AS tt ON t.id_typetags = tt.id
WHERE t.id_typetags IS NOT NULL
```

This pattern is already used in `events_public.inc.php:37-44` and `events_admin.inc.php:80-88`.

### 6. Permission and Security Considerations

- `pwg.images.setInfo` is admin-only, so the unassigned tag list and click-to-assign functionality should only be visible to admins
- `picture.php:771` already checks `is_admin()` for showing admin links
- CSRF protection: `pwg.images.setInfo` accepts `pwg_token`; the token is available via `get_pwg_token()` in PHP and can be passed to JavaScript
- The Piwigo WS endpoint is at `ws.php` and accepts POST requests with `method` parameter

### 7. Template Extension Points

**Plugin hooks available on the picture page:**

- `loc_begin_picture` (`picture.php:129`) -- early, before data loading
- `loc_end_picture` (`picture.php:1019`) -- late, after all includes, before output
- `picture_pictures_data` (`picture.php:621`) -- filter hook, can modify picture data array
- Template prefilters via `$template->set_prefilter('picture', ...)` -- can inject HTML/JS into picture.tpl

**Existing plugin pattern:** The typetags plugin already uses `set_prefilter` for admin pages (e.g., `typetags_admin_prefilter` and `typetags_photo_prefilter` in `events_admin.inc.php:53-64, 120-124`).

**Template variable `$PLUGIN_PICTURE_BEFORE`:** `picture.tpl:9` -- `{if !empty($PLUGIN_PICTURE_BEFORE)}{$PLUGIN_PICTURE_BEFORE}{/if}` -- renders before the image.

**Info box location:** The grey info box is `<dl id="standard" class="imageInfoTable">` ending around line 270 of `picture.tpl`. Content below it (albums, visits) is still inside `<div id="imageInfos">`. The unassigned tags list would go after the `</dl>` closing tag of `#standard` or after the entire `#imageInfos` div.

### 8. Existing JavaScript Patterns for Tag Management

**Admin batch manager** (`admin/themes/default/js/batchManagerUnit.js:360-370`):
```javascript
let ajax_data = {
  method: 'pwg.images.setInfo',
  image_id: pictureId,
  tag_ids: tagsStr,
  multiple_value_mode: 'replace',
  // ...
};
```
Uses jQuery AJAX to call `ws.php` with `format=json`.

**Standard WS call pattern:**
```javascript
jQuery.ajax({
  url: 'ws.php?format=json',
  type: 'POST',
  data: { method: 'pwg.images.setInfo', ... },
  success: function(data) { ... }
});
```

### 9. Data Flow for the Feature

The following data is needed at render time:

1. **Current image ID** -- available as `$page['image_id']` in PHP, can be passed to JS via template
2. **Currently assigned tag IDs** -- from `get_common_tags()` result, already in `$related_tags` template var
3. **All colored tags** -- query `TAGS_TABLE JOIN TYPETAGS_TABLE`
4. **Unassigned colored tags** -- difference of (3) minus (2)
5. **Color information for each tag** -- from `TYPETAGS_TABLE.color`
6. **PWG token** -- from `get_pwg_token()` for CSRF protection

On click, the JS would:
1. Call `pwg.images.setInfo` with `multiple_value_mode=append` and `tag_ids=<clicked_tag_id>`
2. On success: move the tag badge from unassigned list into the "Schlagworte" `<dd>`, remove from unassigned list
3. Alternatively: reload the page to let server-side rendering handle the updated state

### 10. Relevant CSS for Colored Tag Badges

The typetags plugin renders badges with inline styles (`events_public.inc.php:73-74`):
```
background-color:{color};color:{contrast};padding:2px 8px;border-radius:12px;display:inline-block;
```

The `get_color_text($color)` function (`include/functions.inc.php:4-27`) determines black or white text based on luminance threshold of 0.45.

## Code References

- `picture.php:897-922` -- Tags loading and template assignment for current image
- `picture.php:771` -- `is_admin()` check for admin features
- `picture.php:1019` -- `loc_end_picture` hook
- `themes/default/template/picture.tpl:210-217` -- Tags display in info sidebar
- `include/functions_tag.inc.php:40-118` -- `get_available_tags()` -- all user-visible tags
- `include/functions_tag.inc.php:269-307` -- `get_common_tags()` -- tags for specific images
- `admin/include/functions.php:1613-1654` -- `add_tags()` function
- `admin/include/functions.php:1602-1605` -- `set_tags()` function
- `ws.php:851-875` -- `pwg.images.setInfo` registration with `tag_ids` and `multiple_value_mode`
- `include/ws_functions/pwg.images.php:2568-2730` -- `ws_images_setInfo()` implementation
- `include/ws_functions/pwg.tags.php:15-46` -- `ws_tags_getList()` -- all available tags via API
- `plugins/typetags/main.inc.php:44-47` -- `render_tag_name` hook registration
- `plugins/typetags/include/events_public.inc.php:7-88` -- `typetags_render()` -- color badge rendering
- `plugins/typetags/include/events_public.inc.php:37-44` -- Query pattern for colored tag lookup
- `plugins/typetags/include/events_admin.inc.php:69-118` -- `typetags_admin_photo()` -- admin color CSS injection pattern
- `plugins/typetags/include/functions.inc.php:4-27` -- `get_color_text()` -- contrast color calculation
- `plugins/typetags/maintain.class.php:29-43` -- Database schema (TYPETAGS_TABLE, id_typetags column)

## Architecture Documentation

### Tag Data Model
```
TAGS_TABLE (piwigo_tags)
  id, name, url_name, id_typetags (FK to TYPETAGS_TABLE, nullable)

TYPETAGS_TABLE (piwigo_typetags)  [plugin-managed]
  id, name, color (hex string)

IMAGE_TAG_TABLE (piwigo_image_tag)
  image_id, tag_id
```

### Rendering Pipeline
```
get_common_tags()
  -> SELECT t.* FROM image_tag JOIN tags
  -> trigger_change('render_tag_name', $row['name'], $row)  [per tag]
     -> typetags_render()  [if show_all enabled]
        -> wraps name in colored <span> badge
  -> returns array with rendered names
```

### Plugin Extension Pattern
```
main.inc.php
  -> add_event_handler('render_tag_name', 'typetags_render')   [public pages]
  -> add_event_handler('loc_begin_admin_page', 'typetags_admin_photo')  [admin]
  -> add_event_handler('ws_add_methods', 'typetags_add_methods')  [API]
```

## Design Decisions (Resolved)

1. **Tag removal:** Yes -- assigned tags in the "Schlagworte" section get an "x" button. Clicking it un-assigns the tag from the image and moves it back to the unassigned list below.
2. **Permission level:** All logged-in users (not guests). Note: `pwg.images.setInfo` is admin-only (`ws.php:878`), so the implementation needs either a custom WS method in the typetags plugin or a dedicated lightweight endpoint that checks `!is_a_guest()` and handles tag add/remove for the current image.
3. **Update mode:** Dynamic JS -- no page reload. Clicking an unassigned tag moves it into the assigned section; clicking "x" on an assigned tag moves it back. Both trigger an AJAX call to persist the change.
4. **Scope:** Only colored tags (those with `id_typetags IS NOT NULL`) appear in the unassigned list. Non-colored tags are not affected.

### Permission Constraint: `pwg.images.setInfo` is Admin-Only

The existing `pwg.images.setInfo` WS method is registered with `admin_only=>true` (`ws.php:878`). Since the feature targets all logged-in users, the implementation has two options:

- **Option A:** Register a new WS method (e.g., `typetags.image.setTag` / `typetags.image.removeTag`) in the typetags plugin via `ws_add_methods` hook, without `admin_only` restriction but with `!is_a_guest()` check inside the handler. This method would only handle tag assignment/removal for a single image.
- **Option B:** Use a custom AJAX endpoint in the plugin (e.g., `plugins/typetags/ajax.php`) that validates the session and handles the tag operation.

Option A is the idiomatic Piwigo approach -- the plugin already registers WS methods via `typetags_add_methods()` in `main.inc.php:65-92`.

### Tag Removal Mechanism

To remove a single tag from an image without affecting other tags:
1. Get current tag IDs for the image via `get_image_tag_ids()` (`admin/include/functions.php:1833-1861`)
2. Remove the target tag ID from the list
3. Call `set_tags()` with the filtered list

Alternatively, a direct DELETE query:
```sql
DELETE FROM IMAGE_TAG_TABLE WHERE image_id = ? AND tag_id = ?
```
followed by `invalidate_user_cache_nb_tags()` (`admin/include/functions.php:2262-2271`).
