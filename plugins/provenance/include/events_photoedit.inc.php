<?php
defined('PROVENANCE_PATH') or die('Hacking attempt!');

/*
 * No write-back may run on a photo while plugins/photoedit rewrites it: both
 * replace the file by rename, and the later rename would drop the earlier
 * write. photoedit fires these around its write; this plugin takes its own
 * lock there, without photoedit knowing the path.
 */

/**
 * The lock taken on photoedit_begin, by image path, until photoedit_end.
 *
 * @return array by reference
 */
function &provenance_photoedit_held_locks()
{
  static $held = array();

  return $held;
}

/**
 * photoedit_begin: takes this plugin's lock on the photo.
 *
 * Throws on a timeout: photoedit fires this inside its try, so the edit is
 * abandoned before the file is touched.
 *
 * @param array $image the image row
 * @return void
 */
function provenance_photoedit_begin($image)
{
  $lock = provenance_lock_acquire($image['path']);
  if ($lock === null)
  {
    throw new RuntimeException('Timed out waiting for a provenance write-back to this file');
  }

  $held = &provenance_photoedit_held_locks();
  $held[$image['path']] = $lock;
}

/**
 * photoedit_end: gives the lock back, whether or not the edit succeeded.
 *
 * @param array $image the image row
 * @return void
 */
function provenance_photoedit_end($image)
{
  $held = &provenance_photoedit_held_locks();
  if (isset($held[$image['path']]))
  {
    flock($held[$image['path']], LOCK_UN);
    fclose($held[$image['path']]);
    unset($held[$image['path']]);
  }
}
