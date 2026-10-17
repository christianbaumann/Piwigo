---
date: 2026-10-17
git_commit: 6f31fba35
branch: master
topic: "Why the menu counted 106 photos while the API and the albums gave 105"
tags: [research, core, user-cache, menu, image_category]
status: fixed (decision 0074)
---

# The menu photo count

SES-2026-10-13-01: the menu said "106 Fotos", `pwg.categories.getImages` and the album
thumbnails 105. The design guessed a photo in no album or in the lounge, counted for admins.

## Where each number comes from

| Number | Code | Joins `images`? | Joins `categories`? |
|---|---|---|---|
| Menu "N Fotos" | `$user['nb_total_images']`, `include/menubar.inc.php:102`, computed in `getuserdata()` (`include/functions_user.inc.php:499-505`) and stored in `piwigo_user_cache` | no | no |
| Album counts | `get_computed_categories()`, `include/functions_category.inc.php:476` | yes (`LEFT JOIN`, `COUNT(date_available)`) | yes (`FROM categories`) |
| API | `ws_categories_getImages()`, `include/ws_functions/pwg.categories.php` | yes (`INNER JOIN`) | yes (category list read first) |

The menu query was:

```sql
SELECT COUNT(DISTINCT(image_id)) FROM image_category
  WHERE category_id NOT IN (<forbidden>) AND image_id NOT IN (<forbidden images>)
```

`<forbidden>` lists only albums that exist, so a link into a deleted album passes; nothing asks
whether `image_id` still has a row.

## The guess, checked

- **A photo in no album** has no `image_category` row; none of the three counts sees it.
- **A photo in the lounge** sits in `piwigo_lounge` until `empty_lounge()` links it
  (`admin/include/functions.php:1932`); none of the three counts sees it.
- **Admin-only photos**: privacy level and locked albums are already applied to the menu count
  through `image_access_list` and `calculate_permissions()`; an ungranted private album is
  forbidden to administrators too.

All of these are recorded, per role, in `CoreMenuPhotoCountCharacterizationTest` (`[ERR]`).

## What the install held

On 2026-10-17 `piwigo_image_category` had five rows whose photo row was gone (images 55910,
55917, 55950, 55989, 37388), three of them also in albums that no longer exist (29, 37, 27453).
Two sat in the real album *Verschiedenes* (2). The menu counted 112 for the webmaster against
107 photo rows, every one of which is in an album: the five dead links exactly.

Core's own delete paths remove the links (`delete_elements()` and `delete_categories()` in
`admin/include/functions.php`). The ids are test-fixture ids; the fixtures delete the image row
first and the link second (e.g. provenance's `FixtureBuilder::destroyTestImages()`), so a run
killed between the two, or a helper deleting only one table, leaves such a row. The 2026-10-13
state is gone, so which row made it 106 cannot be shown; one dead link explains the difference
exactly, and the two guesses above cannot.

The remote gets its links only from core's own paths (sync, upload, API), so it is less likely to
hold dead links, but nothing prevents them there either.

## Fix

Join `images` and `categories` in the menu query (decision 0074,
`include/functions_user.inc.php`). Requirement test: provenance's `CoreMenuPhotoCountTest`. The
dead rows in the local install are left in place; the count no longer sees them.
