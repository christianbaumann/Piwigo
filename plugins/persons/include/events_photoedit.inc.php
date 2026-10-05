<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * The regions follow a photo turned or cropped by plugins/photoedit.
 *
 * photoedit rewrites the pixels and copies the metadata back unchanged, so the
 * regions in the file still describe the photo as it was. A rescan cannot tell
 * a crop from anything else, so this plugin moves them itself, on photoedit's
 * events, without photoedit knowing it exists.
 */

include_once(PERSONS_PATH.'include/index.inc.php');

/**
 * The lock taken on photoedit_begin, by image path, until photoedit_end.
 *
 * @return array by reference
 */
function &persons_photoedit_held_locks()
{
  static $held = array();

  return $held;
}

/**
 * photoedit_preview: names the regions the edit would remove.
 *
 * @param array $lost the names other handlers already added
 * @param array $image the image row
 * @param array $transform
 * @return array
 */
function persons_photoedit_preview($lost, $image, $transform)
{
  $read = persons_read_regions(persons_image_file_path($image['path']));
  if (!$read['ok'])
  {
    return $lost;
  }

  $outcome = persons_transform_regions($read['regions'], $transform);

  return array_merge((array)$lost, $outcome['lost']);
}

/**
 * photoedit_begin: no region write may run on the photo while it is edited.
 *
 * Throws on a timeout: photoedit fires this inside its try, so the edit is
 * abandoned before the file is touched.
 *
 * @param array $image the image row
 * @return void
 */
function persons_photoedit_begin($image)
{
  $lock = persons_lock_acquire($image['path']);
  if ($lock === null)
  {
    throw new RuntimeException(PERSONS_LOCK_TIMEOUT_MESSAGE);
  }

  $held = &persons_photoedit_held_locks();
  $held[$image['path']] = $lock;
}

/**
 * photoedit_end: after a successful edit, moves the regions in the file and
 * rebuilds the index; in every case gives the lock back.
 *
 * @param array $image the image row as it was before the edit
 * @param array $transform
 * @param bool $ok whether the file was written
 * @return void
 */
function persons_photoedit_end($image, $transform, $ok)
{
  try
  {
    if ($ok)
    {
      persons_photoedit_move_regions($image, $transform);
    }
  }
  finally
  {
    $held = &persons_photoedit_held_locks();
    if (isset($held[$image['path']]))
    {
      persons_lock_release($held[$image['path']]);
      unset($held[$image['path']]);
    }
  }
}

/**
 * Writes the moved regions into the edited file and reindexes it.
 *
 * Every region is removed and its moved copy added, so the names of persons
 * the edit cut away leave PersonInImage with them. A failure is logged: the
 * file is already edited, and photoedit has no way to undo it on our word.
 * A rescan of the photo then indexes what the file still says.
 *
 * @param array $image
 * @param array $transform
 * @return void
 */
function persons_photoedit_move_regions($image, $transform)
{
  global $logger;

  $file = persons_image_file_path($image['path']);
  $read = persons_read_regions($file);

  $failure = '';
  if (!$read['ok'])
  {
    $failure = $read['message'];
  }
  elseif (count($read['regions']) > 0)
  {
    $outcome = persons_transform_regions($read['regions'], $transform);

    $remove = array();
    foreach ($read['regions'] as $region)
    {
      $remove[] = array('name' => $region['name']);
    }

    $merged = persons_merge_regions($read, $outcome['kept'], $remove, $transform['width_after'], $transform['height_after']);
    $write = persons_write_regions($file, $merged['regioninfo'], $merged['names']);
    if (!$write['ok'])
    {
      $failure = $write['message'];
    }
  }

  if ($failure === '')
  {
    $reindex = persons_reindex_image($image['id'], $file);
    $failure = $reindex['ok'] ? '' : $reindex['message'];
  }

  if ($failure !== '' and is_object($logger))
  {
    $logger->error('persons: regions of photo '.$image['id'].' not moved with its edit: '.$failure);
  }
}
