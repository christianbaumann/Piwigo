<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * An upload or a replace through core's upload code: the index follows the new
 * file, and the gallery's marks are not lost with the old one (decision 0084).
 *
 * A file that carries marks is the truth and is indexed as it stands, which is
 * also what a new upload of a marked file needs. A file that carries none
 * receives the marks the index held. Nothing is announced through
 * persons_tags_changed: photoinfo's own handler runs after this one and
 * decides the keywords from the file as it is by then.
 */

include_once(PERSONS_PATH.'include/index.inc.php');

/**
 * loc_end_add_uploaded_file: merges the new file's regions with the index.
 * Core's notification cannot refuse an upload, so a failure is logged and the
 * upload stands.
 *
 * @param array $image_infos the photo's row: id, path, representative_ext
 */
function persons_uploaded_file($image_infos)
{
  global $logger;

  try
  {
    $outcome = persons_merge_uploaded_file((int)$image_infos['id']);
    if (!$outcome['ok'])
    {
      $logger->error('persons: upload of photo '.(int)$image_infos['id'].' not merged: '.$outcome['message']);
    }
  }
  catch (Throwable $e)
  {
    $logger->error('persons: upload of photo '.(int)$image_infos['id'].' not merged: '.$e->getMessage());
  }
}

/**
 * Reindexes a photo from its new file when the file carries marks (or the
 * index holds none), else writes the index's marks into the file.
 *
 * The decision and the write share one hold of the photo's lock, so no other
 * writer changes the file between them.
 *
 * @param int $image_id
 * @return array array('ok' => bool, 'message' => string)
 */
function persons_merge_uploaded_file($image_id)
{
  $image = pwg_db_fetch_assoc(pwg_query(
    'SELECT path FROM '.IMAGES_TABLE.' WHERE id = '.(int)$image_id.';'
    ));
  if (!$image)
  {
    return array('ok' => false, 'message' => 'No such photo');
  }

  $file = persons_image_file_path($image['path']);

  $lock = persons_lock_acquire($image['path']);
  if ($lock === null)
  {
    return array('ok' => false, 'message' => PERSONS_LOCK_TIMEOUT_MESSAGE);
  }

  try
  {
    $read = persons_read_regions($file);
    if (!$read['ok'])
    {
      return array('ok' => false, 'message' => $read['message']);
    }

    $held = persons_regions_to_add(persons_indexed_regions($image_id));
    if (count($read['regions']) > 0 or count($held) == 0)
    {
      $outcome = persons_reindex_image($image_id, $file);
    }
    else
    {
      $outcome = persons_apply_change_quietly($image_id, $held, array());
    }

    return array('ok' => $outcome['ok'], 'message' => $outcome['message']);
  }
  finally
  {
    persons_lock_release($lock);
  }
}

/**
 * Index rows in the shape persons_merge_regions() adds.
 *
 * @param array $indexed persons_indexed_regions()'s rows
 * @return array list of array(name, x, y, w, h, type)
 */
function persons_regions_to_add($indexed)
{
  $add = array();
  foreach ($indexed as $row)
  {
    $add[] = array(
      'name' => $row['name'],
      'x'    => (float)$row['area_x'],
      'y'    => (float)$row['area_y'],
      'w'    => (float)$row['area_w'],
      'h'    => (float)$row['area_h'],
      'type' => $row['region_type'],
      );
  }

  return $add;
}
