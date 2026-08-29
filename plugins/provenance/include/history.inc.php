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
 * and unit-tested; only provenance_record_changes() touches the database.
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
 * @param array $change object, object_id, field, old, new, source
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

  return array(
    'object'       => $change['object'],
    'object_id'    => $object_id,
    'field'        => $field,
    'old_value'    => $old,
    'new_value'    => $new,
    'source'       => $change['source'],
    'performed_by' => $performed_by === null ? null : (int)$performed_by,
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
  return array('object', 'object_id', 'field', 'old_value', 'new_value', 'source', 'performed_by');
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
 * @param array $changes each: object, object_id, field, old, new, source
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
