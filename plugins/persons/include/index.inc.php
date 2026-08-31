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

  $correction = persons_correct_for_rotation($image_id, $file_path, $read, $rotation);
  if (!$correction['ok'])
  {
    return array('ok' => false, 'regions' => 0, 'message' => $correction['message']);
  }
  $read = $correction['read'];

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
 * Turns a file's regions with the file when something outside Piwigo rotated it.
 *
 * This Piwigo has no rotate action of its own - images.rotation is written only
 * by sync and upload, out of Exif Orientation - so there is no event to hook.
 * The trigger is a detected change, evaluated on every reindex, and the two
 * changes that look alike from here have to be told apart:
 *
 *   - the rotation code moved but the file still has the shape its
 *     AppliedToDimensions claims: only the display transform changed. MWG stores
 *     regions before Exif Orientation, so the file is already correct and must
 *     not be touched. The reindex records the new code and nothing else.
 *   - the file's dimensions are the transpose of its AppliedToDimensions: the
 *     bytes were physically turned. Every region turns by the same delta and the
 *     corrected list goes back into the file.
 *
 * Anything else - a crop, a resize - is left to the staleness indicator.
 *
 * A photo with no index rows yet is left alone: a first index has no earlier
 * orientation to compare against, and the file's regions are by definition what
 * MWG says they are.
 *
 * @param int $image_id
 * @param string $file_path
 * @param array $read the persons_read_regions() result for that file
 * @param int|null $rotation images.rotation now
 * @return array array('ok' => bool, 'message' => string, 'read' => array) - the
 *   read to index, which is a fresh one when the file was corrected
 */
function persons_correct_for_rotation($image_id, $file_path, $read, $rotation)
{
  $unchanged = array('ok' => true, 'message' => '', 'read' => $read);

  if (count($read['regions']) == 0)
  {
    return $unchanged;
  }

  $stored = pwg_db_fetch_assoc(pwg_query('
SELECT rotation_at_write
  FROM '.PERSONS_REGION_TABLE.'
  WHERE image_id = '.(int)$image_id.'
  LIMIT 1
;'));

  if (!$stored)
  {
    return $unchanged;
  }

  $image = pwg_db_fetch_assoc(pwg_query(
    'SELECT path, width, height FROM '.IMAGES_TABLE.' WHERE id = '.(int)$image_id.';'
    ));

  if (!$image)
  {
    return $unchanged;
  }

  $delta = persons_rotation_delta(
    $stored['rotation_at_write'],
    $rotation,
    $read['applied']['w'],
    $read['applied']['h'],
    $image['width'],
    $image['height']
    );

  if ($delta == 0)
  {
    return $unchanged;
  }

  // The read that decided this happened outside the lock, so the whole
  // decision is made again inside it against a fresh read of the file.
  $lock = persons_lock_acquire($image['path']);
  if ($lock === null)
  {
    return array('ok' => false, 'read' => $read,
      'message' => 'Timed out waiting for another change to this photo');
  }

  try
  {
    $fresh = persons_read_regions($file_path);
    if (!$fresh['ok'])
    {
      return array('ok' => false, 'message' => $fresh['message'], 'read' => $read);
    }

    $delta = persons_rotation_delta(
      $stored['rotation_at_write'],
      $rotation,
      $fresh['applied']['w'],
      $fresh['applied']['h'],
      $image['width'],
      $image['height']
      );

    if ($delta == 0)
    {
      return array('ok' => true, 'message' => '', 'read' => $fresh);
    }

    $turned = array();
    foreach ($fresh['regions'] as $region)
    {
      $turned[] = persons_rotate_region($region, $delta);
    }

    // Merged with no existing regions and no removals: the turned list replaces
    // the file's own, and the merge is still what builds the payload, so the
    // names and the entries the parser could not read are handled in one place.
    $merged = persons_merge_regions(
      array('regions' => array(), 'unusable' => $fresh['unusable'], 'names' => $fresh['names']),
      $turned,
      array(),
      $image['width'],
      $image['height']
      );

    $write = persons_write_regions($file_path, $merged['regioninfo'], $merged['names']);
    if (!$write['ok'])
    {
      return array('ok' => false, 'message' => $write['message'], 'read' => $read);
    }

    // Re-read rather than indexing the structure just written, for the same
    // reason persons_apply_change() re-reads: a write exiftool accepted but
    // stored differently shows up now instead of at the next rescan.
    $after = persons_read_regions($file_path);
    if (!$after['ok'])
    {
      return array('ok' => false, 'message' => $after['message'], 'read' => $read);
    }

    return array('ok' => true, 'message' => '', 'read' => $after);
  }
  finally
  {
    persons_lock_release($lock);
  }
}

/**
 * Drops the index rows of photos core has just deleted.
 *
 * Registered on core's delete_elements notification. Core clears
 * piwigo_image_tag itself, so the mirrored tag assignment needs nothing here;
 * piwigo_person_region is this plugin's own table and core knows nothing about
 * it, so without this the rows outlive the photo and every count on the admin
 * screen is wrong by however many photos have ever been deleted.
 *
 * The person rows themselves stay. A person whose last photo was deleted is
 * still a person somebody named, and deleting them is an explicit action.
 *
 * @param array $image_ids the ids core has removed
 * @return void
 */
function persons_delete_elements($image_ids)
{
  $ids = array();
  foreach ((array)$image_ids as $id)
  {
    $id = (int)$id;
    if ($id > 0)
    {
      $ids[$id] = $id;
    }
  }

  if (count($ids) == 0)
  {
    return;
  }

  pwg_query(
    'DELETE FROM '.PERSONS_REGION_TABLE.' WHERE image_id IN ('.implode(',', $ids).');'
    );
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

  if (count($obsolete) or count($missing))
  {
    persons_invalidate_tag_cache();
  }
}

/**
 * Discards every user's cached count of available tags.
 *
 * Unscoped on purpose - which users a tag became visible to depends on album
 * permissions this function would have to recompute. Over-invalidation costs one
 * recount per user and is always safe; under-invalidation shows a wrong count
 * with no way to notice. See
 * docs/agents/decisions/0004-unscoped-tag-cache-invalidation-accepted.md.
 *
 * @return void
 */
function persons_invalidate_tag_cache()
{
  pwg_query('UPDATE '.USER_CACHE_TABLE.' SET nb_available_tags = NULL;');
}

/**
 * Marks persons as just used, so the picker's recency ordering means what it says.
 *
 * piwigo_persons.lastmodified is ON UPDATE CURRENT_TIMESTAMP, and a person row
 * is inserted once and never updated again - so without this it records when a
 * person was first *created*, and pwg.persons.getList's "most recently used"
 * would silently be "most recently added".
 *
 * @param array $regions the regions just added, each with a name
 * @return void
 */
function persons_touch_persons($regions)
{
  $names = array();
  foreach ($regions as $region)
  {
    $name = persons_clean_name(isset($region['name']) ? $region['name'] : '');
    if ($name !== '')
    {
      $names[$name] = "'".pwg_db_real_escape_string($name)."'";
    }
  }

  if (count($names) == 0)
  {
    return;
  }

  // lastmodified is ON UPDATE, so it only moves when a column actually changes -
  // assigning name to itself would be a no-op row and leave the timestamp alone.
  pwg_query(
    'UPDATE '.PERSONS_TABLE.' SET lastmodified = NOW()'
    .' WHERE name IN ('.implode(',', $names).');'
    );
}

/**
 * Where a person's name links to: the gallery page of their mirrored tag.
 *
 * That mirror is the whole reason browsing needs no new code. A person whose
 * tag row is gone - an administrator may delete a tag by hand - gets no link
 * rather than a broken one.
 *
 * @param array $row a persons_indexed_regions() row
 * @return string '' when there is nowhere to link to
 */
function persons_person_gallery_url($row)
{
  if (empty($row['tag_id']))
  {
    return '';
  }

  return make_index_url(array(
    'tags' => array(
      array(
        'id'       => (int)$row['tag_id'],
        'url_name' => $row['url_name'],
        ),
      ),
    ));
}

/**
 * This image's indexed regions, newest person data joined in.
 *
 * The one place the region rows are read. Both the web-service payload and the
 * public picture page go through it, so a column added here reaches both and
 * neither can drift into reading a different set.
 *
 * @param int $image_id
 * @return array rows, ordered by region id
 */
function persons_indexed_regions($image_id)
{
  return query2array('
SELECT r.id, r.person_id, p.name, p.url_name, p.tag_id,
       r.area_x, r.area_y, r.area_w, r.area_h,
       r.applied_w, r.applied_h, r.region_type, r.source
  FROM '.PERSONS_REGION_TABLE.' AS r
  JOIN '.PERSONS_TABLE.' AS p ON p.id = r.person_id
  WHERE r.image_id = '.(int)$image_id.'
  ORDER BY r.id
;');
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

    $outcome = persons_reindex_image($image_id, $file);

    // Only the names this call added, and only after the write succeeded. The
    // reindex resolves every name in the file, so touching there would count a
    // person as "used" because somebody else was tagged on the same photo.
    if ($outcome['ok'])
    {
      persons_touch_persons($add);
    }

    return $outcome;
  }
  finally
  {
    persons_lock_release($lock);
  }
}

/**
 * One person's row, or null.
 *
 * @param int $person_id
 * @return array|null id, name, url_name, tag_id
 */
function persons_person_row($person_id)
{
  $row = pwg_db_fetch_assoc(pwg_query(
    'SELECT id, name, url_name, tag_id FROM '.PERSONS_TABLE.' WHERE id = '.(int)$person_id.';'
    ));

  return $row ? $row : null;
}

/**
 * The photos carrying at least one region for a person.
 *
 * @param int $person_id
 * @return array list of image ids
 */
function persons_person_images($person_id)
{
  $ids = array();
  $result = pwg_query(
    'SELECT DISTINCT image_id FROM '.PERSONS_REGION_TABLE.' WHERE person_id = '.(int)$person_id.';'
    );
  while ($row = pwg_db_fetch_assoc($result))
  {
    $ids[] = (int)$row['image_id'];
  }

  return $ids;
}

/**
 * Renames a person everywhere the name is stored.
 *
 * The database moves first and the files afterwards, and the order is not
 * arbitrary: persons_reindex_image() resolves each name it reads through
 * persons_person_id_from_name(), so a file rewritten while the row still said
 * the old name would come back indexed against a second, newly created person.
 *
 * A file this cannot rewrite is reported against its photo and the rest go on -
 * one unwritable file must not leave the rename half-applied everywhere else.
 * The photos it could not reach keep the old name until a later rescan, which is
 * visible in 'failed' rather than silent.
 *
 * @param int $person_id
 * @param string $new_name already the caller's raw input; cleaned here
 * @return array array('ok' => bool, 'message' => string, 'photos' => int,
 *   'failed' => array(image id => message))
 */
function persons_rename_person($person_id, $new_name)
{
  $person_id = (int)$person_id;
  $failure = function ($message)
  {
    return array('ok' => false, 'message' => $message, 'photos' => 0, 'failed' => array());
  };

  $new_name = persons_clean_name($new_name);
  if ($new_name === '')
  {
    return $failure('A person needs a name');
  }

  $person = persons_person_row($person_id);
  if ($person === null)
  {
    return $failure('No such person');
  }

  if ($person['name'] === $new_name)
  {
    return array('ok' => true, 'message' => '', 'photos' => 0, 'failed' => array());
  }

  $taken = pwg_db_fetch_assoc(pwg_query(
    'SELECT id FROM '.PERSONS_TABLE
    ." WHERE name = '".pwg_db_real_escape_string($new_name)."' AND id <> ".$person_id.';'
    ));
  if ($taken)
  {
    // Merging two persons is deliberately not offered - see the plan's
    // "What We're NOT Doing". Silently folding one into the other here would be
    // that feature, minus the chance to undo it.
    return $failure('Another person already has that name');
  }

  $url_name = trigger_change('render_tag_url', $new_name);

  pwg_query(
    'UPDATE '.PERSONS_TABLE
    ." SET name = '".pwg_db_real_escape_string($new_name)."',"
    ." url_name = '".pwg_db_real_escape_string($url_name)."'"
    .' WHERE id = '.$person_id.';'
    );

  if ($person['tag_id'] !== null)
  {
    pwg_query(
      'UPDATE '.TAGS_TABLE
      ." SET name = '".pwg_db_real_escape_string($new_name)."',"
      ." url_name = '".pwg_db_real_escape_string($url_name)."'"
      .' WHERE id = '.(int)$person['tag_id'].';'
      );
  }

  persons_invalidate_tag_cache();

  $failed = array();
  $photos = 0;

  foreach (persons_person_images($person_id) as $image_id)
  {
    $add = array();
    $result = pwg_query('
SELECT area_x, area_y, area_w, area_h, region_type
  FROM '.PERSONS_REGION_TABLE.'
  WHERE image_id = '.$image_id.' AND person_id = '.$person_id.'
;');
    while ($row = pwg_db_fetch_assoc($result))
    {
      $add[] = array(
        'name' => $new_name,
        'x'    => (float)$row['area_x'],
        'y'    => (float)$row['area_y'],
        'w'    => (float)$row['area_w'],
        'h'    => (float)$row['area_h'],
        'type' => $row['region_type'],
        );
    }

    $outcome = persons_apply_change($image_id, $add, array(array('name' => $person['name'])));

    if ($outcome['ok'])
    {
      $photos++;
    }
    else
    {
      $failed[$image_id] = $outcome['message'];
    }
  }

  return array('ok' => true, 'message' => '', 'photos' => $photos, 'failed' => $failed);
}

/**
 * Removes a person: their regions leave every file, then the index rows and the
 * person row go.
 *
 * The mirrored tag is left behind for core's orphan-tag mechanism
 * (get_orphan_tags(), admin/include/functions.php:430) rather than dropped here:
 * an administrator may have applied that tag by hand to photos that never
 * carried a region, and those assignments are not this plugin's to delete.
 *
 * @param int $person_id
 * @return array array('ok' => bool, 'message' => string, 'photos' => int,
 *   'failed' => array(image id => message))
 */
function persons_delete_person($person_id)
{
  $person_id = (int)$person_id;

  $person = persons_person_row($person_id);
  if ($person === null)
  {
    return array('ok' => false, 'message' => 'No such person', 'photos' => 0, 'failed' => array());
  }

  $failed = array();
  $photos = 0;

  foreach (persons_person_images($person_id) as $image_id)
  {
    $outcome = persons_apply_change($image_id, array(), array(array('name' => $person['name'])));

    if ($outcome['ok'])
    {
      $photos++;
    }
    else
    {
      $failed[$image_id] = $outcome['message'];
    }
  }

  // Unconditional, including for the files that could not be rewritten: the
  // person is gone from the gallery either way, and the next rescan of an
  // unwritten file puts its regions back under a fresh person rather than
  // leaving rows pointing at a row that no longer exists.
  pwg_query('DELETE FROM '.PERSONS_REGION_TABLE.' WHERE person_id = '.$person_id.';');
  pwg_query('DELETE FROM '.PERSONS_TABLE.' WHERE id = '.$person_id.';');

  if ($person['tag_id'] !== null)
  {
    pwg_query(
      'DELETE FROM '.IMAGE_TAG_TABLE.' WHERE tag_id = '.(int)$person['tag_id'].';'
      );
  }

  persons_invalidate_tag_cache();

  return array('ok' => true, 'message' => '', 'photos' => $photos, 'failed' => $failed);
}
