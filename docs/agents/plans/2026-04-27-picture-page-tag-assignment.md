---
date: 2026-04-27T18:49:37+00:00
git_commit: e01ef19da76ee94b633acf9cbdf38a23dc9f1bc4
branch: master
topic: "Picture page: inline colored tag assignment UI"
tags: [plan, typetags, picture-page, colored-tags, tag-assignment]
status: draft
---

# Picture Page: Inline Colored Tag Assignment — Implementation Plan

## Overview

Add a UI below the picture page info box that shows all colored tags not currently assigned to the displayed image. Clicking an unassigned tag assigns it to the image (moves it into the assigned tags section). Assigned colored tags get an "x" button to un-assign them (moves back to unassigned list). All operations are AJAX-based, no page reload. Available to all logged-in users (not guests).

## Current State Analysis

- `picture.php:897-912` loads assigned tags via `get_common_tags()`, assigns to `related_tags` Smarty var
- `picture.tpl:210-217` renders assigned tags as `<a href="{$tag.URL}">{$tag.name}</a>` inside `<div id="Tags">`
- Tag names are already rendered through `render_tag_name` hook, which the typetags plugin uses to wrap names in colored `<span>` badges (`events_public.inc.php:7-88`)
- The typetags plugin registers WS methods via `ws_add_methods` hook in `main.inc.php:65-92`
- `ws.php` does NOT include admin functions (`admin/include/functions.php`), so WS handlers must use direct SQL
- jQuery is available on picture page via `require='jquery'` in `{footer_script}` blocks
- The info box closes at `picture.tpl:301` (`</dl>`), followed by the metadata section at line 303

### Key Discoveries:
- `get_common_tags()` (`functions_tag.inc.php:269-307`) returns `t.*` including `id_typetags` — we can identify colored tags
- `is_a_guest()` (`functions_user.inc.php:1560`) checks user status for permission gating
- WS framework checks `admin_only` in `ws_core.inc.php:515` — omitting this option allows any authenticated user
- `get_pwg_token()` (`functions.inc.php:2163`) provides CSRF tokens; must be passed to JS via template variable
- `typetags_render()` in `events_public.inc.php:7-88` builds colored badge HTML with inline styles — we can reuse it for unassigned tag rendering
- Template prefilter pattern already established in plugin: `events_admin.inc.php:53-64` (admin pages)

## Desired End State

Below the grey info box on the picture page, logged-in users see a row of colored tag badges representing tags NOT yet assigned to the current image. These badges appear slightly transparent with a "+" indicator, signaling they are clickable. Clicking one:
1. Sends an AJAX request to assign the tag
2. Moves the badge into the "Tags" row inside the info box (full opacity, no "+")
3. Adds an "x" button to the newly assigned tag

Assigned colored tags in the "Tags" row have a small "x" button. Clicking it:
1. Sends an AJAX request to remove the assignment
2. Moves the badge back to the unassigned list below (with "+" and reduced opacity)
3. If no assigned tags remain, the "Tags" row hides

If all colored tags are assigned (none unassigned), the section below the info box is hidden entirely.

### UI Mockup

**Info box (existing "Tags" row — with "x" buttons added):**
```
+----------------------------------------------------------+
| Tags:  [Nature x] [Landscape x] [Wildlife x]             |
+----------------------------------------------------------+
```

**Unassigned tags section (new, below info box):**
```
  [+ Portrait]  [+ Architecture]  [+ Travel]
```

Unassigned badges use the same colored background as assigned ones but at ~60% opacity, with a "+" prefix inside the badge. On hover, opacity increases to 100% and cursor becomes pointer.

## What We're NOT Doing

- No support for non-colored (plain) tags — only tags with `id_typetags IS NOT NULL`
- No inline tag creation — only existing colored tags can be assigned
- No drag-and-drop reordering
- No tag assignment for guest users
- No changes to admin tag management pages
- No modification of `pwg.images.setInfo` or other core WS methods

## Implementation Approach

All changes live inside the `plugins/typetags/` directory — no core file modifications. The plugin hooks into `loc_end_picture` to prepare data, uses a template prefilter to inject HTML/JS into `picture.tpl`, and registers two new WS methods for add/remove operations.

## Phase 1: Backend — New WS Methods

### Overview
Register two new web service methods in the typetags plugin: `typetags.image.addTag` (assign a colored tag to an image) and `typetags.image.removeTag` (remove a colored tag from an image). Both are accessible to all logged-in users with CSRF protection.

### Changes Required:

#### [x] 1. Register WS methods
**File**: `plugins/typetags/main.inc.php`
**Changes**: Add two new method registrations inside `typetags_add_methods()`:

```php
$service->addMethod(
  'typetags.image.addTag',
  'ws_typetags_image_addTag',
  array(
    'image_id' => array('type' => WS_TYPE_ID),
    'tag_id'   => array('type' => WS_TYPE_ID),
    'pwg_token' => array(),
  ),
  'Assign a colored tag to an image'
);

$service->addMethod(
  'typetags.image.removeTag',
  'ws_typetags_image_removeTag',
  array(
    'image_id' => array('type' => WS_TYPE_ID),
    'tag_id'   => array('type' => WS_TYPE_ID),
    'pwg_token' => array(),
  ),
  'Remove a colored tag from an image'
);
```

Note: No `admin_only` option — permission checked inside handler.

#### [x] 2. Implement WS method handlers
**File**: `plugins/typetags/main.inc.php`
**Changes**: Add two handler functions after the existing WS handlers (~line 165):

```php
function ws_typetags_image_addTag($params, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  // Verify tag is a colored tag
  $query = '
SELECT id FROM ' . TAGS_TABLE . '
  WHERE id = ' . (int)$params['tag_id'] . '
    AND id_typetags IS NOT NULL
;';
  if (!pwg_db_num_rows(pwg_query($query)))
  {
    return new PwgError(404, 'Tag not found or not a colored tag');
  }

  // Insert (ignore if already exists)
  $query = '
INSERT IGNORE INTO ' . IMAGE_TAG_TABLE . '
  (image_id, tag_id)
  VALUES (' . (int)$params['image_id'] . ', ' . (int)$params['tag_id'] . ')
;';
  pwg_query($query);

  // Invalidate tag count cache
  $query = '
UPDATE ' . USER_CACHE_TABLE . '
  SET nb_available_tags = NULL
;';
  pwg_query($query);

  return true;
}

function ws_typetags_image_removeTag($params, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  // Verify tag is a colored tag
  $query = '
SELECT id FROM ' . TAGS_TABLE . '
  WHERE id = ' . (int)$params['tag_id'] . '
    AND id_typetags IS NOT NULL
;';
  if (!pwg_db_num_rows(pwg_query($query)))
  {
    return new PwgError(404, 'Tag not found or not a colored tag');
  }

  $query = '
DELETE FROM ' . IMAGE_TAG_TABLE . '
  WHERE image_id = ' . (int)$params['image_id'] . '
    AND tag_id = ' . (int)$params['tag_id'] . '
;';
  pwg_query($query);

  // Invalidate tag count cache
  $query = '
UPDATE ' . USER_CACHE_TABLE . '
  SET nb_available_tags = NULL
;';
  pwg_query($query);

  return true;
}
```

### Success Criteria:

#### Automated Verification:
- [x] DDEV environment starts: `ddev start`
- [x] No PHP syntax errors: `ddev exec php -l plugins/typetags/main.inc.php`

#### Manual Verification:
- [ ] `typetags.image.addTag` works: call via `ws.php?format=json` with valid params as logged-in user — returns success, tag appears in `IMAGE_TAG_TABLE`
- [ ] `typetags.image.addTag` rejects guest: call without login — returns 401
- [ ] `typetags.image.addTag` rejects bad token: call with wrong `pwg_token` — returns 403
- [ ] `typetags.image.addTag` rejects non-colored tag: call with tag that has `id_typetags IS NULL` — returns 404
- [ ] `typetags.image.removeTag` works: call with valid params — tag removed from `IMAGE_TAG_TABLE`
- [ ] `typetags.image.removeTag` rejects guest/bad token/non-colored tag (same as addTag)
- [ ] Duplicate add is idempotent (INSERT IGNORE): no error on re-adding already-assigned tag

**Implementation Note**: After completing this phase and all verification passes, pause here for manual confirmation before proceeding to the next phase.

---

## Phase 2: PHP Data Preparation + Template Prefilter

### Overview
Hook into `loc_end_picture` to query unassigned colored tags for the current image, pass data to the template, and register a prefilter that injects the UI into `picture.tpl`.

### Changes Required:

#### [x] 1. Register picture page hook
**File**: `plugins/typetags/main.inc.php`
**Changes**: Add conditional hook registration (after line 47, near the existing commented-out picture handler):

```php
// inline tag assignment on picture page
if (script_basename() == 'picture')
{
  add_event_handler('loc_end_picture', 'typetags_picture_tags');
}
```

#### [x] 2. Implement data preparation function
**File**: `plugins/typetags/include/events_public.inc.php`
**Changes**: Add new function `typetags_picture_tags()`:

```php
function typetags_picture_tags()
{
  global $template, $page, $user;

  if (is_a_guest())
  {
    return;
  }

  $image_id = $page['image_id'];

  // Get IDs of tags already assigned to this image
  $query = '
SELECT tag_id
  FROM ' . IMAGE_TAG_TABLE . '
  WHERE image_id = ' . (int)$image_id . '
;';
  $assigned_ids = query2array($query, null, 'tag_id');

  // Get all colored tags with their colors
  $query = '
SELECT t.id, t.name, t.url_name, tt.color
  FROM ' . TAGS_TABLE . ' AS t
  INNER JOIN ' . TYPETAGS_TABLE . ' AS tt ON t.id_typetags = tt.id
  ORDER BY t.name
;';
  $all_colored = query2array($query);

  // Build unassigned list with pre-rendered badge HTML
  $unassigned = array();
  foreach ($all_colored as $tag)
  {
    if (!in_array($tag['id'], $assigned_ids))
    {
      $color_text = get_color_text($tag['color']);
      $tag['color_text'] = $color_text;
      $unassigned[] = $tag;
    }
  }

  // Build assigned colored tag ID set (for "x" buttons in JS)
  $assigned_colored_ids = array();
  foreach ($all_colored as $tag)
  {
    if (in_array($tag['id'], $assigned_ids))
    {
      $assigned_colored_ids[] = $tag['id'];
    }
  }

  $template->assign(array(
    'TYPETAGS_UNASSIGNED' => $unassigned,
    'TYPETAGS_ASSIGNED_COLORED_IDS' => $assigned_colored_ids,
    'TYPETAGS_IMAGE_ID' => $image_id,
    'TYPETAGS_PWG_TOKEN' => get_pwg_token(),
  ));

  $template->set_prefilter('picture', 'typetags_picture_prefilter');
}
```

#### [x] 3. Implement template prefilter
**File**: `plugins/typetags/include/events_public.inc.php`
**Changes**: Add prefilter function that modifies `picture.tpl` content:

```php
function typetags_picture_prefilter($content)
{
  // 1. Add data-tag-id attribute to tag links in #Tags section
  $search = '<a href="{$tag.URL}">{$tag.name}</a>';
  $replace = '<a href="{$tag.URL}" data-tag-id="{$tag.id}">{$tag.name}</a>';
  $content = str_replace($search, $replace, $content);

  // 2. Inject unassigned tags section after the info box </dl>
  //    Target: the unique "{/strip}\n</dl>" before "{if isset($metadata)}"
  $injection = '
{if isset($TYPETAGS_UNASSIGNED) && !empty($TYPETAGS_UNASSIGNED)}
<div id="typetags-unassigned" style="margin:8px 0;line-height:2.2;">
  {foreach from=$TYPETAGS_UNASSIGNED item=utag}
  <span class="typetag-badge typetag-add" data-tag-id="{$utag.id}" data-tag-name="{$utag.name|escape}" data-tag-color="{$utag.color}" data-tag-color-text="{$utag.color_text}" style="background-color:{$utag.color};color:{$utag.color_text};padding:2px 8px;border-radius:12px;display:inline-block;cursor:pointer;opacity:0.6;margin:2px;" title="{\'Add tag\'|@translate}">+ {$utag.name}</span>
  {/foreach}
</div>
{/if}
';

  $search_inject = '{if isset($metadata)}';
  $content = str_replace($search_inject, $injection . $search_inject, $content);

  // 3. Inject JavaScript via footer_script
  $js = '
{if isset($TYPETAGS_IMAGE_ID)}
{footer_script require=\'jquery\'}
(function() {ldelim}
  var imageId = {$TYPETAGS_IMAGE_ID};
  var pwgToken = "{$TYPETAGS_PWG_TOKEN}";
  var assignedColoredIds = [{foreach from=$TYPETAGS_ASSIGNED_COLORED_IDS item=tid name=tidloop}{$tid}{if !$smarty.foreach.tidloop.last},{/if}{/foreach}];

  // Add "x" buttons to assigned colored tags
  jQuery("#Tags a[data-tag-id]").each(function() {ldelim}
    var tagId = parseInt(jQuery(this).data("tag-id"));
    if (assignedColoredIds.indexOf(tagId) !== -1) {ldelim}
      jQuery(this).after(\'<span class="typetag-remove" data-tag-id="\' + tagId + \'" style="cursor:pointer;margin-left:2px;font-size:0.8em;" title="{\\\'Remove tag\\\'|@translate}">&times;</span>\');
    {rdelim}
  {rdelim});

  // Click: assign unassigned tag
  jQuery(document).on("click", ".typetag-add", function() {ldelim}
    var el = jQuery(this);
    var tagId = el.data("tag-id");
    var tagName = el.data("tag-name");
    var tagColor = el.data("tag-color");
    var tagColorText = el.data("tag-color-text");
    el.css("pointer-events", "none");

    jQuery.ajax({ldelim}
      url: "ws.php?format=json",
      type: "POST",
      data: {ldelim}
        method: "typetags.image.addTag",
        image_id: imageId,
        tag_id: tagId,
        pwg_token: pwgToken
      {rdelim},
      dataType: "json",
      success: function(data) {ldelim}
        if (data.stat === "ok") {ldelim}
          // Build assigned tag badge
          var style = "background-color:" + tagColor + ";color:" + tagColorText + ";padding:2px 8px;border-radius:12px;display:inline-block;";
          var badge = \'<span style="\' + style + \'">\' + tagName + \'</span>\';
          var link = \'<a href="#" data-tag-id="\' + tagId + \'">\' + badge + \'</a>\';
          var removeBtn = \'<span class="typetag-remove" data-tag-id="\' + tagId + \'" style="cursor:pointer;margin-left:2px;font-size:0.8em;" title="{\\\'Remove tag\\\'|@translate}">&times;</span>\';

          var tagsDD = jQuery("#Tags dd");
          if (tagsDD.length === 0) {ldelim}
            // Tags section doesn\'t exist yet, create it
            var tagsDiv = \'<div id="Tags" class="imageInfo"><dt>{\\\'Tags\\\'|@translate}</dt><dd>\' + link + removeBtn + \'</dd></div>\';
            // Insert before Albums or at end of dl#standard
            var albums = jQuery("#Categories");
            if (albums.length) {ldelim}
              albums.before(tagsDiv);
            {rdelim} else {ldelim}
              jQuery("dl#standard").children().last().after(tagsDiv);
            {rdelim}
          {rdelim} else {ldelim}
            // Append to existing tags
            if (tagsDD.children().length > 0) {ldelim}
              tagsDD.append(", ");
            {rdelim}
            tagsDD.append(link);
            tagsDD.append(removeBtn);
          {rdelim}

          // Remove from unassigned list
          el.remove();
          assignedColoredIds.push(tagId);

          // Hide unassigned section if empty
          if (jQuery("#typetags-unassigned .typetag-add").length === 0) {ldelim}
            jQuery("#typetags-unassigned").hide();
          {rdelim}
        {rdelim}
      {rdelim},
      error: function() {ldelim}
        el.css("pointer-events", "");
      {rdelim}
    {rdelim});
  {rdelim});

  // Click: remove assigned tag
  jQuery(document).on("click", ".typetag-remove", function(e) {ldelim}
    e.preventDefault();
    var el = jQuery(this);
    var tagId = el.data("tag-id");
    el.css("pointer-events", "none");

    jQuery.ajax({ldelim}
      url: "ws.php?format=json",
      type: "POST",
      data: {ldelim}
        method: "typetags.image.removeTag",
        image_id: imageId,
        tag_id: tagId,
        pwg_token: pwgToken
      {rdelim},
      dataType: "json",
      success: function(data) {ldelim}
        if (data.stat === "ok") {ldelim}
          // Find the tag link and remove it + the "x" button
          var tagLink = jQuery("#Tags a[data-tag-id=\'" + tagId + "\']");
          var tagName = "";
          var tagColor = "";
          var tagColorText = "";

          // Extract info from the badge span
          var badgeSpan = tagLink.find("span[style]");
          if (badgeSpan.length) {ldelim}
            tagName = badgeSpan.text();
            var bgMatch = badgeSpan.attr("style").match(/background-color:\s*([^;]+)/);
            var clMatch = badgeSpan.attr("style").match(/(?:^|;)\s*color:\s*([^;]+)/);
            if (bgMatch) tagColor = bgMatch[1];
            if (clMatch) tagColorText = clMatch[1];
          {rdelim}

          // Remove separator (", " before or after)
          var prev = tagLink[0].previousSibling;
          var next = el[0].nextSibling;
          if (next && next.nodeType === 3 && next.textContent.trim() === ",") {ldelim}
            next.remove();
          {rdelim} else if (prev && prev.nodeType === 3 && prev.textContent.match(/,\s*$/)) {ldelim}
            prev.textContent = prev.textContent.replace(/,\s*$/, "");
          {rdelim}

          tagLink.remove();
          el.remove();

          // Remove from assigned list
          var idx = assignedColoredIds.indexOf(tagId);
          if (idx !== -1) assignedColoredIds.splice(idx, 1);

          // Add back to unassigned list
          if (tagName && tagColor) {ldelim}
            var addStyle = "background-color:" + tagColor + ";color:" + tagColorText + ";padding:2px 8px;border-radius:12px;display:inline-block;cursor:pointer;opacity:0.6;margin:2px;";
            var addBadge = \'<span class="typetag-badge typetag-add" data-tag-id="\' + tagId + \'" data-tag-name="\' + tagName + \'" data-tag-color="\' + tagColor + \'" data-tag-color-text="\' + tagColorText + \'" style="\' + addStyle + \'" title="{\\\'Add tag\\\'|@translate}">+ \' + tagName + \'</span>\';
            var container = jQuery("#typetags-unassigned");
            if (container.length === 0) {ldelim}
              container = jQuery(\'<div id="typetags-unassigned" style="margin:8px 0;line-height:2.2;"></div>\');
              jQuery("dl#standard").after(container);
            {rdelim}
            container.append(addBadge).show();
          {rdelim}

          // Hide Tags row if empty
          if (jQuery("#Tags dd").children("a").length === 0) {ldelim}
            jQuery("#Tags").hide();
          {rdelim}
        {rdelim}
      {rdelim},
      error: function() {ldelim}
        el.css("pointer-events", "");
      {rdelim}
    {rdelim});
  {rdelim});

  // Hover effect on unassigned tags
  jQuery(document).on("mouseenter", ".typetag-add", function() {ldelim}
    jQuery(this).css("opacity", "1");
  {rdelim}).on("mouseleave", ".typetag-add", function() {ldelim}
    jQuery(this).css("opacity", "0.6");
  {rdelim});
{rdelim})();
{/footer_script}
{/if}
';

  // Append JS before the closing of the template
  $content .= $js;

  return $content;
}
```

### Success Criteria:

#### Automated Verification:
- [x] No PHP syntax errors: `ddev exec php -l plugins/typetags/include/events_public.inc.php`
- [x] No PHP syntax errors: `ddev exec php -l plugins/typetags/main.inc.php`

#### Manual Verification:
- [ ] Picture page loads without errors for logged-in user
- [ ] Picture page loads without errors for guest (no tag assignment UI shown)
- [ ] Unassigned colored tags appear below the info box with reduced opacity and "+" prefix
- [ ] Assigned colored tags show "x" button next to them
- [ ] Unassigned section is hidden when all colored tags are already assigned
- [ ] Tags section and unassigned section render correctly with the modus theme

**Implementation Note**: After completing this phase and all verification passes, pause here for manual confirmation before proceeding to the next phase.

---

## Phase 3: Interactive Behavior — AJAX Tag Assignment

### Overview
This phase is about testing the complete interactive flow. The JS was injected in Phase 2 — this phase focuses on end-to-end verification and any fixes needed.

### Changes Required:

#### [x] 1. Fix any issues found during interactive testing
**File**: `plugins/typetags/include/events_public.inc.php`
**Changes**: Address bugs found during Phase 2 manual verification — exact changes TBD.

### Success Criteria:

#### Manual Verification — Add Tag Flow:
- [ ] Click unassigned tag → tag appears in "Tags" row inside info box (full opacity, no "+")
- [ ] Click unassigned tag → "x" button appears next to the newly assigned tag
- [ ] Click unassigned tag → tag disappears from unassigned list
- [ ] Click unassigned tag → unassigned section hides when last tag is assigned
- [ ] Adding a tag when "Tags" row doesn't exist yet → "Tags" row is created
- [ ] Page reload after adding → tag persists in "Tags" row (server-rendered)

#### Manual Verification — Remove Tag Flow:
- [ ] Click "x" on assigned tag → tag disappears from "Tags" row
- [ ] Click "x" on assigned tag → tag reappears in unassigned list (reduced opacity, "+")
- [ ] Click "x" on assigned tag → "Tags" row hides when last tag is removed
- [ ] Removing a tag when unassigned section was hidden → section reappears
- [ ] Page reload after removing → tag persists in unassigned list

#### Manual Verification — Edge Cases:
- [ ] Rapid clicks: double-clicking a tag doesn't cause duplicate requests or UI glitches
- [ ] Network error: tag stays in place (not moved) if AJAX fails
- [ ] Multiple colored tags: comma separators between assigned tags render correctly
- [ ] Comma separators clean up properly when tags are removed (no leading/trailing commas)
- [ ] Image with no tags at all: unassigned section shows, assigning creates the Tags row
- [ ] Image with only non-colored tags: non-colored tags unaffected, no "x" buttons on them

**Implementation Note**: After completing this phase and all verification passes, the feature is ready.

---

## Testing Strategy

The project has no formal test suite (no PHPUnit). All testing is manual via the DDEV environment.

### Manual Testing Steps:

1. Start DDEV: `ddev start`
2. Log in as a non-admin user (if available) and as admin
3. Navigate to a picture page with some colored tags assigned
4. Verify the unassigned tags section appears below the info box
5. Test the full add/remove flow as described in Phase 3 success criteria
6. Test as guest — verify no tag assignment UI is shown
7. Test with a picture that has no tags assigned at all
8. Test with a picture where all colored tags are already assigned
9. Verify no console errors in browser DevTools

### Test Design Techniques Applied:

- **Happy path** `[HAPPY]`: Add tag, remove tag, standard flow
- **Boundary** `[BVA]`: Image with 0 tags, image with all colored tags assigned, image with only non-colored tags, single colored tag exists
- **Negative** `[NEG]`: Guest user (denied), invalid CSRF token (denied), non-colored tag ID (denied)
- **State transition** `[ST]`: Unassigned → assigned → unassigned (round-trip), Tags row creation/removal
- **Error guessing** `[ERR]`: Double-click, network failure, concurrent browser tabs

## Performance Considerations

- Two additional SQL queries per picture page load for logged-in users: one for assigned tag IDs, one for all colored tags. Both are small result sets and indexed.
- AJAX calls for add/remove are lightweight single-row operations.
- No impact on guest users (feature not loaded).

## References

- [Research document](../research/2026-04-24-picture-page-tag-assignment.md)
- Existing WS method pattern: `plugins/typetags/main.inc.php:65-92`
- Template prefilter pattern: `plugins/typetags/include/events_admin.inc.php:53-64`
- Tag rendering pipeline: `plugins/typetags/include/events_public.inc.php:7-88`
