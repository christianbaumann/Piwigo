<?php
defined('GALLERYRULES_PATH') or die('Hacking attempt!');

/*
 * Empty albums are hidden from visitors: core forbids every album without a
 * photo in a non-admin's cache (feature 1053, get_computed_categories()), so it
 * is neither listed nor opened by URL. An administrator still opens it, through
 * "In Galerie öffnen", with its description, and is told visitors do not see it.
 */

/** The note an administrator sees on an empty album's page. */
define('GALLERYRULES_EMPTY_ALBUM_NOTE', 'This album holds no photo. Visitors do not see it until it holds one.');

/**
 * loc_begin_index: adds the note to an empty album's page for an administrator.
 */
function galleryrules_empty_album_note()
{
  global $page;

  if (!is_admin()
    or 'categories' != @$page['section']
    or !isset($page['category']['id'])
    or isset($page['combined_categories']))
  {
    return;
  }

  // The album's own photos and its sub-albums', whoever may see them.
  $query = '
SELECT COUNT(*)
  FROM '.IMAGE_CATEGORY_TABLE.' AS ic
    INNER JOIN '.CATEGORIES_TABLE.' AS c ON c.id = ic.category_id
  WHERE c.id = '.(int)$page['category']['id'].'
    OR c.uppercats LIKE \''.pwg_db_real_escape_string($page['category']['uppercats']).',%\'
;';
  list($photos) = pwg_db_fetch_row(pwg_query($query));
  if ($photos == 0)
  {
    load_language('plugin.lang', GALLERYRULES_PATH);
    $page['infos'][] = l10n(GALLERYRULES_EMPTY_ALBUM_NOTE);
  }
}
