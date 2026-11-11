<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * Undo of a date, info or tag entry of a photo's change list
 * (pwg.photoinfo.undo). The first half declares pure functions only: who may
 * undo, whether the value is still the entry's, which entries are undoable and
 * which refusal wins. The second half reads the database and applies the
 * inverse through the same functions as the original write (file write and
 * lock included), then appends an entry with undoes_id set and source undo.
 *
 * A region entry is undone by persons, asked through the persons_undo_region
 * event (photoinfo_undo_region()); without persons active nobody answers and
 * the undo is refused.
 */

/** The refusals, as the language-file keys their texts live under. */
define('PHOTOINFO_UNDO_NOT_ALLOWED', 'Undo not allowed');
define('PHOTOINFO_UNDO_ALREADY_UNDONE', 'Already undone');
define('PHOTOINFO_UNDO_CHANGED_SINCE', 'Changed meanwhile by %s');
/** Names the account that changed a value when no entry says who. */
define('PHOTOINFO_UNDO_SOMEONE_ELSE', 'someone else');
define('PHOTOINFO_UNDO_NO_PERSONS', 'Persons is switched off');
/** The actions persons_undo_region answers; persons names them the same. */
define('PHOTOINFO_UNDO_REGION_STATE', 'state');
define('PHOTOINFO_UNDO_REGION_REMOVE', 'remove');
define('PHOTOINFO_UNDO_REGION_RESTORE', 'restore');

/**
 * The fields the change list offers Rückgängig for. A field joins when its
 * undo is implemented in photoinfo_undo_apply().
 *
 * @return string[]
 */
function photoinfo_history_undo_fields()
{
  return array(PROVENANCE_HISTORY_FIELD_DATE, PROVENANCE_HISTORY_FIELD_INFO, PROVENANCE_HISTORY_FIELD_TAG,
    PROVENANCE_HISTORY_FIELD_REGION);
}

/**
 * Own entries for everybody, every entry for an administrator.
 *
 * @param int|null $performed_by the entry's account, null when nobody was logged in
 * @param int $viewer_id
 * @param bool $is_admin is_admin(): a webmaster counts
 * @return bool
 */
function photoinfo_history_may_undo($performed_by, $viewer_id, $is_admin)
{
  return $is_admin or ($performed_by !== null and (int)$performed_by === (int)$viewer_id);
}

/**
 * Whether the value now is the one the entry wrote. Both sides are the same
 * kind of thing for one field: normalized text, or a tag id (null for none).
 *
 * @param mixed $current
 * @param mixed $entry_new
 * @return bool
 */
function photoinfo_history_is_current($current, $entry_new)
{
  return $current === $entry_new;
}

/**
 * @param array $entry field, undoes_id, undone
 * @return bool a plain entry of an undoable field that nothing undid yet
 */
function photoinfo_history_entry_is_undoable($entry)
{
  return photoinfo_history_is_undoable($entry['field'])
    and $entry['undoes_id'] === null
    and empty($entry['undone']);
}

/**
 * Whether the change list shows Rückgängig on an entry to its viewer. History.js
 * repeats this rule for the rows it builds; the server answers every click anyway.
 *
 * @param array $entry field, undoes_id, undone
 * @param bool $own the viewer made the entry
 * @param bool $is_admin
 * @return bool
 */
function photoinfo_history_offers_undo($entry, $own, $is_admin)
{
  return photoinfo_history_entry_is_undoable($entry)
    and in_array($entry['field'], photoinfo_history_undo_fields(), true)
    and ($own or $is_admin);
}

/**
 * The first refusal that applies: rights first, so nothing about an entry the
 * caller has no right to is told; then the entry's own state; then the value.
 *
 * @param array $entry field, undoes_id, undone
 * @param bool $may_undo photoinfo_history_may_undo()
 * @param bool $is_current photoinfo_history_is_current()
 * @return string|null a PHOTOINFO_UNDO_* key, null when the undo may run
 */
function photoinfo_history_undo_refusal($entry, $may_undo, $is_current)
{
  if (!$may_undo)
  {
    return PHOTOINFO_UNDO_NOT_ALLOWED;
  }
  if (!empty($entry['undone']))
  {
    return PHOTOINFO_UNDO_ALREADY_UNDONE;
  }
  if (!photoinfo_history_entry_is_undoable($entry))
  {
    return PHOTOINFO_UNDO_NOT_ALLOWED;
  }
  if (!$is_current)
  {
    return PHOTOINFO_UNDO_CHANGED_SINCE;
  }

  return null;
}

/*
 * ---------------------------------------------------------------------------
 * Below here: Piwigo's database and language.
 * ---------------------------------------------------------------------------
 */

/**
 * @param int $history_id
 * @return array|null the entry of a photo with 'undone', null for none
 */
function photoinfo_undo_entry($history_id)
{
  $rows = query2array('
SELECT id, object_id, field, old_value, new_value, performed_by, undoes_id
  FROM '.PROVENANCE_HISTORY_TABLE.'
  WHERE id = '.(int)$history_id.'
    AND object = "photo"
;');
  if (empty($rows))
  {
    return null;
  }

  $row = $rows[0];
  $row['id'] = (int)$row['id'];
  $row['object_id'] = (int)$row['object_id'];
  $row['performed_by'] = $row['performed_by'] === null ? null : (int)$row['performed_by'];
  $row['undoes_id'] = $row['undoes_id'] === null ? null : (int)$row['undoes_id'];
  $row['undone'] = count(provenance_undone_ids(array($row['id']))) > 0;

  return $row;
}

/**
 * What the photo holds now for the entry's field, as photoinfo_history_is_current()
 * compares it with photoinfo_undo_expected().
 *
 * @param array $entry
 * @param array $image photoinfo_image_row()
 * @return mixed
 */
function photoinfo_undo_current($entry, $image)
{
  switch ($entry['field'])
  {
    case PROVENANCE_HISTORY_FIELD_DATE:
      return provenance_history_normalize(photoinfo_history_date_value(photoinfo_dating_from_row($image)));
    case PROVENANCE_HISTORY_FIELD_INFO:
      return provenance_history_normalize($image['comment']);
    case PROVENANCE_HISTORY_FIELD_TAG:
      $tag = provenance_history_decode_tag($entry['new_value'] !== null ? $entry['new_value'] : $entry['old_value']);
      if ($tag === null or !photoinfo_tag_exists($tag['id']))
      {
        return false;
      }
      return in_array($tag['id'], photoinfo_tag_ids_of($image['id']), true) ? $tag['id'] : null;
    case PROVENANCE_HISTORY_FIELD_REGION:
      $answer = photoinfo_undo_ask_persons($image['id'], PHOTOINFO_UNDO_REGION_STATE, $entry);
      return $answer === null ? null : $answer['present'];
  }

  return false;
}

/**
 * What photoinfo_undo_current() must equal for the undo to run.
 *
 * @param array $entry
 * @return mixed
 */
function photoinfo_undo_expected($entry)
{
  if ($entry['field'] == PROVENANCE_HISTORY_FIELD_TAG)
  {
    $tag = provenance_history_decode_tag($entry['new_value']);
    return $tag === null ? null : $tag['id'];
  }
  if ($entry['field'] == PROVENANCE_HISTORY_FIELD_REGION)
  {
    return $entry['new_value'] !== null;
  }

  return provenance_history_normalize($entry['new_value']);
}

/**
 * @param int $tag_id
 * @return bool
 */
function photoinfo_tag_exists($tag_id)
{
  $rows = query2array('SELECT id FROM '.TAGS_TABLE.' WHERE id = '.(int)$tag_id.';');

  return count($rows) > 0;
}

/**
 * The name for "Wurde inzwischen von X geändert": the account of the newest
 * later entry on the same value of the same photo.
 *
 * @param array $entry
 * @return string
 */
function photoinfo_undo_changed_by($entry)
{
  global $conf;

  $where = '';
  if ($entry['field'] == PROVENANCE_HISTORY_FIELD_TAG)
  {
    $tag = provenance_history_decode_tag($entry['new_value'] !== null ? $entry['new_value'] : $entry['old_value']);
    $where = $tag === null ? '' : '
    AND (new_value LIKE "{\"id\":'.(int)$tag['id'].',%" OR old_value LIKE "{\"id\":'.(int)$tag['id'].',%")';
  }

  if ($entry['field'] == PROVENANCE_HISTORY_FIELD_REGION)
  {
    $region = provenance_history_decode_region($entry['new_value'] !== null ? $entry['new_value'] : $entry['old_value']);
    $prefix = photoinfo_like_prefix('{"name":'.json_encode($region === null ? '' : $region['name'], JSON_UNESCAPED_UNICODE).',');
    $where = '
    AND (new_value LIKE "'.$prefix.'%" OR old_value LIKE "'.$prefix.'%")';
  }

  $rows = query2array('
SELECT performed_by
  FROM '.PROVENANCE_HISTORY_TABLE.'
  WHERE object = "photo"
    AND object_id = '.(int)$entry['object_id'].'
    AND field = "'.pwg_db_real_escape_string($entry['field']).'"
    AND id > '.(int)$entry['id'].$where.'
  ORDER BY id DESC
  LIMIT 1
;');

  if (!empty($rows) and $rows[0]['performed_by'] !== null)
  {
    $names = query2array('
SELECT '.$conf['user_fields']['username'].' AS username
  FROM '.USERS_TABLE.'
  WHERE '.$conf['user_fields']['id'].' = '.(int)$rows[0]['performed_by'].'
;', null, 'username');
    if (!empty($names))
    {
      return $names[0];
    }
  }

  return l10n(PHOTOINFO_UNDO_SOMEONE_ELSE);
}

/**
 * The refusal's text in the caller's language.
 *
 * @param string $refusal a PHOTOINFO_UNDO_* key
 * @param array $entry
 * @return string
 */
function photoinfo_undo_refusal_text($refusal, $entry)
{
  load_language('plugin.lang', PHOTOINFO_PATH);

  if ($refusal == PHOTOINFO_UNDO_CHANGED_SINCE)
  {
    if ($entry['field'] == PROVENANCE_HISTORY_FIELD_REGION
      and photoinfo_undo_ask_persons($entry['object_id'], PHOTOINFO_UNDO_REGION_STATE, $entry) === null)
    {
      return l10n(PHOTOINFO_UNDO_NO_PERSONS);
    }
    return sprintf(l10n($refusal), photoinfo_undo_changed_by($entry));
  }

  return l10n($refusal);
}

/**
 * Applies the inverse of one entry and appends the undo entry.
 *
 * @param array $entry photoinfo_undo_entry()
 * @param array $image photoinfo_image_row()
 * @return array|string answer fields (history_id, written, message, ...),
 *   or a refusal text for a value that cannot be restored
 */
function photoinfo_undo_apply($entry, $image)
{
  switch ($entry['field'])
  {
    case PROVENANCE_HISTORY_FIELD_DATE:
      return photoinfo_undo_date($entry, $image);
    case PROVENANCE_HISTORY_FIELD_INFO:
      return photoinfo_undo_info($entry, $image);
    case PROVENANCE_HISTORY_FIELD_TAG:
      return photoinfo_undo_tag($entry, $image);
    case PROVENANCE_HISTORY_FIELD_REGION:
      return photoinfo_undo_region($entry, $image);
  }

  return l10n(PHOTOINFO_UNDO_NOT_ALLOWED);
}

/**
 * The undo entry: the entry's values swapped, pointing back at it.
 *
 * @param array $entry
 * @return int|null the new entry's id
 */
function photoinfo_undo_record($entry)
{
  return photoinfo_history_insert($entry['object_id'], $entry['field'],
    $entry['new_value'], $entry['old_value'], 'UNDO', $entry['id']);
}

/**
 * @param array $entry
 * @param array $image
 * @return array|string
 */
function photoinfo_undo_date($entry, $image)
{
  $dating = null;
  $old = photoinfo_history_date_parse($entry['old_value']);
  if ($old !== null)
  {
    $parsed = photoinfo_dating_from_edtf($old['edtf'], (int)date('Y'));
    if ($parsed['error'] !== null)
    {
      return l10n(PHOTOINFO_UNDO_NOT_ALLOWED);
    }
    $dating = $parsed['dating'];
  }
  elseif ($entry['old_value'] !== null)
  {
    return l10n(PHOTOINFO_UNDO_NOT_ALLOWED);
  }

  $columns = photoinfo_dating_columns($dating);
  photoinfo_update_image($image['id'], $columns);
  pwg_activity('photo', $image['id'], 'edit');
  $history_id = photoinfo_undo_record($entry);

  $result = photoinfo_write_file(array_merge($image, $columns), PHOTOINFO_WRITE_DATE);

  return photoinfo_undo_answer($image, $history_id, $result);
}

/**
 * @param array $entry
 * @param array $image
 * @return array
 */
function photoinfo_undo_info($entry, $image)
{
  $info = $entry['old_value'];
  pwg_query('
UPDATE '.IMAGES_TABLE.'
  SET comment = '.($info === null ? 'NULL' : '\''.pwg_db_real_escape_string($info).'\'').'
  WHERE id = '.(int)$image['id'].'
;');
  pwg_activity('photo', $image['id'], 'edit');
  $history_id = photoinfo_undo_record($entry);

  $image['comment'] = $info;
  $result = photoinfo_write_file($image, PHOTOINFO_WRITE_INFO);

  return photoinfo_undo_answer($image, $history_id, $result);
}

/**
 * Takes a tag off the photo that the entry added, or puts back one it removed.
 *
 * @param array $entry
 * @param array $image
 * @return array
 */
function photoinfo_undo_tag($entry, $image)
{
  $added = $entry['new_value'] !== null;
  $tag = provenance_history_decode_tag($added ? $entry['new_value'] : $entry['old_value']);
  if ($tag === null)
  {
    return l10n(PHOTOINFO_UNDO_NOT_ALLOWED);
  }

  if ($added)
  {
    pwg_query('DELETE FROM '.IMAGE_TAG_TABLE.' WHERE image_id = '.(int)$image['id'].' AND tag_id = '.(int)$tag['id'].';');
  }
  else
  {
    pwg_query('INSERT IGNORE INTO '.IMAGE_TAG_TABLE.' (image_id, tag_id) VALUES ('.(int)$image['id'].', '.(int)$tag['id'].');');
  }
  if (function_exists('typetags_invalidate_tag_lists'))
  {
    typetags_invalidate_tag_lists();
  }
  pwg_activity('photo', $image['id'], 'edit');
  $history_id = photoinfo_undo_record($entry);

  return array_merge(
    array('image_id' => (int)$image['id'], 'history_id' => $history_id),
    photoinfo_tag_write_answer(photoinfo_write_tags(array($image['id'])))
    );
}

/**
 * @param array $image
 * @param int|null $history_id
 * @param array $result photoinfo_write_file()'s answer
 * @return array
 */
function photoinfo_undo_answer($image, $history_id, $result)
{
  return array(
    'image_id' => (int)$image['id'],
    'history_id' => $history_id,
    'written' => $result['ok'],
    'message' => $result['message'],
    'message_l10n' => photoinfo_l10n_reason($result['message']),
    );
}

/**
 * A literal as the start of a double-quoted LIKE pattern: the wildcards and the
 * escape character taken literally, then the string escaped.
 *
 * @param string $text
 * @return string
 */
function photoinfo_like_prefix($text)
{
  return pwg_db_real_escape_string(str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $text));
}

/**
 * Asks persons about, or to change, the region an entry names.
 *
 * @param int $image_id
 * @param string $action PHOTOINFO_UNDO_REGION_STATE, PHOTOINFO_UNDO_REGION_REMOVE or PHOTOINFO_UNDO_REGION_RESTORE
 * @param array $entry
 * @return array|null persons' answer, null when persons is not active or the entry holds no region
 */
function photoinfo_undo_ask_persons($image_id, $action, $entry)
{
  global $user;

  $region = provenance_history_decode_region($entry['new_value'] !== null ? $entry['new_value'] : $entry['old_value']);
  if ($region === null)
  {
    return null;
  }

  $answer = trigger_change('persons_undo_region', null, (int)$image_id, $action, $region, (int)$user['id'], is_admin());

  return is_array($answer) ? $answer : null;
}

/**
 * Takes a box off the photo that the entry added, or puts back one it
 * removed with its original drawer. persons does the change; the one undo
 * entry is written here, since persons_apply_change() announces nothing.
 *
 * @param array $entry
 * @param array $image
 * @return array|string
 */
function photoinfo_undo_region($entry, $image)
{
  $added = $entry['new_value'] !== null;
  $answer = photoinfo_undo_ask_persons($image['id'],
    $added ? PHOTOINFO_UNDO_REGION_REMOVE : PHOTOINFO_UNDO_REGION_RESTORE, $entry);

  if ($answer === null)
  {
    return photoinfo_undo_refusal_text(PHOTOINFO_UNDO_NO_PERSONS, $entry);
  }
  if (!$answer['ok'])
  {
    return $answer['forbidden'] ? photoinfo_undo_refusal_text(PHOTOINFO_UNDO_NOT_ALLOWED, $entry) : $answer['message'];
  }

  pwg_activity('photo', $image['id'], 'edit');

  return array(
    'image_id' => (int)$image['id'],
    'history_id' => photoinfo_undo_record($entry),
    'written' => true,
    'message' => '',
    'message_l10n' => '',
    );
}
