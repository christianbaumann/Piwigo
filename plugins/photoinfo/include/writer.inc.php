<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * The file side: what photoinfo puts into an image file, through provenance's
 * exiftool runner and under provenance's lock - one lock file per image, which
 * plugins/photoedit's edits respect as well. When plugins/persons is active,
 * its lock is taken first: persons writes its regions under that lock alone.
 */

/**
 * provenance_caption_parts: puts the photo's date and info text first in the
 * caption.
 *
 * Provenance's own write-back selects none of photoinfo's columns, so they are
 * read here when the row does not carry them.
 *
 * @param array $blocks name => text
 * @param array $image the image row: id, and photoinfo_written_columns() when the caller has them
 * @return array
 */
function photoinfo_caption_parts_handler($blocks, $image)
{
  $columns = photoinfo_written_columns();

  if (count(array_diff($columns, array_keys($image))) > 0)
  {
    $query = '
SELECT '.implode(', ', $columns).'
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int)$image['id'].'
;';
    $row = pwg_db_fetch_assoc(pwg_query($query));
    // What the caller passed wins over what is stored.
    $image = array_merge(empty($row) ? array_fill_keys($columns, null) : $row, $image);
  }

  return photoinfo_caption_blocks($blocks, $image['comment'], photoinfo_dating_display(photoinfo_dating_from_row($image)));
}

/** What a file write sets beside the caption: the info text, the date, or both. */
define('PHOTOINFO_WRITE_INFO', 'info');
define('PHOTOINFO_WRITE_DATE', 'date');
define('PHOTOINFO_WRITE_ALL', 'all');

/**
 * Writes one photo's composed caption into its file, with its info text, its
 * date, or both beside it.
 *
 * @param array $image id, path, photoinfo_written_columns() and provenance's photo columns
 * @param string $field PHOTOINFO_WRITE_INFO, PHOTOINFO_WRITE_DATE or PHOTOINFO_WRITE_ALL
 * @return array array('ok' => bool, 'message' => string)
 */
function photoinfo_write_file($image, $field)
{
  if (!provenance_exiftool_available())
  {
    return array('ok' => false, 'message' => 'exiftool is not available on this server');
  }

  $file = provenance_image_file_path($image['path']);
  if (!is_file($file) or !is_writable($file))
  {
    return array('ok' => false, 'message' => PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE);
  }

  load_language('plugin.lang', PROVENANCE_PATH);
  $labels = array_map('l10n', provenance_caption_label_keys());

  $blocks = trigger_change('provenance_caption_parts',
    array('provenance' => provenance_caption_block($image, $labels)),
    $image
    );

  $caption = provenance_join_caption_blocks($blocks);
  $info = (string)$image['comment'];

  $operation_dir = provenance_operation_dir(provenance_operation_id());
  $value_prefix = $operation_dir.(int)$image['id'].'-';

  if ($field == PHOTOINFO_WRITE_DATE)
  {
    $lines = photoinfo_build_date_argfile($caption, photoinfo_dating_from_row($image), $value_prefix);
    $values = provenance_caption_values(provenance_normalize_caption($caption));
  }
  elseif ($field == PHOTOINFO_WRITE_ALL)
  {
    $lines = photoinfo_build_full_argfile($caption, $info, photoinfo_dating_from_row($image), $value_prefix);
    $values = photoinfo_argfile_values($caption, $info);
  }
  else
  {
    $lines = photoinfo_build_argfile($caption, $info, $value_prefix);
    $values = photoinfo_argfile_values($caption, $info);
  }

  try
  {
    provenance_make_dir($operation_dir);

    foreach (provenance_argfile_value_files($values, $value_prefix) as $path => $content)
    {
      file_put_contents($path, $content);
    }

    $argfile = $operation_dir.(int)$image['id'].'.args';
    file_put_contents($argfile, implode("\n", $lines)."\n");

    return photoinfo_with_persons_lock($image['path'], function () use ($argfile, $file, $image)
    {
      return provenance_exiftool_run($argfile, $file, $image['path'], PHOTOINFO_XMP_CONFIG);
    });
  }
  finally
  {
    provenance_remove_dir($operation_dir);
  }
}

/**
 * Runs a file write under plugins/persons' lock on the photo, when persons is
 * active. Its lock is re-entrant within one request, so a write started from
 * inside a persons change does not wait for itself.
 *
 * @param string $db_path images.path
 * @param callable $fn returns array('ok' => bool, 'message' => string)
 * @return array what $fn returned, or the persons lock's timeout
 */
function photoinfo_with_persons_lock($db_path, $fn)
{
  // PERSONS_PATH alone is also defined while persons is being (de)activated.
  if (!defined('PERSONS_LOCK_DIR'))
  {
    return $fn();
  }

  include_once(PERSONS_PATH.'include/exiftool.inc.php');
  $lock = persons_lock_acquire($db_path);
  if ($lock === null)
  {
    return array('ok' => false, 'message' => PERSONS_LOCK_TIMEOUT_MESSAGE);
  }

  try
  {
    return $fn();
  }
  finally
  {
    persons_lock_release($lock);
  }
}

/**
 * Whether plugins/photoedit holds provenance's lock on the photo in this
 * request: a write now would wait for itself. photoedit_end writes the tags
 * once the edit is over.
 *
 * @param string $db_path images.path
 * @return bool
 */
function photoinfo_photoedit_holds($db_path)
{
  if (!function_exists('provenance_photoedit_held_locks'))
  {
    return false;
  }

  $held = &provenance_photoedit_held_locks();
  return isset($held[$db_path]);
}

/**
 * @param int $image_id
 * @return int[] the photo's tag ids, ascending
 */
function photoinfo_tag_ids_of($image_id)
{
  $query = '
SELECT tag_id
  FROM '.IMAGE_TAG_TABLE.'
  WHERE image_id = '.(int)$image_id.'
  ORDER BY tag_id
;';

  return array_map('intval', query2array($query, null, 'tag_id'));
}

/**
 * @param int[] $tag_ids
 * @return int[] the photos carrying any of the tags
 */
function photoinfo_images_with_tags($tag_ids)
{
  $tag_ids = array_map('intval', $tag_ids);
  if (count($tag_ids) == 0)
  {
    return array();
  }

  $query = '
SELECT DISTINCT image_id
  FROM '.IMAGE_TAG_TABLE.'
  WHERE tag_id IN ('.implode(',', $tag_ids).')
;';

  return array_map('intval', query2array($query, null, 'image_id'));
}

/**
 * @return int the highest tag id, 0 for none: taken before a save, it tells
 *   the tags the save created
 */
function photoinfo_max_tag_id()
{
  list($max) = pwg_db_fetch_row(pwg_query('SELECT MAX(id) FROM '.TAGS_TABLE.';'));

  return (int)$max;
}

/**
 * Puts the tags typed in on the photos since photoinfo_max_tag_id() into the
 * Freitext group. Does nothing without typetags or without that group.
 *
 * @param int $max_before
 * @param int[] $image_ids the photos the save changed
 */
function photoinfo_assign_freitext($max_before, $image_ids)
{
  if (!defined('TYPETAGS_TABLE'))
  {
    return;
  }

  $query = '
SELECT id
  FROM '.TYPETAGS_TABLE.'
  WHERE name = \''.pwg_db_real_escape_string(PHOTOINFO_FREITEXT_GROUP).'\'
;';
  $group = query2array($query, null, 'id');
  if (count($group) == 0)
  {
    return;
  }

  $query = '
SELECT id, id_typetags AS `group`
  FROM '.TAGS_TABLE.'
  WHERE id > '.(int)$max_before.'
;';
  $person_tag_ids = array();
  if (defined('PERSONS_TABLE'))
  {
    $person_tag_ids = query2array('SELECT tag_id FROM '.PERSONS_TABLE.' WHERE tag_id IS NOT NULL;', null, 'tag_id');
  }

  $image_ids = array_map('intval', $image_ids);
  if (count($image_ids) == 0)
  {
    return;
  }
  $linked_tag_ids = query2array('
SELECT DISTINCT tag_id
  FROM '.IMAGE_TAG_TABLE.'
  WHERE image_id IN ('.implode(',', $image_ids).')
    AND tag_id > '.(int)$max_before.'
;', null, 'tag_id');

  $tag_ids = photoinfo_new_freitext_tags(query2array($query), $max_before, $person_tag_ids, $linked_tag_ids);
  if (count($tag_ids) == 0)
  {
    return;
  }

  $query = '
UPDATE '.TAGS_TABLE.'
  SET id_typetags = '.(int)$group[0].'
  WHERE id IN ('.implode(',', $tag_ids).')
    AND id_typetags IS NULL
;';
  pwg_query($query);
}

/**
 * One photo's tags with the name of each one's typetags group.
 *
 * @param int $image_id
 * @return array rows: name, group (null for none, or without typetags)
 */
function photoinfo_image_tags($image_id)
{
  $grouped = defined('TYPETAGS_TABLE');

  $query = '
SELECT t.name, '.($grouped ? 'g.name' : 'NULL').' AS group_name
  FROM '.IMAGE_TAG_TABLE.' AS it
    JOIN '.TAGS_TABLE.' AS t ON t.id = it.tag_id'.($grouped ? '
    LEFT JOIN '.TYPETAGS_TABLE.' AS g ON g.id = t.id_typetags' : '').'
  WHERE it.image_id = '.(int)$image_id.'
;';

  $tags = array();
  foreach (query2array($query) as $row)
  {
    $tags[] = array('name' => $row['name'], 'group' => $row['group_name']);
  }

  return $tags;
}

/**
 * Writes the photos' tags into their files. A photo plugins/photoedit is
 * editing in this request is left out; photoedit_end writes it.
 *
 * @param int[] $image_ids
 * @return array image id => array('ok' => bool, 'message' => string)
 */
function photoinfo_write_tags($image_ids)
{
  $image_ids = array_unique(array_map('intval', $image_ids));
  if (count($image_ids) == 0)
  {
    return array();
  }

  if (!defined('PROVENANCE_PATH'))
  {
    return array_fill_keys($image_ids,
      array('ok' => false, 'message' => PHOTOINFO_REQUIRES_PROVENANCE_MESSAGE));
  }

  $query = '
SELECT id, path
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $image_ids).')
;';

  $results = array();
  foreach (query2array($query) as $image)
  {
    if (!photoinfo_photoedit_holds($image['path']))
    {
      $results[(int)$image['id']] = photoinfo_write_tags_file($image);
    }
  }

  return $results;
}

/**
 * Writes one photo's tags into its file. The tags are read under the locks,
 * so two changes racing each other leave the later state in the file.
 *
 * @param array $image id, path
 * @return array array('ok' => bool, 'message' => string)
 */
function photoinfo_write_tags_file($image)
{
  if (!provenance_exiftool_available())
  {
    return array('ok' => false, 'message' => 'exiftool is not available on this server');
  }

  $file = provenance_image_file_path($image['path']);
  if (!is_file($file) or !is_writable($file))
  {
    return array('ok' => false, 'message' => PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE);
  }

  $operation_dir = provenance_operation_dir(provenance_operation_id());
  $argfile = $operation_dir.(int)$image['id'].'-tags.args';

  try
  {
    provenance_make_dir($operation_dir);

    return photoinfo_with_persons_lock($image['path'], function () use ($argfile, $file, $image)
    {
      $lines = photoinfo_build_tags_argfile(photoinfo_file_keywords(photoinfo_image_tags($image['id'])));
      file_put_contents($argfile, implode("\n", $lines)."\n");

      return provenance_exiftool_run($argfile, $file, $image['path'], PHOTOINFO_XMP_CONFIG);
    });
  }
  finally
  {
    provenance_remove_dir($operation_dir);
  }
}

/**
 * Loads one photo with every column a file write needs.
 *
 * @param int $image_id
 * @return array|null null for no such photo
 */
function photoinfo_image_row($image_id)
{
  $columns = array_merge(array('id', 'path'), photoinfo_written_columns(), array_keys(provenance_image_columns()));

  $query = '
SELECT '.implode(', ', $columns).'
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int)$image_id.'
;';
  $image = pwg_db_fetch_assoc(pwg_query($query));

  return empty($image) ? null : $image;
}

/**
 * Stores columns of one photo's row.
 *
 * @param int $image_id
 * @param array $columns column => value or null
 */
function photoinfo_update_image($image_id, $columns)
{
  $set = array();
  foreach ($columns as $column => $value)
  {
    $set[] = $column.' = '.($value === null ? 'NULL' : '\''.pwg_db_real_escape_string($value).'\'');
  }

  $query = '
UPDATE '.IMAGES_TABLE.'
  SET '.implode(",\n      ", $set).'
  WHERE id = '.(int)$image_id.'
;';
  pwg_query($query);
}
