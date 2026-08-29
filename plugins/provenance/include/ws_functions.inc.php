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
