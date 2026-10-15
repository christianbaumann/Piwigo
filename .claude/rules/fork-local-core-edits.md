# Fork-Local Core Edits

Read before editing Piwigo core, a core theme or a root entry, and before pulling from upstream.
Every edit this fork makes outside `plugins/` is listed here, one bullet each; a new one is added in
the same commit that makes it. An upstream pull checks each bullet still holds after the merge.

- Two fork-local `trigger_notify()` calls have been added to core so the provenance plugin can hook the paths that create album links. Upstream has neither:
  - `associate_images_to_categories` in `admin/include/functions.php`, inside the `if (count($inserts))` block — the funnel every virtual link goes through (API, Batch Manager, upload). Payload: `image_ids`, `category_ids`
  - `site_update_associate_images` in `admin/site_update.php`, after the `$insert_links` `mass_inserts()` — the filesystem sync inserts its storage links directly without calling the helper. Payload: the `$insert_links` rows. This is the **only** trigger in that file; anything claiming it fires none is out of date
- One fork-local core fix: album thumbnails no longer count an admin's empty sub-albums, which the listing hides (`discount_hidden_subalbums()`, `include/category_cats.inc.php`), see [decision 0037](../../docs/agents/decisions/0037-album-thumbnails-count-only-listed-subalbums.md)
- One fork-local core fix: `set_cat_visible()` (`admin/include/functions.php`) ends with `invalidate_user_cache()`, so closing or reopening an album (Geschlossenes Album, `pwg.categories.setInfo`) takes effect for visitors at once instead of after the next cache purge. Tested by provenance's `CoreAlbumLockTakesEffectTest`
- One fork-local root entry: `handbuch.php` serves `handbuch/` to logged-in users only, rewritten from `/handbuch/*` by `.ddev/nginx/handbuch.conf` and `handbuch/.htaccess` ([decision 0063](../../docs/agents/decisions/0063-the-handbook-is-served-through-a-login-checking-entry.md))
- One fork-local core template change: the merge panel's two buttons in `admin/themes/default/template/tags.tpl` pass through `|@translate` (upstream hard-codes English `Confirm merge` / `Cancel`). It stays fork-local: nothing is ever pushed or proposed to `Piwigo/Piwigo` ([git-and-commits.md](git-and-commits.md))
- The `modus` theme carries one fork-local change: fit-to-area and zoom on the picture page (`js/photo.zoom.js`, a changed `rvas_choose()`), see [decision 0032](../../docs/agents/decisions/0032-modus-picture-fit-and-zoom-is-a-fork-local-theme-edit.md)
