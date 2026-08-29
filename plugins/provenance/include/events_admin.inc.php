<?php
defined('PROVENANCE_PATH') or die('Hacking attempt!');

/**
 * Admin-side injection. Loaded only when IN_ADMIN is defined, so the public
 * gallery never pays for it.
 */

/**
 * Puts the provenance block on the album properties screen.
 *
 * The album's own save path cannot be extended - ws_categories_setInfo hard-codes
 * the columns it writes - so the block carries its own button and saves through
 * pwg.provenance.setAlbumInfo.
 */
function provenance_admin_album()
{
  global $template, $page;

  if ($page['page'] != 'album')
  {
    return;
  }

  // admin/album.php sets $page['tab'], but it runs after this event, so the
  // request parameter is read here with the same default that file applies.
  $tab = isset($_GET['tab']) ? $_GET['tab'] : 'properties';
  if ($tab != 'properties')
  {
    return;
  }

  $cat_id = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
  if ($cat_id <= 0)
  {
    return;
  }

  $columns = array_keys(provenance_album_columns());

  $result = pwg_query('
SELECT '.implode(', ', $columns).'
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$cat_id.'
;');

  if (!pwg_db_num_rows($result))
  {
    return;
  }

  $values = pwg_db_fetch_assoc($result);

  load_language('plugin.lang', PROVENANCE_PATH);

  $template->assign(
    array(
      'PROVENANCE_PATH' => PROVENANCE_PATH,
      'PROVENANCE_SHORT_TEXT_MAX' => PROVENANCE_SHORT_TEXT_MAX_CHARS,
      'PROVENANCE_ALBUM' => array(
        'CAT_ID'         => $cat_id,
        'PHYSICAL_ALBUM' => (string)$values['provenance_physical_album'],
        'OWNER'          => (string)$values['provenance_owner'],
        'SCANNED_ON'     => (string)$values['provenance_scanned_on'],
        'NOTE'           => (string)$values['provenance_note'],
        ),
      )
    );

  $template->set_prefilter('album_properties', 'provenance_album_prefilter');
}

/**
 * Injects the block immediately before the album's Save button.
 *
 * The anchor is a constant with a structural guard test behind it: a string that
 * silently stops matching would leave the screen rendering perfectly, without
 * the feature.
 *
 * @param string $content
 * @return string
 */
function provenance_album_prefilter($content)
{
  $injection = file_get_contents(PROVENANCE_PATH . 'template/album_provenance.tpl');

  return str_replace(
    PROVENANCE_TPL_ALBUM_ANCHOR,
    $injection . PROVENANCE_TPL_ALBUM_ANCHOR,
    $content
    );
}
