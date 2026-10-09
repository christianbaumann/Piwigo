<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * Rebuilding photos' dates and info texts from their files, which carry both in
 * photoinfo's own tags, and adding the tags a file names: the database is never
 * deployed (decision 0023), the file is. Reads only - nothing here writes a
 * file, and a tag is only ever added; pwg.photoinfo.pruneTags removes.
 *
 * One unreadable file must not cost a gallery its rescan, so every failure is
 * recorded against its photo and the loop continues, as in pwg.persons.rescan.
 */

/** The colour of a group a rescan has to create: typetags' own default (template/admin.tpl). */
define('PHOTOINFO_RESCAN_GROUP_COLOR', '#444444');

/**
 * Restores each given photo's date and info text from its file, and links the
 * tags a marked file names.
 *
 * @param array $image_ids
 * @return array array('scanned' => int, 'failed' => array(image id => message),
 *   'tags_added' => int, 'not_in_file' => array(image id => tag names))
 */
function photoinfo_rescan_images($image_ids)
{
  global $conf;

  $failed = array();
  $scanned = 0;
  $tags_added = 0;
  $not_in_file = array();

  if (count($image_ids) == 0)
  {
    return array('scanned' => 0, 'failed' => array(), 'tags_added' => 0, 'not_in_file' => array());
  }

  if (!provenance_exiftool_available())
  {
    return array('scanned' => 0, 'failed' => array_fill_keys($image_ids, 'exiftool is not available on this server'),
      'tags_added' => 0, 'not_in_file' => array());
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

    $tag_outcome = photoinfo_rescan_apply_tags($image_id, $tags);
    $tags_added += $tag_outcome['added'];
    if (count($tag_outcome['not_in_file']) > 0)
    {
      $not_in_file[$image_id] = $tag_outcome['not_in_file'];
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

  if ($tags_added > 0)
  {
    invalidate_user_cache_nb_tags();
  }

  return array('scanned' => $scanned, 'failed' => $failed, 'tags_added' => $tags_added, 'not_in_file' => $not_in_file);
}

/**
 * Links the tags one marked file names and the photo lacks, creating a
 * missing tag, and a missing group the hierarchy names. A tag already in a
 * group keeps it. Writes no file and fires no event: the file already says
 * this, and Freitext is for tags typed in by hand.
 *
 * @param int $image_id
 * @param array $file_tags photoinfo_parse_rescan_xml()'s answer
 * @return array array('added' => int, 'not_in_file' => names)
 */
function photoinfo_rescan_apply_tags($image_id, $file_tags)
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  $outcome = photoinfo_rescan_tags($file_tags, photoinfo_image_tags($image_id), photoinfo_existing_tag_ids($file_tags));

  $added = 0;
  foreach ($outcome['link'] as $name => $group)
  {
    $tag_id = (int)tag_id_from_tag_name(pwg_db_real_escape_string((string)$name));
    if ($group !== null and defined('TYPETAGS_TABLE') and !photoinfo_tag_has_group($tag_id))
    {
      pwg_query('
UPDATE '.TAGS_TABLE.'
  SET id_typetags = '.photoinfo_rescan_group_id($group).'
  WHERE id = '.$tag_id.'
;');
    }

    pwg_query('
INSERT IGNORE INTO '.IMAGE_TAG_TABLE.'
  (image_id, tag_id)
  VALUES ('.(int)$image_id.', '.$tag_id.')
;');
    $added += pwg_db_changes();
  }

  return array('added' => $added, 'not_in_file' => array_values($outcome['not_in_file']));
}

/**
 * For each name a file gives, the tag core's tag_id_from_tag_name() would
 * find for it without creating one: the same name as MariaDB compares it,
 * then the same URL name. Names it finds none for are left out.
 *
 * @param array $file_tags photoinfo_parse_rescan_xml()'s answer
 * @return array name => tag id
 */
function photoinfo_existing_tag_ids($file_tags)
{
  $ids = array();
  foreach (array_keys(photoinfo_file_tag_names($file_tags)) as $name)
  {
    $name = (string)$name;
    foreach (array('name' => $name, 'url_name' => trigger_change('render_tag_url', $name)) as $column => $value)
    {
      $found = query2array('
SELECT id
  FROM '.TAGS_TABLE.'
  WHERE '.$column.' = \''.pwg_db_real_escape_string($value).'\'
  LIMIT 1
;', null, 'id');
      if (count($found) > 0)
      {
        $ids[$name] = (int)$found[0];
        break;
      }
    }
  }

  return $ids;
}

/**
 * @param int $tag_id
 * @return bool whether the tag is in a typetags group
 */
function photoinfo_tag_has_group($tag_id)
{
  list($group) = pwg_db_fetch_row(pwg_query('SELECT id_typetags FROM '.TAGS_TABLE.' WHERE id = '.(int)$tag_id.';'));

  return $group !== null;
}

/**
 * The typetags group of that name, created with typetags' default colour when
 * the install lacks it.
 *
 * @param string $name
 * @return int
 */
function photoinfo_rescan_group_id($name)
{
  $escaped = pwg_db_real_escape_string($name);
  $ids = query2array('SELECT id FROM '.TYPETAGS_TABLE.' WHERE name = \''.$escaped.'\';', null, 'id');
  if (count($ids) > 0)
  {
    return (int)$ids[0];
  }

  pwg_query('
INSERT INTO '.TYPETAGS_TABLE.' (name, color, striped, emoji)
  VALUES (\''.$escaped.'\', \''.PHOTOINFO_RESCAN_GROUP_COLOR.'\', 0, \'\')
;');
  return (int)pwg_db_insert_id(TYPETAGS_TABLE);
}

/**
 * Reads photoinfo's two tags, the two XMP keyword fields and the tags marker
 * from one file. IPTC Keywords is not read: exiftool cuts an entry at 64 bytes.
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
    ' -X -'.PHOTOINFO_INFO_TAG.' -'.PHOTOINFO_DATE_EDTF_TAG.
    ' -'.PHOTOINFO_SUBJECT_TAG.' -'.PHOTOINFO_HIERARCHY_TAG.' -'.PHOTOINFO_TAGS_MARKER_TAG.' '.
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

/**
 * Removes from each photo the tags its marked file does not name. A file
 * without the marker, and a local-only tag, are left alone.
 *
 * @param array $image_ids
 * @return array array('removed' => array(image id => names), 'failed' =>
 *   array(image id => message))
 */
function photoinfo_prune_images($image_ids)
{
  if (!provenance_exiftool_available())
  {
    return array('removed' => array(), 'failed' => array_fill_keys($image_ids, 'exiftool is not available on this server'));
  }

  $query = '
SELECT id, path
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', $image_ids)).')
;';
  $rows = query2array($query, 'id');

  $removed = array();
  $failed = array();
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

    $not_in_file = photoinfo_rescan_tags($tags, photoinfo_image_tags($image_id), photoinfo_existing_tag_ids($tags))['not_in_file'];
    if (count($not_in_file) == 0)
    {
      continue;
    }

    pwg_query('
DELETE FROM '.IMAGE_TAG_TABLE.'
  WHERE image_id = '.(int)$image_id.'
    AND tag_id IN ('.implode(',', array_keys($not_in_file)).')
;');
    $removed[$image_id] = array_values($not_in_file);
  }

  if (count($removed) > 0)
  {
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    invalidate_user_cache_nb_tags();
  }

  return array('removed' => $removed, 'failed' => $failed);
}
