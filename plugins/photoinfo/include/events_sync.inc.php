<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * Metadata sync. Pulled in only when core reads a file's EXIF for a sync
 * (get_exif_data() in include/functions_metadata.inc.php).
 */

/**
 * Keeps a metadata sync from replacing the photo's date with the file's: a date
 * set with photoinfo is handed back unchanged, and a file without camera
 * metadata gives no date at all. A camera file's date is taken; with no
 * precision stored, it reads as an exact day (photoinfo_date_from_row()).
 *
 * Core hands this filter null when PHP read nothing from the file - a PNG or a
 * HEIC on PHP 8.4. For a photo with a photoinfo date the date is supplied even
 * then, so a sync that writes missing values as NULL cannot clear it.
 *
 * @param array|null $exif
 * @param string $filename the file core read, as it built the path
 * @param array $map $conf['use_exif_mapping'], pwg field => EXIF field
 * @return array|null
 */
function photoinfo_format_exif_data($exif, $filename, $map)
{
  if (!isset($map['date_creation']))
  {
    return $exif;
  }

  return photoinfo_sync_exif($exif, $map['date_creation'], photoinfo_file_date($filename));
}

/**
 * The date_creation of the photo stored at a file, when photoinfo set it. The
 * file may be the photo itself or its representative (a HEIC's JPEG). A file no
 * image row points at has none.
 *
 * @param string $filename
 * @return string|null
 */
function photoinfo_file_date($filename)
{
  $path = photoinfo_image_path($filename, PHPWG_ROOT_PATH);
  if ($path === null)
  {
    return null;
  }

  $where = 'path = \''.pwg_db_real_escape_string($path).'\'';
  $original = photoinfo_representative_original($path);
  if ($original !== null)
  {
    list($prefix, $ext) = $original;
    $where = '('.$where.'
       OR (representative_ext = \''.pwg_db_real_escape_string($ext).'\'
           AND path LIKE \''.pwg_db_real_escape_string(addcslashes($prefix, '\\%_')).'%\'))';
  }

  $query = '
SELECT date_creation
  FROM '.IMAGES_TABLE.'
  WHERE '.$where.'
    AND photoinfo_date_precision IS NOT NULL
    AND date_creation IS NOT NULL
  LIMIT 1
;';
  $row = pwg_db_fetch_assoc(pwg_query($query));

  return empty($row) ? null : $row['date_creation'];
}
