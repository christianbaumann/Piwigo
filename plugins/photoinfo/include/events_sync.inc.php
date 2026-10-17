<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * Metadata sync. Pulled in only when core reads a file's EXIF for a sync
 * (get_exif_data() in include/functions_metadata.inc.php).
 */

/**
 * Keeps a metadata sync from replacing the photo's date with the file's: a date
 * set with photoinfo is handed back unchanged. Without one, the date photoinfo
 * wrote into the file is taken (XMP-pwginfo:DateEDTF, then
 * XMP-photoshop:DateCreated), so a file added again gets its date back. A file
 * without either gives a camera date only, and no date at all without camera
 * metadata. A camera date with no precision stored reads as an exact day
 * (photoinfo_date_from_row()).
 *
 * Core hands this filter null when PHP read nothing from the file - a PNG or a
 * HEIC on PHP 8.4. A photoinfo date is supplied even then, so a sync that
 * writes missing values as NULL cannot clear it. photoinfo's date columns go
 * along through photoinfo_sync_mapping().
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

  $columns = photoinfo_sync_columns(photoinfo_file_row($filename));
  if ($columns === null)
  {
    return photoinfo_sync_exif($exif, $map['date_creation'], null);
  }

  $date = $columns['date_creation'];
  unset($columns['date_creation']);
  return photoinfo_sync_exif($exif, $map['date_creation'], $date, array_intersect_key($columns, $map));
}

/**
 * The date columns a sync writes for a photo: those photoinfo set, else those
 * its file carries in photoinfo's tags.
 *
 * @param array|null $row photoinfo_file_row()'s answer
 * @return array|null photoinfo_dating_columns(); null for neither
 */
function photoinfo_sync_columns($row)
{
  if ($row === null)
  {
    return null;
  }

  $columns = array_intersect_key($row, photoinfo_dating_columns(null));
  if ($row['photoinfo_date_precision'] !== null and $row['date_creation'] !== null)
  {
    return $columns;
  }

  $file_dates = photoinfo_read_file_date(provenance_image_file_path($row['path']));
  return $file_dates === null ? null
    : photoinfo_sync_file_columns($file_dates, (int)date('Y'), $row['date_creation']);
}

/**
 * The two date tags of one file, read with exiftool. Reads only.
 *
 * @param string $file
 * @return array|null photoinfo_parse_sync_date_xml()'s answer; null when
 *   exiftool is missing or cannot read the file
 */
function photoinfo_read_file_date($file)
{
  // No provenance_exiftool_available(): it runs exiftool once more per file,
  // and a missing binary fails the read below just the same.
  if (!function_exists('provenance_exiftool_binary') or !is_file($file) or !is_readable($file))
  {
    return null;
  }

  $lines = array();
  $status = 1;
  exec(
    escapeshellcmd(provenance_exiftool_binary()).
    ' -config '.escapeshellarg(PHOTOINFO_XMP_CONFIG).
    ' -X -'.PHOTOINFO_DATE_EDTF_TAG.' -'.PHOTOINFO_DATE_XMP_TAG.' '.
    escapeshellarg($file).' 2>/dev/null',
    $lines, $status);

  return $status === 0 ? photoinfo_parse_sync_date_xml(implode("\n", $lines)) : null;
}

/**
 * The row of the photo stored at a file: its path and date columns. The file
 * may be the photo itself or its representative (a HEIC's JPEG). A file no
 * image row points at has none.
 *
 * @param string $filename
 * @return array|null
 */
function photoinfo_file_row($filename)
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
SELECT path, '.implode(', ', array_keys(photoinfo_dating_columns(null))).'
  FROM '.IMAGES_TABLE.'
  WHERE '.$where.'
  LIMIT 1
;';
  $row = pwg_db_fetch_assoc(pwg_query($query));

  return empty($row) ? null : $row;
}
