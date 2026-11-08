<?php
defined('PROVENANCE_PATH') or die('Hacking attempt!');

/*
 * The value-level audit trail. Every provenance write in the plugin goes through
 * provenance_record_change() or provenance_record_changes(), so no write path can
 * be added that forgets to log.
 *
 * A field whose value did not change writes no row: the trail records changes,
 * not saves. Without that rule a re-apply of unchanged text over an album of 76
 * photos would write 380 rows saying nothing happened.
 *
 * The file declares functions and nothing else. The shaping half below is pure
 * and unit-tested; only provenance_record_changes() and provenance_fetch_history()
 * touch the database.
 */

/**
 * Normalises a provenance value for storage and comparison.
 *
 * NULL and the empty string are the same absence: the database stores a cleared
 * field as NULL while a form posts it as '', and a save that clears an already
 * empty field is not a change. Whitespace is deliberately *not* trimmed - the
 * note is free text, and removing a trailing newline is a real edit.
 *
 * @param mixed $value
 * @return string|null
 */
function provenance_history_normalize($value)
{
  if ($value === null)
  {
    return null;
  }

  $value = (string)$value;

  return $value === '' ? null : $value;
}

/**
 * Shapes one change into a history row, or returns null if nothing changed.
 *
 * Validates against the enums the schema declares rather than trusting the
 * caller: MySQL stores an out-of-range enum value as '', which would leave a row
 * that silently claims nothing.
 *
 * @param array $change object, object_id, field, old, new, source; optional undoes_id
 * @param int|null $performed_by acting user, null when nobody was logged in
 * @return array|null
 * @throws InvalidArgumentException on an unknown enum value or an unusable field
 */
function provenance_history_row($change, $performed_by)
{
  if (!in_array($change['object'], provenance_history_objects(), true))
  {
    throw new InvalidArgumentException('Unknown provenance history object: '.$change['object']);
  }

  if (!in_array($change['source'], provenance_history_sources(), true))
  {
    throw new InvalidArgumentException('Unknown provenance history source: '.$change['source']);
  }

  $object_id = (int)$change['object_id'];
  if ($object_id <= 0)
  {
    throw new InvalidArgumentException('Provenance history needs a positive object id');
  }

  $field = (string)$change['field'];
  if ($field === '' or strlen($field) > PROVENANCE_HISTORY_FIELD_MAX_BYTES)
  {
    throw new InvalidArgumentException('Unusable provenance history field name: '.$field);
  }

  $old = provenance_history_normalize($change['old']);
  $new = provenance_history_normalize($change['new']);

  if ($old === $new)
  {
    return null;
  }

  $undoes_id = isset($change['undoes_id']) ? (int)$change['undoes_id'] : 0;
  if ($undoes_id < 0)
  {
    throw new InvalidArgumentException('Provenance history undoes_id must be positive');
  }

  return array(
    'object'       => $change['object'],
    'object_id'    => $object_id,
    'field'        => $field,
    'old_value'    => $old,
    'new_value'    => $new,
    'source'       => $change['source'],
    'performed_by' => $performed_by === null ? null : (int)$performed_by,
    'undoes_id'    => $undoes_id > 0 ? $undoes_id : null,
    );
}

/**
 * Shapes a batch, dropping the entries that changed nothing.
 *
 * @param array $changes
 * @param int|null $performed_by
 * @return array rows in the order they were given
 */
function provenance_history_rows($changes, $performed_by)
{
  $rows = array();

  foreach ($changes as $change)
  {
    $row = provenance_history_row($change, $performed_by);

    if ($row !== null)
    {
      $rows[] = $row;
    }
  }

  return $rows;
}

/**
 * The columns provenance_record_changes() writes, in mass_inserts() order.
 *
 * @return array
 */
function provenance_history_insert_columns()
{
  return array('object', 'object_id', 'field', 'old_value', 'new_value', 'source', 'performed_by', 'undoes_id');
}

/**
 * The acting user, or null when the write has no session behind it (filesystem
 * sync, a CLI run).
 *
 * @return int|null
 */
function provenance_history_actor()
{
  global $user;

  return isset($user['id']) ? (int)$user['id'] : null;
}

/**
 * Records a batch of changes in one insert.
 *
 * mass_inserts() applies no escaping of its own, so values are escaped here.
 *
 * @param array $changes each: object, object_id, field, old, new, source; optional undoes_id
 * @return int rows written
 */
function provenance_record_changes($changes)
{
  $rows = provenance_history_rows($changes, provenance_history_actor());

  if (empty($rows))
  {
    return 0;
  }

  foreach ($rows as $i => $row)
  {
    foreach (array('object', 'field', 'old_value', 'new_value', 'source') as $column)
    {
      if ($row[$column] !== null)
      {
        $rows[$i][$column] = pwg_db_real_escape_string($row[$column]);
      }
    }
  }

  mass_inserts(PROVENANCE_HISTORY_TABLE, provenance_history_insert_columns(), $rows);

  return count($rows);
}

/**
 * Records a single change. Thin wrapper so there is one insert path, not two.
 *
 * @param string $object 'album' or 'photo'
 * @param int $object_id
 * @param string $field
 * @param mixed $old
 * @param mixed $new
 * @param string $source
 * @return int rows written: 1, or 0 when the value did not change
 */
function provenance_record_change($object, $object_id, $field, $old, $new, $source)
{
  return provenance_record_changes(array(array(
    'object'    => $object,
    'object_id' => $object_id,
    'field'     => $field,
    'old'       => $old,
    'new'       => $new,
    'source'    => $source,
    )));
}

/**
 * Reads one object's trail, newest first. The one query behind both
 * pwg.provenance.getHistory and the read-only provenance page.
 *
 * @param string $object 'album' or 'photo', already validated
 * @param int $object_id
 * @param int $limit
 * @param array $conditions further SQL conditions, already escaped
 * @return array total_count, rows
 */
function provenance_fetch_history($object, $object_id, $limit, $conditions = array())
{
  $where = array_merge(
    array(
      'object = "'.pwg_db_real_escape_string($object).'"',
      'object_id = '.(int)$object_id,
      ),
    $conditions
    );

  $where = 'WHERE '.implode("\n    AND ", $where);

  list($total_count) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM '.PROVENANCE_HISTORY_TABLE.'
  '.$where.'
;'));

  $rows = query2array('
SELECT id, object, object_id, field, old_value, new_value, source, performed_by, undoes_id, occured_on
  FROM '.PROVENANCE_HISTORY_TABLE.'
  '.$where.'
  ORDER BY occured_on DESC, id DESC
  LIMIT '.(int)$limit.'
;');

  foreach ($rows as $i => $row)
  {
    $rows[$i]['id'] = (int)$row['id'];
    $rows[$i]['object_id'] = (int)$row['object_id'];
    $rows[$i]['performed_by'] = $row['performed_by'] === null ? null : (int)$row['performed_by'];
    $rows[$i]['undoes_id'] = $row['undoes_id'] === null ? null : (int)$row['undoes_id'];
  }

  $undone = provenance_undone_ids(array_column($rows, 'id'));
  foreach ($rows as $i => $row)
  {
    $rows[$i]['undone'] = isset($undone[$row['id']]);
  }

  return array(
    'total_count' => (int)$total_count,
    'rows' => $rows,
    );
}

/**
 * Which of the given entries another entry undoes.
 *
 * The log is append-only: an undo is a new entry whose undoes_id names the
 * original, so "undone" is derived and never stored on the original.
 *
 * @param array $ids history ids
 * @return array id => true for each one that is undone
 */
function provenance_undone_ids($ids)
{
  $ids = array_map('intval', $ids);
  if (empty($ids))
  {
    return array();
  }

  $undone = query2array('
SELECT DISTINCT undoes_id
  FROM '.PROVENANCE_HISTORY_TABLE.'
  WHERE undoes_id IN ('.implode(',', $ids).')
;', 'undoes_id');

  return array_fill_keys(array_keys($undone), true);
}

/**
 * The value of a tag entry: {"id":5,"name":"Kirche"}.
 *
 * @param int $id
 * @param string $name
 * @return string
 */
function provenance_history_encode_tag($id, $name)
{
  return json_encode(array('id' => (int)$id, 'name' => (string)$name), JSON_UNESCAPED_UNICODE);
}

/**
 * @param string|null $value
 * @return array|null id, name; null for NULL, malformed JSON or a wrong shape
 */
function provenance_history_decode_tag($value)
{
  $data = provenance_history_decode_object($value);

  if ($data === null
    or !isset($data['id'], $data['name'])
    or !is_int($data['id']) or $data['id'] <= 0
    or !is_string($data['name']))
  {
    return null;
  }

  return array('id' => $data['id'], 'name' => $data['name']);
}

/**
 * The value of a region entry: {"name":"Anna","box":[x,y,w,h],"drawn_by":12}.
 * drawn_by is null for a region nobody drew.
 *
 * @param string $name
 * @param array $box x, y, width, height
 * @param int|null $drawn_by
 * @return string
 */
function provenance_history_encode_region($name, $box, $drawn_by)
{
  return json_encode(
    array(
      'name' => (string)$name,
      'box' => array_map('floatval', array_values($box)),
      'drawn_by' => $drawn_by === null ? null : (int)$drawn_by,
      ),
    JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
}

/**
 * @param string|null $value
 * @return array|null name, box (four numbers), drawn_by (int|null); null for NULL, malformed JSON or a wrong shape
 */
function provenance_history_decode_region($value)
{
  $data = provenance_history_decode_object($value);

  if ($data === null
    or !isset($data['name'], $data['box'])
    or !is_string($data['name'])
    or !is_array($data['box']) or count($data['box']) !== 4)
  {
    return null;
  }

  foreach ($data['box'] as $number)
  {
    if (!is_int($number) and !is_float($number))
    {
      return null;
    }
  }

  $drawn_by = isset($data['drawn_by']) ? $data['drawn_by'] : null;
  if ($drawn_by !== null and !is_int($drawn_by))
  {
    return null;
  }

  return array('name' => $data['name'], 'box' => array_values($data['box']), 'drawn_by' => $drawn_by);
}

/**
 * @param string|null $value
 * @return array|null the decoded JSON object, or null
 */
function provenance_history_decode_object($value)
{
  if (!is_string($value) or $value === '')
  {
    return null;
  }

  $data = json_decode($value, true);

  return is_array($data) ? $data : null;
}

/**
 * Whether the current account may read the history of an album or photo: an
 * administrator any object, anyone else only one it can see - the rule of the
 * read-only page (decision 0064). Guests read nothing.
 *
 * @param string $object 'album' or 'photo', already validated
 * @param int $object_id
 * @return bool
 */
function provenance_may_read_history($object, $object_id)
{
  if (is_a_guest())
  {
    return false;
  }

  if (is_admin())
  {
    return true;
  }

  if ($object === 'album')
  {
    $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.(int)$object_id.'
  '.get_sql_condition_FandF(
      array('forbidden_categories' => 'id', 'visible_categories' => 'id'),
      'AND'
      ).'
;';
  }
  else
  {
    $query = '
SELECT i.id
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE i.id = '.(int)$object_id.'
  '.get_sql_condition_FandF(
      array(
        'forbidden_categories' => 'ic.category_id',
        'visible_categories' => 'ic.category_id',
        'visible_images' => 'i.id',
        ),
      'AND'
      ).'
  LIMIT 1
;';
  }

  return count(query2array($query)) > 0;
}
