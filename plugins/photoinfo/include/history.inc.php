<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * The change log entries photoinfo writes for a photo's date and info text,
 * into provenance's value-level history (provenance_record_changes()).
 *
 * The first half declares pure functions only: the shape of a date entry and
 * the rule that merges consecutive info saves. The second half touches the
 * database. Nothing here is called from a rescan or an upload, which rebuild
 * values from the file and are not edits (design "What gets a row").
 */

/** Entries the picture page's change list shows before "alle anzeigen". */
define('PHOTOINFO_HISTORY_LIST_SIZE', 8);

/** Seconds within which one account's next info save joins its previous entry. */
define('PHOTOINFO_INFO_MERGE_SECONDS', 600);

/**
 * A date entry's value: the text people read, then the EDTF string in
 * parentheses - "ca. 1965 (1965~)". Plain text rather than JSON, so the
 * read-only provenance page shows something readable; the EDTF is what undo
 * restores, since it names qualifier, precision and range exactly.
 *
 * @param array|null $dating
 * @return string|null null for no date
 */
function photoinfo_history_date_value($dating)
{
  if ($dating === null)
  {
    return null;
  }

  return photoinfo_dating_display($dating).' ('.photoinfo_dating_edtf($dating).')';
}

/**
 * @param string|null $value a date entry's value
 * @return array|null display, edtf; null for no date or a value of another shape
 */
function photoinfo_history_date_parse($value)
{
  if (!is_string($value) or !preg_match('/^(.*) \(([^()]+)\)$/su', $value, $match))
  {
    return null;
  }

  return array('display' => $match[1], 'edtf' => $match[2]);
}

/**
 * Whether a new info entry updates the photo's latest entry instead of adding one.
 *
 * @param array|null $latest the photo's newest entry of any field: field,
 *   undoes_id (null unless it is an undo), performed_by, performed_at (unix
 *   time); null for none
 * @param int|null $performed_by the account saving now
 * @param int $now unix time
 * @param int $window seconds, inclusive
 * @return bool
 */
function photoinfo_history_should_merge($latest, $performed_by, $now, $window = PHOTOINFO_INFO_MERGE_SECONDS)
{
  return $latest !== null
    and $performed_by !== null
    and $latest['field'] === PROVENANCE_HISTORY_FIELD_INFO
    and $latest['undoes_id'] === null
    and $latest['performed_by'] === (int)$performed_by
    and $now - $latest['performed_at'] <= $window;
}

/**
 * Records a change of a photo's info text.
 *
 * @param int $image_id
 * @param string|null $old
 * @param string|null $new
 * @param string $source the end of a PROVENANCE_HISTORY_SOURCE_* name, e.g. 'PICTURE_PAGE'
 * @return int|null the entry's id; null when the text did not change or the
 *   merge brought it back to where the entry began
 */
function photoinfo_record_info($image_id, $old, $new, $source)
{
  if (!photoinfo_history_available())
  {
    return null;
  }

  $old = provenance_history_normalize($old);
  $new = provenance_history_normalize($new);
  if ($old === $new)
  {
    return null;
  }

  $latest = photoinfo_history_latest($image_id);
  if (photoinfo_history_should_merge($latest, provenance_history_actor(), $latest['now'] ?? 0))
  {
    return photoinfo_history_merge($latest, $new);
  }

  return photoinfo_history_insert($image_id, PROVENANCE_HISTORY_FIELD_INFO, $old, $new, $source);
}

/**
 * Records a change of a photo's date.
 *
 * @param int $image_id
 * @param array|null $old dating before
 * @param array|null $new dating after
 * @param string $source the end of a PROVENANCE_HISTORY_SOURCE_* name, e.g. 'PICTURE_PAGE'
 * @return int|null the entry's id; null when the date did not change
 */
function photoinfo_record_date($image_id, $old, $new, $source)
{
  if (!photoinfo_history_available())
  {
    return null;
  }

  return photoinfo_history_insert($image_id, PROVENANCE_HISTORY_FIELD_DATE,
    photoinfo_history_date_value($old), photoinfo_history_date_value($new), $source);
}

/**
 * Records what a save changed in a photo's date and info text, from two
 * photoinfo_core_edit_snapshot() rows.
 *
 * @param array $before
 * @param array|null $after null for a photo that is gone
 * @param string $source as above
 */
function photoinfo_record_edit($before, $after, $source)
{
  if ($after === null)
  {
    return;
  }

  $image_id = (int)$before['id'];

  photoinfo_record_date($image_id, photoinfo_dating_from_row($before), photoinfo_dating_from_row($after), $source);
  photoinfo_record_info($image_id, $before['comment'], $after['comment'], $source);
}

/**
 * Sources travel as the end of provenance's constant name: the core-screen
 * paths run while provenance is off, when the constants do not exist.
 *
 * @param string $source e.g. 'PICTURE_PAGE'
 * @return string
 */
function photoinfo_history_source($source)
{
  return constant('PROVENANCE_HISTORY_SOURCE_'.$source);
}

/**
 * @return bool whether provenance's history can take an entry
 */
function photoinfo_history_available()
{
  return defined('PROVENANCE_PATH') and function_exists('provenance_record_changes');
}

/**
 * @param int $image_id
 * @param string $field
 * @param string|null $old
 * @param string|null $new
 * @param string $source as above
 * @return int|null the new entry's id, null when nothing changed
 */
function photoinfo_history_insert($image_id, $field, $old, $new, $source)
{
  $written = provenance_record_changes(array(array(
    'object' => 'photo',
    'object_id' => $image_id,
    'field' => $field,
    'old' => $old,
    'new' => $new,
    'source' => photoinfo_history_source($source),
    )));

  // One row went in with one statement, so the connection's last id is its.
  return $written > 0 ? (int)pwg_db_insert_id() : null;
}

/**
 * The newest entry of a photo, whatever its field.
 *
 * @param int $image_id
 * @return array|null id, field, old_value, undoes_id (int|null), performed_by (int|null),
 *   performed_at, now (database unix times, so no clock of PHP's enters)
 */
function photoinfo_history_latest($image_id)
{
  $rows = query2array('
SELECT id, field, old_value, undoes_id, performed_by, UNIX_TIMESTAMP(occured_on) AS performed_at, UNIX_TIMESTAMP() AS now
  FROM '.PROVENANCE_HISTORY_TABLE.'
  WHERE object = "photo"
    AND object_id = '.(int)$image_id.'
  ORDER BY id DESC
  LIMIT 1
;');

  if (empty($rows))
  {
    return null;
  }

  $row = $rows[0];
  $row['id'] = (int)$row['id'];
  $row['undoes_id'] = $row['undoes_id'] === null ? null : (int)$row['undoes_id'];
  $row['performed_by'] = $row['performed_by'] === null ? null : (int)$row['performed_by'];
  $row['performed_at'] = (int)$row['performed_at'];
  $row['now'] = (int)$row['now'];

  return $row;
}

/**
 * Makes the latest info entry end in a new text, and moves its time to now so
 * a text written over a longer sitting stays one entry. A text that is back at
 * where the entry began leaves nothing to undo: the entry goes.
 *
 * @param array $latest photoinfo_history_latest()
 * @param string|null $new
 * @return int|null the entry's id, null when it was removed
 */
function photoinfo_history_merge($latest, $new)
{
  if ($latest['old_value'] === $new)
  {
    pwg_query('DELETE FROM '.PROVENANCE_HISTORY_TABLE.' WHERE id = '.(int)$latest['id'].';');
    return null;
  }

  pwg_query('
UPDATE '.PROVENANCE_HISTORY_TABLE.'
  SET new_value = '.($new === null ? 'NULL' : '"'.pwg_db_real_escape_string($new).'"').',
      occured_on = NOW()
  WHERE id = '.(int)$latest['id'].'
;');

  return $latest['id'];
}
