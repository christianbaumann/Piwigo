---
date: 2026-04-27T18:49:37+00:00
git_commit: e01ef19da76ee94b633acf9cbdf38a23dc9f1bc4
branch: master
topic: "Picture page: inline colored tag assignment UI"
tags: [plan, typetags, picture-page, colored-tags, tag-assignment]
status: complete
completed: 2026-08-29
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

#### Manual Verification — automated 2026-08-29 (integration layer):

Every box below is now a named PHPUnit test in `plugins/typetags/tests/Integration/`. The
suite forces its fixtures and asserts they took effect, so none of these runs over state it
merely hoped for.

- [x] `typetags.image.addTag` works — `AddTagTest::testAssignsColouredTag` `[HAPPY]`
- [x] `typetags.image.addTag` rejects guest (401) — `AddTagTest::testGuestIsRejected` `[NEG]`
- [x] `typetags.image.addTag` rejects bad token (403) — `AddTagTest::testBadTokenIsRejected` `[NEG]`
- [x] `typetags.image.addTag` rejects non-colored tag (404) — `AddTagTest::testNonColouredTagIsRejected` `[NEG]`
- [x] `typetags.image.removeTag` works — `RemoveTagTest::testRemovesAssignedTag` `[HAPPY]`
- [x] `typetags.image.removeTag` rejects guest/bad token/non-colored tag —
  `RemoveTagTest::testGuestIsRejected`, `::testBadTokenIsRejected`,
  `::testNonColouredTagIsRejected` `[NEG]`. Each also asserts the row **survived** the
  rejection, which the original box did not ask for and which is the part that would
  actually matter.
- [x] Duplicate add is idempotent — `AddTagTest::testDuplicateAddIsIdempotent` `[ST]`,
  asserting `COUNT(*) == 1` rather than mere presence. The mechanism is
  `PRIMARY KEY (image_id, tag_id)` (`install/piwigo_structure-mysql.sql:208`).

The port also closed gaps this checklist never had: `removeTag` with a nonexistent tag,
both methods' cache invalidation, `tag_id`/`image_id` boundary values, and the two
defects Phase 2 of the 2026-08-28 plan fixed (`::testNonexistentImageIsRejected`,
`::testNonexistentImageWritesNoOrphanRow`). Full list in
[docs/agents/TESTING.md](../TESTING.md) and the 2026-08-28 plan's Testing Strategy.

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

#### Manual Verification — automated 2026-08-29:

Two of these are server-side facts and are asserted against page source; four are runtime
DOM facts that page source cannot witness, and are Playwright specs.

- [x] Picture page loads without errors for logged-in user —
  `PicturePageSourceTest::testPageReturnsTwoHundredForLoggedInUser`, `::testPageHasNoFatalError`,
  `::testPageHasNoSmartyCompilerError` `[HAPPY]` `[NEG]`
- [x] Picture page loads without errors for guest, with no assignment UI —
  `PicturePageSourceTest::testGuestSeesNoAssignmentUi` `[NEG]`
- [x] Unassigned colored tags appear with reduced opacity and "+" prefix —
  `assign.spec.js` → `unassigned badges render at reduced opacity with a plus prefix` `[HAPPY]`
- [x] Assigned colored tags show "x" button —
  `remove.spec.js` → `assigned coloured tags show a remove button` `[HAPPY]`
- [x] Unassigned section hidden when all colored tags are assigned — split across layers,
  because the two halves are different facts: the server never renders the container
  (`PicturePageSourceTest::testAllAssignedRendersNoUnassignedSection` `[BVA]`), and the
  browser recreates it when one is removed (`remove.spec.js` → `the unassigned section is
  recreated when it had been hidden` `[BVA]`)
- [x] Both sections render correctly with the modus theme —
  `edge-cases.spec.js` → `the modus theme renders both sections correctly` `[HAPPY]`, which
  guards against asserting under the wrong theme by checking `themes/modus` appears in the
  page's asset URLs first

What "render correctly" meant beyond shape — that badges are painted their configured
colours at a real size — is `rendering.spec.js` (4 specs), which reads the expected palette
out of `piwigo_typetags` rather than carrying a copy of it.

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

All 17 boxes below were automated 2026-08-29 as Playwright specs in
`plugins/typetags/tests/e2e/`. Every one was drafted by driving the running site, not by
reading `picture.tpl` — the badge markup is assembled at runtime by injected JavaScript, so
the DOM a spec sees is not the DOM the template shows.

#### Manual Verification — Add Tag Flow (`assign.spec.js`):
- [x] Click unassigned tag → tag appears in "Tags" row at full opacity, no "+" —
  `clicking an unassigned badge moves it into the Tags row at full opacity` `[HAPPY]`
- [x] Click unassigned tag → "x" button appears — `a remove button appears on the newly assigned tag` `[HAPPY]`
- [x] Click unassigned tag → tag disappears from unassigned list — `the badge disappears from the unassigned list` `[ST]`
- [x] Unassigned section hides when the last tag is assigned — `the unassigned section hides when the last tag is assigned` `[BVA]`.
  Needed a fifth fixture, `allButOneColoredAssigned()`: this is a *transition to empty*, and
  neither planned scenario could produce it.
- [x] Adding a tag when the "Tags" row doesn't exist → row is created —
  `the Tags row is created when the image had no tags` `[BVA]`, State C
- [x] Page reload after adding → tag persists, server-rendered — `the assignment survives a page reload` `[ST]`

#### Manual Verification — Remove Tag Flow (`remove.spec.js`):
- [x] Click "x" → tag disappears from "Tags" row — `clicking it removes the tag from the Tags row` `[HAPPY]`
- [x] Click "x" → tag reappears in unassigned list at reduced opacity — `the tag reappears in the unassigned list at reduced opacity` `[ST]`
- [x] Click "x" → "Tags" row hides when the last tag is removed — `the Tags row hides when the last tag is removed` `[BVA]`
- [x] Removing when the unassigned section was hidden → section reappears — `the unassigned section is recreated when it had been hidden` `[BVA]`
- [x] Page reload after removing → tag persists in unassigned list — `the removal survives a page reload` `[ST]`

One spec maps to no box: `add then remove returns the page to its starting state`, the
round trip the two flows imply but neither checklist asked for.

#### Manual Verification — Edge Cases (`edge-cases.spec.js`):
- [x] Double-clicking causes no duplicate requests — `double-clicking issues exactly one request` `[ERR]`,
  asserted by counting intercepted POSTs rather than by eyeballing the UI
- [x] Network error: tag stays in place — `a network failure leaves the tag in place and the badge clickable` `[NEG]`, via `route.abort()`
- [x] Comma separators render between multiple assigned tags — `comma separators render between multiple assigned tags` `[HAPPY]`
- [x] Comma separators clean up with no leading/trailing commas — `comma separators clean up with no leading or trailing comma` `[BVA]`.
  This one failed on its first run and the failure was real: the two cleanup branches are
  not symmetric — `nextSibling` deletes the separator node, `previousSibling` only empties
  its text — so removing the last tag leaves one zero-length text node. Invisible, no
  requirement forbids it, so it is recorded rather than fixed: the spec counts non-empty
  separator nodes for its real assertion and pins the leftover at exactly one `[ERR]`, so a
  future change to either branch surfaces instead of passing silently.
- [x] Image with no tags: unassigned section shows, assigning creates the Tags row — split
  across layers: `#Tags` genuinely absent server-side is
  `PicturePageSourceTest::testImageWithNoTagsRendersNoTagsRow` `[BVA]`; the creation is
  `assign.spec.js` → `the Tags row is created when the image had no tags`
- [x] Image with only non-colored tags: unaffected, no "x" buttons — `an image with only non-coloured tags shows no remove buttons` `[NEG]`, State D

One further spec maps to no box because the box list predates the defect being found:
`a server rejection leaves the badge clickable`. A `PwgError` arrives as HTTP 200 with
`stat:"fail"`, so it lands in jQuery's `success` callback, not `error` — before the Phase 2
fix that left the badge permanently non-interactive with no message. It is a different code
path from the network-failure box above, which is why both exist.

**Implementation Note**: After completing this phase and all verification passes, the feature is ready.

---

## Testing Strategy

**Superseded 2026-08-29.** When this plan was written the project had no test suite and all
testing was manual. `plugins/typetags` now carries unit, integration and E2E suites — see
[docs/agents/TESTING.md](../TESTING.md) for the conventions, the commands, and the two
ledgers.

### Manual Testing Steps — all automated:

| Step | Successor |
|---|---|
| 1. Start DDEV | Precondition of the integration and E2E suites, which fail fast without it |
| 2. Log in | `tests/e2e/auth.setup.js` (asserts the login form is *gone*, since Piwigo re-renders it on failure) and `WsClient::login()` |
| 3–5. Navigate, verify the unassigned section, run the add/remove flow | `assign.spec.js`, `remove.spec.js` |
| 6. Test as guest | `PicturePageSourceTest::testGuestSeesNoAssignmentUi` |
| 7. Picture with no tags | `FixtureBuilder::imageWithNoTags()` — State C |
| 8. Picture with all colored tags assigned | `FixtureBuilder::allColoredAssigned()` — State B |
| 9. No console errors | `rendering.spec.js` → `the assignment UI initialises with no console or page errors`, with an anti-vacuity guard asserting the badges exist first |

What stays manual, because it has no oracle: whether badge contrast is *legible* for all 8
colours, and whether the hover transition *feels* right. Both are in the hand-check ledger
in [docs/agents/TESTING.md](../TESTING.md) with that reason, not ticked here.

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
