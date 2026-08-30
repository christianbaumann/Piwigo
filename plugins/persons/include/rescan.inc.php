<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * Rebuilding the index for many photos at once.
 *
 * One unreadable file must not cost an album its rescan, so every failure is
 * recorded against its photo and the loop continues - the same rule the
 * provenance write-back follows.
 */

include_once(PERSONS_PATH.'include/index.inc.php');

/**
 * Rebuilds the index for each given photo.
 *
 * @param array $image_ids
 * @return array array('scanned' => int, 'failed' => array(image id => message))
 */
function persons_rescan_images($image_ids)
{
  $ids = array();
  foreach ($image_ids as $id)
  {
    $id = (int)$id;
    if ($id > 0)
    {
      $ids[$id] = $id;
    }
  }

  $scanned = 0;
  $failed = array();

  if (count($ids) == 0)
  {
    return array('scanned' => 0, 'failed' => array());
  }

  foreach (array_chunk($ids, PERSONS_WRITEBACK_MAX_CHUNK) as $chunk)
  {
    $result = pwg_query('
SELECT id, path
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $chunk).')
;');

    $paths = array();
    while ($row = pwg_db_fetch_assoc($result))
    {
      $paths[(int)$row['id']] = $row['path'];
    }

    foreach ($chunk as $image_id)
    {
      if (!isset($paths[$image_id]))
      {
        $failed[$image_id] = 'No such photo';
        continue;
      }

      $outcome = persons_reindex_image($image_id, persons_image_file_path($paths[$image_id]));

      if ($outcome['ok'])
      {
        $scanned++;
      }
      else
      {
        $failed[$image_id] = $outcome['message'];
      }
    }
  }

  return array('scanned' => $scanned, 'failed' => $failed);
}
