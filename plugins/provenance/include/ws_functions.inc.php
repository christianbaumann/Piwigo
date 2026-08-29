<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Reads the provenance audit trail for one object.
 *
 * Follows the shape of ws_getActivityList() (include/ws_functions/pwg.php:453)
 * rather than inventing a new one, but returns the rows unaggregated: this is a
 * value-level trail, and collapsing consecutive rows the way the activity screen
 * does would hide exactly what it exists to show.
 *
 * @param array $param
 * @param object $service
 * @return array|PwgError
 */
function ws_provenance_getHistory($param, &$service)
{
  if (!in_array($param['object'], provenance_history_objects(), true))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid object');
  }

  $where = array(
    'object = "'.pwg_db_real_escape_string($param['object']).'"',
    'object_id = '.(int)$param['object_id'],
    );

  foreach (array('date_min' => '>=', 'date_max' => '<=') as $datefield => $operator)
  {
    if (empty($param[$datefield]))
    {
      continue;
    }

    if (!is_valid_mysql_datetime($param[$datefield]))
    {
      return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid '.$datefield);
    }

    $where[] = 'occured_on '.$operator.' "'.pwg_db_real_escape_string($param[$datefield]).'"';
  }

  $where = 'WHERE '.implode("\n    AND ", $where);

  list($total_count) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM '.PROVENANCE_HISTORY_TABLE.'
  '.$where.'
;'));

  $rows = query2array('
SELECT id, object, object_id, field, old_value, new_value, source, performed_by, occured_on
  FROM '.PROVENANCE_HISTORY_TABLE.'
  '.$where.'
  ORDER BY occured_on DESC, id DESC
  LIMIT '.(int)$param['per_page'].'
;');

  foreach ($rows as $i => $row)
  {
    $rows[$i]['id'] = (int)$row['id'];
    $rows[$i]['object_id'] = (int)$row['object_id'];
    $rows[$i]['performed_by'] = $row['performed_by'] === null ? null : (int)$row['performed_by'];
  }

  return array(
    'paging' => array(
      'per_page' => (int)$param['per_page'],
      'count' => count($rows),
      'total_count' => (int)$total_count,
      ),
    'histories' => $rows,
    );
}

/**
 * Saves the provenance of one album.
 *
 * The album's own save path cannot carry these columns - ws_categories_setInfo
 * hard-codes the three it writes and fires no trigger_change - so this is a
 * separate method with its own guards.
 *
 * Validation happens here and only here, at the system boundary. Over-long text
 * is refused rather than cut: silently shortening a provenance fact is worse
 * than a save the administrator can see failed.
 *
 * $conf['allow_html_descriptions'] is deliberately not honoured. This text is
 * destined for an EXIF/IPTC packet where markup is meaningless.
 *
 * @param array $param
 * @param object $service
 * @return array|PwgError
 */
function ws_provenance_setAlbumInfo($param, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $param['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $cat_id = (int)$param['cat_id'];
  $columns = array_keys(provenance_album_columns());

  $result = pwg_query('
SELECT '.implode(', ', $columns).'
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$cat_id.'
;');

  if (!pwg_db_num_rows($result))
  {
    return new PwgError(404, 'Album not found');
  }

  $before = pwg_db_fetch_assoc($result);

  $physical_album = provenance_clean_short_text($param['physical_album']);
  $owner = provenance_clean_short_text($param['owner']);

  foreach (array('physical_album' => $physical_album, 'owner' => $owner) as $name => $cleaned)
  {
    if ($cleaned['too_long'])
    {
      return new PwgError(400, $name.' exceeds '.PROVENANCE_SHORT_TEXT_MAX_CHARS.' characters');
    }
  }

  if (!provenance_is_valid_scanned_on($param['scanned_on']))
  {
    return new PwgError(400, 'scanned_on must be an existing date in YYYY-MM-DD form, or empty');
  }

  $after = array(
    'provenance_physical_album' => $physical_album['text'],
    'provenance_owner'          => $owner['text'],
    'provenance_scanned_on'     => trim((string)$param['scanned_on']),
    'provenance_note'           => provenance_clean_note($param['note']),
    );

  // An emptied field is stored as NULL, so "never entered" and "cleared" are the
  // same absence in the database as they are to the recorder.
  $assignments = array();
  foreach ($after as $column => $value)
  {
    $assignments[] = $column.' = '.($value === '' ? 'NULL' : '"'.pwg_db_real_escape_string($value).'"');
  }

  pwg_query('
UPDATE '.CATEGORIES_TABLE.'
  SET '.implode(",\n      ", $assignments).'
  WHERE id = '.$cat_id.'
;');

  $changes = array();
  foreach ($after as $column => $value)
  {
    $changes[] = array(
      'object'    => 'album',
      'object_id' => $cat_id,
      'field'     => $column,
      'old'       => $before[$column],
      'new'       => $value,
      'source'    => 'album_edit',
      );
  }

  return array(
    'cat_id' => $cat_id,
    'changed' => provenance_record_changes($changes),
    );
}

/**
 * Copies one album's provenance onto the photos of one chunk.
 *
 * The server never walks a whole album: the client cuts it into chunks and sends
 * one request at a time, so a large album cannot run into the production 60 s
 * ceiling. A chunk is all-or-nothing - an unusable id list, or one naming a
 * photo outside the album, refuses the whole request rather than applying part
 * of it, because a half-applied album is invisible afterwards.
 *
 * provenance_note on the photo is not in the update set at all: the album's own
 * free text lands in provenance_album_note, and what somebody wrote about one
 * photo is never overwritten from above (decision C3).
 *
 * @param array $param
 * @param object $service
 * @return array|PwgError
 */
function ws_provenance_applyToPhotos($param, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $param['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $cat_id = (int)$param['cat_id'];

  $result = pwg_query('
SELECT '.implode(', ', array_keys(provenance_album_columns())).'
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$cat_id.'
;');

  if (!pwg_db_num_rows($result))
  {
    return new PwgError(404, 'Album not found');
  }

  $album = pwg_db_fetch_assoc($result);

  $image_ids = provenance_parse_id_list($param['image_ids']);
  if ($image_ids === null)
  {
    return new PwgError(400, 'image_ids must be at most '.PROVENANCE_APPLY_MAX_CHUNK.' comma-separated photo ids');
  }

  if (empty($image_ids))
  {
    return array('cat_id' => $cat_id, 'applied' => 0, 'changed' => 0);
  }

  $id_list = implode(',', $image_ids);

  $in_album = query2array('
SELECT image_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id = '.$cat_id.'
    AND image_id IN ('.$id_list.')
;', null, 'image_id');

  if (count($in_album) != count($image_ids))
  {
    return new PwgError(400, 'Every photo in image_ids must belong to this album');
  }

  // The copy-down target columns, and what the album puts in each of them.
  $values = array();
  foreach (provenance_copy_down_map() as $album_column => $image_column)
  {
    $values[$image_column] = (string)$album[$album_column];
  }

  // Read before the update, so the history rows say what really changed.
  $before = query2array('
SELECT id, '.implode(', ', array_keys($values)).'
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.$id_list.')
;', 'id');

  // mass_updates() applies no escaping of its own.
  $escaped = array();
  foreach ($values as $column => $value)
  {
    $escaped[$column] = pwg_db_real_escape_string($value);
  }

  $datas = array();
  foreach ($image_ids as $image_id)
  {
    $datas[] = array_merge(array('id' => $image_id), $escaped);
  }

  // Deliberately without MASS_UPDATES_SKIP_EMPTY: an album field cleared by the
  // administrator has to clear on the photos too, not linger there.
  mass_updates(
    IMAGES_TABLE,
    array('primary' => array('id'), 'update' => array_keys($values)),
    $datas
    );

  $changes = array();
  foreach ($image_ids as $image_id)
  {
    foreach ($values as $column => $value)
    {
      $changes[] = array(
        'object'    => 'photo',
        'object_id' => $image_id,
        'field'     => $column,
        'old'       => isset($before[$image_id]) ? $before[$image_id][$column] : null,
        'new'       => $value,
        'source'    => 'apply',
        );
    }
  }

  return array(
    'cat_id' => $cat_id,
    'applied' => count($image_ids),
    'changed' => provenance_record_changes($changes),
    );
}

/**
 * Saves one photo's own provenance note.
 *
 * The only column this method writes is provenance_note. The four album-sourced
 * columns are album-authoritative (decision C3) and are changed by an album
 * operation or not at all, so a photo can never drift away from its album
 * through this screen.
 *
 * picture_modify.php's own form posts to itself with a hard-coded set of fields
 * and no hook, so - like the album screen - the block carries its own button.
 *
 * @param array $param
 * @param object $service
 * @return array|PwgError
 */
function ws_provenance_setPhotoInfo($param, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $param['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $image_id = (int)$param['image_id'];

  $result = pwg_query('
SELECT provenance_note
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$image_id.'
;');

  if (!pwg_db_num_rows($result))
  {
    return new PwgError(404, 'Photo not found');
  }

  list($before) = pwg_db_fetch_row($result);

  $note = provenance_clean_note($param['note']);

  pwg_query('
UPDATE '.IMAGES_TABLE.'
  SET provenance_note = '.($note === '' ? 'NULL' : '"'.pwg_db_real_escape_string($note).'"').'
  WHERE id = '.$image_id.'
;');

  return array(
    'image_id' => $image_id,
    'changed' => provenance_record_change('photo', $image_id, 'provenance_note', $before, $note, 'photo_edit'),
    );
}

/**
 * Writes the provenance of one chunk of photos into their files.
 *
 * The chunk is far smaller than the copy-down's (PROVENANCE_WRITEBACK_MAX_CHUNK
 * against PROVENANCE_APPLY_MAX_CHUNK): one exiftool invocation costs orders of
 * magnitude more than an UPDATE, and the production 60 s request ceiling is the
 * same for both.
 *
 * A failed photo does not abandon the chunk (decision 13a) - the whole album is
 * unusable otherwise the day one file turns out to be read-only - so the answer
 * carries both counts and the caller decides what to say about them.
 *
 * @param array $param
 * @param object $service
 * @return array|PwgError
 */
function ws_provenance_writeBack($param, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $param['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  // Checked before anything is read or written: on a server without exec() the
  // feature degrades to the database half rather than failing halfway through.
  if (!provenance_exiftool_available())
  {
    return new PwgError(501, 'exiftool is not available on this server');
  }

  $image_ids = provenance_parse_id_list($param['image_ids'], PROVENANCE_WRITEBACK_MAX_CHUNK);
  if ($image_ids === null)
  {
    return new PwgError(400, 'image_ids must be at most '.PROVENANCE_WRITEBACK_MAX_CHUNK.' comma-separated photo ids');
  }

  if (empty($image_ids))
  {
    return array('written' => 0, 'failed' => array());
  }

  $images = query2array('
SELECT id, path, '.implode(', ', array_keys(provenance_image_columns())).'
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $image_ids).')
;');

  if (count($images) != count($image_ids))
  {
    return new PwgError(404, 'Photo not found');
  }

  load_language('plugin.lang', PROVENANCE_PATH);

  return provenance_write_back($images, array_map('l10n', provenance_caption_label_keys()));
}
