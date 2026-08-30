<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * The derived index: two tables that say exactly what the image files say.
 *
 * Every rebuild is destructive by design - this image's rows are replaced by
 * what the file holds right now. Nothing is ever computed from the previous
 * index, so a wrong row cannot outlive one rescan.
 *
 * No explicit transaction wraps a rebuild. Core ships no transaction helper,
 * and piwigo_image_tag is MyISAM, so the tag mirror could not join one anyway.
 * A rebuild interrupted halfway leaves an index that disagrees with the file -
 * which is exactly what a rescan repairs, and the file has lost nothing.
 */

include_once(PERSONS_PATH.'include/exiftool.inc.php');

/**
 * Replaces one image's index rows with what its file says.
 *
 * @param int $image_id
 * @param string $file_path the image on disk
 * @return array array('ok' => bool, 'regions' => int, 'message' => string)
 */
function persons_reindex_image($image_id, $file_path)
{
  $image_id = (int)$image_id;

  $read = persons_read_regions($file_path);

  if (!$read['ok'])
  {
    return array('ok' => false, 'regions' => 0, 'message' => $read['message']);
  }

  $rotation = persons_image_rotation($image_id);

  $rows = array();
  foreach ($read['regions'] as $region)
  {
    // MWG: a region whose centre is outside the frame describes a subject that
    // is not in the picture; one that merely overruns an edge is clipped.
    $clipped = persons_clip_region($region);
    if ($clipped === null)
    {
      continue;
    }

    $rows[] = array(
      'image_id'          => $image_id,
      'person_id'         => persons_person_id_from_name($clipped['name']),
      'area_x'            => $clipped['x'],
      'area_y'            => $clipped['y'],
      'area_w'            => $clipped['w'],
      'area_h'            => $clipped['h'],
      'applied_w'         => $read['applied']['w'],
      'applied_h'         => $read['applied']['h'],
      'rotation_at_write' => $rotation,
      'region_type'       => $clipped['type'],
      'source'            => $clipped['source'],
      );
  }

  pwg_query('DELETE FROM '.PERSONS_REGION_TABLE.' WHERE image_id = '.$image_id.';');

  if (count($rows))
  {
    mass_inserts(PERSONS_REGION_TABLE, array_keys($rows[0]), $rows);
  }

  persons_sync_image_tags($image_id);

  return array('ok' => true, 'regions' => count($rows), 'message' => '');
}

/**
 * The person row for a name, creating it - and its mirrored core tag - the
 * first time the name is seen.
 *
 * The tag mirror is what makes browsing, counting, permission filtering, the
 * menubar and permalinks work with no new code: a person is an ordinary
 * piwigo_tags row as far as the rest of Piwigo is concerned.
 *
 * @param string $name already cleaned by persons_clean_name()
 * @return int the persons.id
 */
function persons_person_id_from_name($name)
{
  $name = persons_clean_name($name);
  if ($name === '')
  {
    throw new InvalidArgumentException('a person needs a name');
  }

  $escaped = pwg_db_real_escape_string($name);

  $result = pwg_query('SELECT id FROM '.PERSONS_TABLE." WHERE name = '".$escaped."';");
  if ($row = pwg_db_fetch_assoc($result))
  {
    return (int)$row['id'];
  }

  // ws.php does not include the admin helpers, so this file pulls them in
  // itself rather than assuming the caller did.
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  $tag_id = (int)tag_id_from_tag_name($name);

  $url_name = pwg_db_fetch_assoc(pwg_query(
    'SELECT url_name FROM '.TAGS_TABLE.' WHERE id = '.$tag_id.';'
    ));

  single_insert(PERSONS_TABLE, array(
    'name'     => $name,
    'url_name' => $url_name ? $url_name['url_name'] : null,
    'tag_id'   => $tag_id,
    ));

  return (int)pwg_db_insert_id();
}

/**
 * Makes this image's mirrored tags match its face regions.
 *
 * Only tags this plugin mirrors are touched: a tag an administrator applied by
 * hand is not the plugin's to remove, and a Pet region is not a person and
 * never becomes a tag at all.
 *
 * @param int $image_id
 * @return void
 */
function persons_sync_image_tags($image_id)
{
  $image_id = (int)$image_id;

  $wanted = array();
  $result = pwg_query('
SELECT DISTINCT p.tag_id
  FROM '.PERSONS_REGION_TABLE.' AS r
  JOIN '.PERSONS_TABLE.' AS p ON p.id = r.person_id
  WHERE r.image_id = '.$image_id.'
    AND r.region_type = \'Face\'
    AND p.tag_id IS NOT NULL
;');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $wanted[] = (int)$row['tag_id'];
  }

  $present = array();
  $result = pwg_query('
SELECT it.tag_id
  FROM '.IMAGE_TAG_TABLE.' AS it
  JOIN '.PERSONS_TABLE.' AS p ON p.tag_id = it.tag_id
  WHERE it.image_id = '.$image_id.'
;');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $present[] = (int)$row['tag_id'];
  }

  $obsolete = array_diff($present, $wanted);
  if (count($obsolete))
  {
    pwg_query('
DELETE FROM '.IMAGE_TAG_TABLE.'
  WHERE image_id = '.$image_id.'
    AND tag_id IN ('.implode(',', $obsolete).')
;');
  }

  $missing = array_diff($wanted, $present);
  if (count($missing))
  {
    $inserts = array();
    foreach ($missing as $tag_id)
    {
      $inserts[] = array('image_id' => $image_id, 'tag_id' => $tag_id);
    }
    mass_inserts(IMAGE_TAG_TABLE, array('image_id', 'tag_id'), $inserts);
  }
}

/**
 * images.rotation for an image, as the code 0..3 core stores.
 *
 * @param int $image_id
 * @return int|null null when the column was never filled
 */
function persons_image_rotation($image_id)
{
  $row = pwg_db_fetch_assoc(pwg_query(
    'SELECT rotation FROM '.IMAGES_TABLE.' WHERE id = '.(int)$image_id.';'
    ));

  return ($row and $row['rotation'] !== null) ? (int)$row['rotation'] : null;
}

/**
 * Applies one change to a photo: read the file, merge, write, re-read, reindex.
 *
 * The whole sequence runs under the image's exclusive lock, not just the
 * exiftool invocation. The file is the only store of the regions, so a writer
 * that read it before another writer's write and wrote afterwards would produce
 * a complete, valid region list with the other writer's face silently missing -
 * a lock around the exec alone prevents the two exiftool processes colliding
 * and nothing else.
 *
 * The re-read at the end is not redundant either: persons_reindex_image() opens
 * the file again rather than being handed the structure that was just written,
 * so a write exiftool accepted but stored differently shows up as an index that
 * disagrees, instead of staying invisible until the next rescan.
 *
 * @param int $image_id
 * @param array $add list of array(name, x, y, w, h, type) to add
 * @param array $remove list of matchers, per persons_merge_regions()
 * @return array array('ok' => bool, 'regions' => int, 'message' => string)
 */
function persons_apply_change($image_id, $add, $remove)
{
  $image_id = (int)$image_id;

  $image = pwg_db_fetch_assoc(pwg_query(
    'SELECT path, width, height FROM '.IMAGES_TABLE.' WHERE id = '.$image_id.';'
    ));

  if (!$image)
  {
    return array('ok' => false, 'regions' => 0, 'message' => 'No such photo');
  }

  $file = persons_image_file_path($image['path']);

  $lock = persons_lock_acquire($image['path']);
  if ($lock === null)
  {
    return array('ok' => false, 'regions' => 0,
      'message' => 'Timed out waiting for another change to this photo');
  }

  try
  {
    $read = persons_read_regions($file);
    if (!$read['ok'])
    {
      return array('ok' => false, 'regions' => 0, 'message' => $read['message']);
    }

    // images.width/height are the RAW file dimensions - SrcImage::__construct()
    // (include/derivative.inc.php:74-92) swaps them for the odd rotation codes
    // rather than core storing them swapped. AppliedToDimensions is a fact about
    // the file, so the raw pair is the right one.
    $applied_w = persons_positive_int_or_null(isset($image['width']) ? $image['width'] : null);
    $applied_h = persons_positive_int_or_null(isset($image['height']) ? $image['height'] : null);

    $merged = persons_merge_regions($read, $add, $remove, $applied_w, $applied_h);

    $write = persons_write_regions($file, $merged['regioninfo'], $merged['names']);
    if (!$write['ok'])
    {
      return array('ok' => false, 'regions' => 0, 'message' => $write['message']);
    }

    return persons_reindex_image($image_id, $file);
  }
  finally
  {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}
