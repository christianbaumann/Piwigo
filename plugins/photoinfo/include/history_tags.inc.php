<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * The change log entries photoinfo writes for a photo's tags, into
 * provenance's value-level history (provenance_record_changes()): one entry
 * per tag added or removed, value {"id","name"}.
 *
 * Called from the paths that edit tags: typetags' badge methods, the wrapped
 * pwg.images.setInfo, the admin photo screen and the Batch Manager. Not from
 * persons_tags_changed (the person tags follow a region, which has its own
 * entry), a rescan, pruneTags or an upload: none of them is an edit.
 */

/**
 * @param int[] $before tag ids of the photo before the change
 * @param int[] $after tag ids after it
 * @return array 'added' and 'removed': sorted tag ids
 */
function photoinfo_tag_changes($before, $after)
{
  $before = array_map('intval', $before);
  $after = array_map('intval', $after);

  $added = array_values(array_diff($after, $before));
  $removed = array_values(array_diff($before, $after));
  sort($added);
  sort($removed);

  return array('added' => $added, 'removed' => $removed);
}

/**
 * The history changes of one photo's tag change, an added tag before a removed one.
 *
 * @param int $image_id
 * @param int[] $before
 * @param int[] $after
 * @param array $names tag id => name, for every id that changed
 * @param string $source a PROVENANCE_HISTORY_SOURCE_* value
 * @return array provenance_record_changes()'s input
 */
function photoinfo_tag_history_changes($image_id, $before, $after, $names, $source)
{
  $diff = photoinfo_tag_changes($before, $after);
  $changes = array();

  foreach (array('added', 'removed') as $kind)
  {
    foreach ($diff[$kind] as $tag_id)
    {
      $value = provenance_history_encode_tag($tag_id, isset($names[$tag_id]) ? $names[$tag_id] : '');
      $changes[] = array(
        'object' => 'photo',
        'object_id' => $image_id,
        'field' => PROVENANCE_HISTORY_FIELD_TAG,
        'old' => $kind == 'added' ? null : $value,
        'new' => $kind == 'added' ? $value : null,
        'source' => $source,
        );
    }
  }

  return $changes;
}

/**
 * @return bool whether provenance's history can take an entry
 */
function photoinfo_tag_history_available()
{
  return defined('PROVENANCE_PATH') and function_exists('provenance_record_changes');
}

/**
 * @param int[] $tag_ids
 * @return array tag id => name
 */
function photoinfo_tag_names($tag_ids)
{
  if (count($tag_ids) == 0)
  {
    return array();
  }

  $query = '
SELECT id, name
  FROM '.TAGS_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', $tag_ids)).')
;';

  return query2array($query, 'id', 'name');
}

/**
 * Records the tags a photo gained and lost, given its tag ids before and now.
 *
 * @param int $image_id
 * @param int[] $before photoinfo_tag_ids_of() before the change
 * @param string $source the end of a PROVENANCE_HISTORY_SOURCE_* name, e.g. 'PICTURE_PAGE'
 * @return int[] the new entries' ids; empty when nothing changed or provenance is off
 */
function photoinfo_record_tag_changes($image_id, $before, $source)
{
  if (!photoinfo_tag_history_available())
  {
    return array();
  }

  $after = photoinfo_tag_ids_of($image_id);
  $diff = photoinfo_tag_changes($before, $after);
  $names = photoinfo_tag_names(array_merge($diff['added'], $diff['removed']));

  $changes = photoinfo_tag_history_changes(
    (int)$image_id, $before, $after, $names, constant('PROVENANCE_HISTORY_SOURCE_'.$source));
  $written = provenance_record_changes($changes);
  if ($written == 0)
  {
    return array();
  }

  // One statement inserted the rows, so their ids follow the connection's last one.
  $first = (int)pwg_db_insert_id();

  return range($first, $first + $written - 1);
}

/**
 * The answer fields for a typetags method that changed one photo's tags.
 *
 * @param int $image_id
 * @param int[] $before
 * @param string $source as for photoinfo_record_tag_changes()
 * @return array 'history_ids' => int[]
 */
function photoinfo_tag_history_answer($image_id, $before, $source)
{
  return array('history_ids' => photoinfo_record_tag_changes($image_id, $before, $source));
}

/**
 * The photos the Batch Manager's current submit acts on, as its page reads them.
 *
 * @return int[]
 */
function photoinfo_tag_history_posted_collection()
{
  if (isset($_POST['setSelected']) and isset($_POST['whole_set']))
  {
    $ids = explode(',', $_POST['whole_set']);
  }
  else if (isset($_POST['selection']) and is_array($_POST['selection']))
  {
    $ids = $_POST['selection'];
  }
  else
  {
    return array();
  }

  return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

/**
 * @param int[] $image_ids
 * @return array image id => tag ids, an entry for every photo
 */
function photoinfo_tag_ids_by_image($image_ids)
{
  $tags = array_fill_keys(array_map('intval', $image_ids), array());
  if (count($tags) == 0)
  {
    return $tags;
  }

  $query = '
SELECT image_id, tag_id
  FROM '.IMAGE_TAG_TABLE.'
  WHERE image_id IN ('.implode(',', array_keys($tags)).')
  ORDER BY tag_id
;';
  foreach (query2array($query) as $row)
  {
    $tags[(int)$row['image_id']][] = (int)$row['tag_id'];
  }

  return $tags;
}
