<?php
defined('PROVENANCE_PATH') or die('Hacking attempt!');

/*
 * History entries as people read them: "„Kirche“ hinzugefügt", "Datum
 * geändert: ca. 1955 → vor 1960", "heute, 14:05", "maria.t (Sie)".
 *
 * Used by provenance's read-only page and, through pwg.provenance.getHistory,
 * by photoinfo's list on the picture page. photoinfo requires provenance, so
 * the formatter lives here and calls nothing of photoinfo.
 *
 * The first half is pure: it takes a translator callable (key => text) and, for
 * time, the current time as a string. Only provenance_history_display_rows()
 * and the helpers below it touch Piwigo.
 */

/**
 * The fields the picture page's list shows. Write-back rows (file caption, file
 * errors) and the album-level values stay on the read-only page.
 *
 * @return array
 */
function provenance_history_list_fields()
{
  return array(
    PROVENANCE_HISTORY_FIELD_DATE,
    PROVENANCE_HISTORY_FIELD_INFO,
    PROVENANCE_HISTORY_FIELD_TAG,
    PROVENANCE_HISTORY_FIELD_REGION,
    PROVENANCE_HISTORY_FIELD_PHOTO,
    );
}

/**
 * A date entry's value is "<display> (<edtf>)"; the display is what people read.
 *
 * @param string|null $value
 * @return string|null
 */
function provenance_history_date_display($value)
{
  if (!is_string($value) or $value === '')
  {
    return null;
  }

  $cut = strrpos($value, ' (');

  return $cut === false ? $value : substr($value, 0, $cut);
}

/**
 * A photo-edit entry's value: {"turns":0-3,"crop":{"w":int,"h":int}|null}.
 *
 * @param string|null $value
 * @return array|null turned (bool), cropped (bool); null for NULL, malformed JSON or a wrong shape
 */
function provenance_history_decode_photo($value)
{
  $data = provenance_history_decode_object($value);

  if ($data === null or !isset($data['turns']) or !is_int($data['turns']) or $data['turns'] < 0 or $data['turns'] > 3)
  {
    return null;
  }

  $crop = isset($data['crop']) ? $data['crop'] : null;
  if ($crop !== null and !is_array($crop))
  {
    return null;
  }

  $turned = $data['turns'] > 0;
  $cropped = $crop !== null;

  return ($turned or $cropped) ? array('turned' => $turned, 'cropped' => $cropped) : null;
}

/**
 * One stored value as a table cell of the read-only page shows it: a tag or
 * person by name, a photo edit in words, anything else as stored.
 *
 * @param string $field
 * @param string|null $value
 * @param callable $t
 * @return string
 */
function provenance_history_value_text($field, $value, $t)
{
  if ($value === null)
  {
    return '';
  }

  if ($field === PROVENANCE_HISTORY_FIELD_TAG)
  {
    $tag = provenance_history_decode_tag($value);
    return $tag === null ? $value : $tag['name'];
  }

  if ($field === PROVENANCE_HISTORY_FIELD_REGION)
  {
    $region = provenance_history_decode_region($value);
    return $region === null ? $value : $region['name'];
  }

  if ($field === PROVENANCE_HISTORY_FIELD_PHOTO)
  {
    $edit = provenance_history_decode_photo($value);
    return $edit === null ? $value : provenance_history_photo_text($edit, $t);
  }

  return $value;
}

/**
 * @param array $edit turned, cropped
 * @param callable $t
 * @return string
 */
function provenance_history_photo_text($edit, $t)
{
  if ($edit['turned'] and $edit['cropped'])
  {
    return $t('Photo turned and cropped');
  }

  return $edit['turned'] ? $t('Photo turned') : $t('Photo cropped');
}

/**
 * What one entry says happened.
 *
 * @param array $row field, old_value, new_value
 * @param callable $t translator, key => text
 * @return string plain text, not HTML
 */
function provenance_history_entry_text($row, $t)
{
  $old = $row['old_value'];
  $new = $row['new_value'];

  switch ($row['field'])
  {
    case PROVENANCE_HISTORY_FIELD_DATE:
      $before = provenance_history_date_display($old);
      $after = provenance_history_date_display($new);
      if ($before !== null and $after !== null)
      {
        return sprintf($t('Date changed: %s → %s'), $before, $after);
      }
      return $after !== null
        ? sprintf($t('Date set: %s'), $after)
        : sprintf($t('Date removed: %s'), (string)$before);

    case PROVENANCE_HISTORY_FIELD_INFO:
      if ($old === null)
      {
        return $t('Info added');
      }
      return $new === null ? $t('Info removed') : $t('Info changed');

    case PROVENANCE_HISTORY_FIELD_TAG:
      $added = provenance_history_decode_tag($new);
      if ($added !== null)
      {
        return sprintf($t('Tag %s added'), $added['name']);
      }
      $removed = provenance_history_decode_tag($old);
      if ($removed !== null)
      {
        return sprintf($t('Tag %s removed'), $removed['name']);
      }
      break;

    case PROVENANCE_HISTORY_FIELD_REGION:
      $after = provenance_history_decode_region($new);
      $before = provenance_history_decode_region($old);
      if ($after !== null and $before !== null)
      {
        return sprintf($t('Person changed: %s → %s'), $before['name'], $after['name']);
      }
      if ($after !== null)
      {
        return sprintf($t('Person %s marked'), $after['name']);
      }
      if ($before !== null)
      {
        return sprintf($t('Person mark %s removed'), $before['name']);
      }
      break;

    case PROVENANCE_HISTORY_FIELD_PHOTO:
      $edit = provenance_history_decode_photo($new);
      if ($edit !== null)
      {
        return provenance_history_photo_text($edit, $t);
      }
      break;
  }

  $labels = provenance_history_field_label_keys();

  return sprintf($t('%s changed'), isset($labels[$row['field']]) ? $t($labels[$row['field']]) : $row['field']);
}

/**
 * "today, 14:05", "yesterday, 18:42", else the date as $older makes it.
 * The day is the calendar day, so 00:10 is yesterday's neighbour, not "24 hours ago".
 *
 * @param string $occured_on 'Y-m-d H:i:s'
 * @param string $now 'Y-m-d H:i:s'
 * @param callable $t
 * @param callable $older occured_on => text
 * @return string
 */
function provenance_history_when($occured_on, $now, $t, $older)
{
  $day = substr($occured_on, 0, 10);
  $today = substr($now, 0, 10);
  $yesterday = (new DateTime($today))->modify('-1 day')->format('Y-m-d');
  $time = substr($occured_on, 11, 5);

  if ($day === $today)
  {
    return sprintf($t('today, %s'), $time);
  }
  if ($day === $yesterday)
  {
    return sprintf($t('yesterday, %s'), $time);
  }

  return $older($occured_on);
}

/**
 * Who made the change: the name, "(you)" after the viewer's own, "deleted user"
 * for an account that is gone.
 *
 * @param int|null $performed_by null when nobody was logged in (sync, CLI)
 * @param array $names user id => username
 * @param int|null $viewer_id
 * @param callable $t
 * @return string empty for a change nobody made
 */
function provenance_history_user_text($performed_by, $names, $viewer_id, $t)
{
  if ($performed_by === null)
  {
    return '';
  }
  if (!isset($names[$performed_by]))
  {
    return $t('deleted user');
  }

  $name = $names[$performed_by];

  return $performed_by === $viewer_id ? $name.' '.$t('(you)') : $name;
}

/*
 * ---------------------------------------------------------------------------
 * Below here: Piwigo's database and language.
 * ---------------------------------------------------------------------------
 */

/**
 * @param array $rows from provenance_fetch_history()
 * @return array user id => username, for the accounts that still exist
 */
function provenance_history_usernames($rows)
{
  global $conf;

  $user_ids = array();
  foreach ($rows as $row)
  {
    if ($row['performed_by'] !== null)
    {
      $user_ids[$row['performed_by']] = true;
    }
  }

  if (empty($user_ids))
  {
    return array();
  }

  return query2array('
SELECT '.$conf['user_fields']['id'].' AS id, '.$conf['user_fields']['username'].' AS username
  FROM '.USERS_TABLE.'
  WHERE '.$conf['user_fields']['id'].' IN ('.implode(',', array_keys($user_ids)).')
;', 'id', 'username');
}

/**
 * Adds a 'display' entry (text, by, when, own, undone) to each row.
 *
 * @param array $rows from provenance_fetch_history()
 * @return array
 */
function provenance_history_display_rows($rows)
{
  global $user;

  load_language('plugin.lang', PROVENANCE_PATH);

  $t = function ($key) { return l10n($key); };
  $older = function ($occured_on) { return format_date($occured_on, array('day', 'month', 'year')); };
  $names = provenance_history_usernames($rows);
  $viewer = isset($user['id']) ? (int)$user['id'] : null;
  // occured_on is the database's clock; PHP's may run in another time zone.
  list($now) = pwg_db_fetch_row(pwg_query('SELECT NOW();'));

  foreach ($rows as $i => $row)
  {
    $rows[$i]['display'] = array(
      'text' => provenance_history_entry_text($row, $t),
      'by' => provenance_history_user_text($row['performed_by'], $names, $viewer, $t),
      'when' => provenance_history_when($row['occured_on'], $now, $t, $older),
      'own' => $row['performed_by'] !== null and $row['performed_by'] === $viewer,
      'undone' => !empty($row['undone']),
      );
  }

  return $rows;
}

/**
 * The SQL condition restricting a history query to some fields.
 *
 * @param array $fields
 * @return string already escaped
 */
function provenance_history_field_condition($fields)
{
  $quoted = array();
  foreach ($fields as $field)
  {
    $quoted[] = '"'.pwg_db_real_escape_string($field).'"';
  }

  return 'field IN ('.implode(', ', $quoted).')';
}
