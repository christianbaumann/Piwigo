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

  // The client chunks the apply itself, so it needs the album's photo ids. One
  // id list is cheaper than the paging round-trips a web-service listing would
  // cost, and the album screen already knows which album it is showing.
  $photo_ids = query2array('
SELECT image_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id = '.$cat_id.'
  ORDER BY image_id
;', null, 'image_id');

  load_language('plugin.lang', PROVENANCE_PATH);

  $template->assign(
    array(
      'PROVENANCE_PATH' => PROVENANCE_PATH,
      'PROVENANCE_SHORT_TEXT_MAX' => PROVENANCE_SHORT_TEXT_MAX_CHARS,
      'PROVENANCE_APPLY_MAX_CHUNK' => PROVENANCE_APPLY_MAX_CHUNK,
      'PROVENANCE_WRITEBACK_MAX_CHUNK' => PROVENANCE_WRITEBACK_MAX_CHUNK,
      // No exiftool means no write-back button: an action that can only fail is
      // worse than an action the screen does not offer.
      'PROVENANCE_EXIFTOOL' => provenance_exiftool_available(),
      'PROVENANCE_ALBUM' => array(
        'CAT_ID'         => $cat_id,
        'PHYSICAL_ALBUM' => (string)$values['provenance_physical_album'],
        'OWNER'          => (string)$values['provenance_owner'],
        'SCANNED_ON'     => (string)$values['provenance_scanned_on'],
        'NOTE'           => (string)$values['provenance_note'],
        'PHOTO_IDS'      => implode(',', $photo_ids),
        'PHOTO_COUNT'    => count($photo_ids),
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

/**
 * Puts the provenance block on the photo properties screen.
 *
 * Only the photo's own note is editable here. The four album-sourced values are
 * shown read-only: they are album-authoritative, and an input that silently did
 * nothing would be worse than no input at all.
 */
function provenance_admin_photo()
{
  global $template, $page;

  if ($page['page'] != 'photo')
  {
    return;
  }

  // admin/photo.php sets $page['tab'], but it runs after this event, so the
  // request parameter is read here with the same default that file applies.
  $tab = isset($_GET['tab']) ? $_GET['tab'] : 'properties';
  if ($tab != 'properties')
  {
    return;
  }

  $image_id = isset($_GET['image_id']) ? (int)$_GET['image_id'] : 0;
  if ($image_id <= 0)
  {
    return;
  }

  $columns = array_keys(provenance_image_columns());

  $result = pwg_query('
SELECT '.implode(', ', $columns).'
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$image_id.'
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
      'PROVENANCE_PHOTO' => array(
        'IMAGE_ID'       => $image_id,
        'PHYSICAL_ALBUM' => (string)$values['provenance_physical_album'],
        'OWNER'          => (string)$values['provenance_owner'],
        'SCANNED_ON'     => (string)$values['provenance_scanned_on'],
        'ALBUM_NOTE'     => (string)$values['provenance_album_note'],
        'NOTE'           => (string)$values['provenance_note'],
        ),
      )
    );

  $template->set_prefilter('picture_modify', 'provenance_photo_prefilter');
}

/**
 * Injects the block immediately before the photo screen's save bar.
 *
 * @param string $content
 * @return string
 */
function provenance_photo_prefilter($content)
{
  $injection = file_get_contents(PROVENANCE_PATH . 'template/photo_provenance.tpl');

  return str_replace(
    PROVENANCE_TPL_PHOTO_ANCHOR,
    $injection . PROVENANCE_TPL_PHOTO_ANCHOR,
    $content
    );
}

/**
 * Puts the move-mode choice into the Batch Manager's move panel.
 *
 * Registered on loc_end_element_set_global, which fires after the page has set
 * its template filenames and so is the point a prefilter can still be added.
 * The radios post alongside the move itself; loc_begin_element_set_global has
 * already read the request by the time core dispatches the action, and
 * provenance_inherit_into() reads the same parameter from $_POST.
 */
function provenance_batch_move_panel()
{
  global $template;

  load_language('plugin.lang', PROVENANCE_PATH);

  $template->set_prefilter('batch_manager_global', 'provenance_batch_prefilter');
}

/**
 * Injects the radios immediately inside the move panel.
 *
 * The anchor is a constant with a structural guard test behind it: a string
 * that silently stopped matching would leave the Batch Manager rendering
 * perfectly, with every move quietly taking the unattended default.
 *
 * @param string $content
 * @return string
 */
function provenance_batch_prefilter($content)
{
  $injection = file_get_contents(PROVENANCE_PATH . 'template/batch_move_provenance.tpl');

  return str_replace(
    PROVENANCE_TPL_BATCH_MOVE_ANCHOR,
    PROVENANCE_TPL_BATCH_MOVE_ANCHOR . $injection,
    $content
    );
}
