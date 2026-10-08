<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * Metadata sync. Pulled in only when core reads a file's EXIF for a sync
 * (get_exif_data() in include/functions_metadata.inc.php).
 */

/**
 * Keeps a metadata sync from replacing the photo's date with the file's: a date
 * set with photoinfo is never overwritten, and a file without camera metadata
 * gives no date at all. A camera file's date is taken; with no precision
 * stored, it reads as an exact day (photoinfo_date_from_row()).
 *
 * Core hands this filter null when PHP read nothing from the file - a PNG or a
 * HEIC on PHP 8.4 - and null is passed on.
 *
 * @param array|null $exif
 * @param string $filename the file core read, as it built the path
 * @param array $map $conf['use_exif_mapping'], pwg field => EXIF field
 * @return array|null
 */
function photoinfo_format_exif_data($exif, $filename, $map)
{
  if (!is_array($exif) or !isset($map['date_creation']))
  {
    return $exif;
  }

  return photoinfo_sync_exif($exif, $map['date_creation'], photoinfo_file_has_date($filename));
}

/**
 * Whether the photo stored at a file has a date set with photoinfo. A file no
 * image row points at - a video's representative, for one - has none.
 *
 * @param string $filename
 * @return bool
 */
function photoinfo_file_has_date($filename)
{
  $path = photoinfo_image_path(realpath($filename), realpath(PHPWG_ROOT_PATH));
  if ($path === null)
  {
    return false;
  }

  $query = '
SELECT COUNT(*)
  FROM '.IMAGES_TABLE.'
  WHERE path = \''.pwg_db_real_escape_string($path).'\'
    AND photoinfo_date_precision IS NOT NULL
;';
  list($count) = pwg_db_fetch_row(pwg_query($query));

  return $count > 0;
}
