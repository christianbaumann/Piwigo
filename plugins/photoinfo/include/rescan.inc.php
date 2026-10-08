<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * Rebuilding photos' dates and info texts from their files, which carry both in
 * photoinfo's own tags: the database is never deployed (decision 0023), the file
 * is. Reads only - nothing here writes a file.
 *
 * One unreadable file must not cost a gallery its rescan, so every failure is
 * recorded against its photo and the loop continues, as in pwg.persons.rescan.
 */

/**
 * Restores each given photo's date and info text from its file.
 *
 * @param array $image_ids
 * @return array array('scanned' => int, 'failed' => array(image id => message))
 */
function photoinfo_rescan_images($image_ids)
{
  global $conf;

  $failed = array();
  $scanned = 0;

  if (count($image_ids) == 0)
  {
    return array('scanned' => 0, 'failed' => array());
  }

  if (!provenance_exiftool_available())
  {
    return array('scanned' => 0, 'failed' => array_fill_keys($image_ids, 'exiftool is not available on this server'));
  }

  $query = '
SELECT id, path, date_creation
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', $image_ids)).')
;';
  $rows = query2array($query, 'id');

  foreach ($image_ids as $image_id)
  {
    if (!isset($rows[$image_id]))
    {
      $failed[$image_id] = 'No such photo';
      continue;
    }

    $tags = photoinfo_read_file_tags(provenance_image_file_path($rows[$image_id]['path']));
    if (!is_array($tags))
    {
      $failed[$image_id] = $tags;
      continue;
    }

    $outcome = photoinfo_rescan_columns($tags, $conf['allow_html_descriptions'], (int)date('Y'),
      $rows[$image_id]['date_creation']);
    if (count($outcome['columns']) > 0)
    {
      photoinfo_update_image($image_id, $outcome['columns']);
    }

    if ($outcome['error'] === null)
    {
      $scanned++;
    }
    else
    {
      $failed[$image_id] = $outcome['error'];
    }
  }

  return array('scanned' => $scanned, 'failed' => $failed);
}

/**
 * Reads photoinfo's two tags from one file.
 *
 * With photoinfo's exiftool config: without it, exiftool guesses the unknown
 * DateEDTF's type and reads "1965/1970" as a fraction (exiftool 13.25,
 * measured 2026-10-08).
 *
 * @param string $file the image on disk
 * @return array|string photoinfo_parse_rescan_xml()'s answer, or why the file
 *   could not be read
 */
function photoinfo_read_file_tags($file)
{
  if (!is_file($file) or !is_readable($file))
  {
    return 'File is missing or not readable';
  }

  $command =
    escapeshellcmd(provenance_exiftool_binary()).
    ' -config '.escapeshellarg(PHOTOINFO_XMP_CONFIG).
    ' -X -'.PHOTOINFO_INFO_TAG.' -'.PHOTOINFO_DATE_EDTF_TAG.' '.
    escapeshellarg($file);

  // Into a file rather than exec's output array, which drops each line's
  // trailing whitespace - part of a multi-line info text. exec() is the one
  // shell function the remote host was probed for.
  $operation_dir = provenance_operation_dir(provenance_operation_id());
  try
  {
    provenance_make_dir($operation_dir);
    $out_file = $operation_dir.'rescan.xml';

    $lines = array();
    $status = 1;
    exec($command.' > '.escapeshellarg($out_file).' 2>/dev/null', $lines, $status);
    $output = $status === 0 ? file_get_contents($out_file) : '';
  }
  catch (RuntimeException $e)
  {
    return $e->getMessage();
  }
  finally
  {
    provenance_remove_dir($operation_dir);
  }

  if ($status !== 0)
  {
    return 'exiftool could not read the file (status '.$status.')';
  }

  $tags = photoinfo_parse_rescan_xml($output);

  return $tags === null ? 'exiftool returned no readable XML' : $tags;
}
