<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/*
 * The web-service layer. Loaded lazily by ws.php - addMethod()'s fifth argument
 * names this file, so a request that never calls a persons method never reads it.
 *
 * ---------------------------------------------------------------------------
 * The permission model, stated once. See
 * docs/agents/decisions/0019-person-region-permission-model.md.
 *
 *   every method            not a guest
 *   every write             a matching pwg_token
 *   every photo-level call  the photo passes get_sql_condition_FandF()
 *   rename / delete /
 *   rescan                  admin_only, because each reaches past one photo
 *
 * The third check is the one decision 0005 left open for typetags. A photo the
 * caller may not see answers "not found", never "forbidden".
 * ---------------------------------------------------------------------------
 */

include_once(PERSONS_PATH.'include/index.inc.php');
include_once(PERSONS_PATH.'include/rescan.inc.php');

/**
 * The single refusal for a photo the caller may not have.
 *
 * 404 rather than 403, and the same message whether the photo is hidden or
 * absent: a 403 would confirm the id exists, which is exactly what the gate is
 * hiding. Faces are personal data, so the asymmetry is deliberate.
 *
 * @return PwgError
 */
function persons_no_such_image()
{
  return new PwgError(404, 'No such photo');
}

/**
 * Whether the calling user is allowed to see this photo at all.
 *
 * Closes the question docs/agents/decisions/0005-tag-assignment-permission-model.md
 * left open: neither typetags method checks this, so any authenticated user can
 * tag any photo id there.
 *
 * @param int $image_id
 * @return bool
 */
function persons_user_can_see_image($image_id)
{
  $query = '
SELECT COUNT(DISTINCT ic.image_id)
  FROM '.IMAGE_CATEGORY_TABLE.' AS ic
  WHERE ic.image_id = '.(int)$image_id.'
    '.get_sql_condition_FandF(
        array(
          'forbidden_categories' => 'ic.category_id',
          'visible_categories'   => 'ic.category_id',
          'forbidden_images'     => 'ic.image_id',
          ),
        'AND'
        ).'
;';

  list($count) = pwg_db_fetch_row(pwg_query($query));

  return $count > 0;
}

/**
 * The two gates every non-admin write passes: an account, and a token proving
 * the request came from a page this gallery served.
 *
 * @param array $params
 * @return PwgError|null null when the caller may proceed
 */
function persons_check_writer($params)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  return null;
}

/**
 * One image's regions in the shape the picture page renders from.
 *
 * Coordinates are handed over exactly as stored - normalized, centre origin,
 * pre-rotation - and the rotation code travels with them, so the client applies
 * one transform rather than this file inventing a second convention.
 *
 * @param int $image_id
 * @return array|PwgError
 */
function persons_regions_payload($image_id)
{
  $image = pwg_db_fetch_assoc(pwg_query(
    'SELECT width, height, rotation FROM '.IMAGES_TABLE.' WHERE id = '.(int)$image_id.';'
    ));

  if (!$image)
  {
    return persons_no_such_image();
  }

  $rows = persons_indexed_regions($image_id);

  $regions = array();
  foreach ($rows as $row)
  {
    $regions[] = array(
      'id'        => (int)$row['id'],
      'person_id' => (int)$row['person_id'],
      'name'      => $row['name'],
      'url_name'  => $row['url_name'],
      'tag_id'    => $row['tag_id'] === null ? null : (int)$row['tag_id'],
      'x'         => (float)$row['area_x'],
      'y'         => (float)$row['area_y'],
      'w'         => (float)$row['area_w'],
      'h'         => (float)$row['area_h'],
      'type'      => $row['region_type'],
      'source'    => $row['source'],
      'stale'     => persons_region_is_stale(
                       $row['applied_w'], $row['applied_h'], $image['width'], $image['height']),
      );
  }

  return array(
    'image_id' => (int)$image_id,
    'width'    => persons_positive_int_or_null($image['width']),
    'height'   => persons_positive_int_or_null($image['height']),
    'rotation' => $image['rotation'] === null ? null : (int)$image['rotation'],
    'regions'  => $regions,
    );
}

/**
 * API method: the persons a picker may offer.
 *
 * Without a query, the most recently used persons - the list a user sees before
 * typing anything. With one, a substring match on the name.
 *
 * Searched server-side, unlike core's tag picker, which ships the whole tag list
 * to the browser: persons are personal data and the row count is not bounded by
 * a smallint, so shipping all of them to every visitor is the wrong trade.
 *
 * @param array $params
 *    @option string q (optional)
 *    @option int image_id (optional)
 *    @option int per_page
 * @param object $service
 * @return array|PwgError
 */
function ws_persons_getList($params, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  $where = array();

  if (!empty($params['image_id']))
  {
    $image_id = (int)$params['image_id'];

    if (!persons_user_can_see_image($image_id))
    {
      return persons_no_such_image();
    }

    // Whoever is already on this photo is not worth offering again - the picker
    // exists to name somebody new.
    $where[] = 'p.id NOT IN (SELECT person_id FROM '.PERSONS_REGION_TABLE
      .' WHERE image_id = '.$image_id.')';
  }

  $query = persons_clean_name(isset($params['q']) ? $params['q'] : '');

  if ($query !== '')
  {
    // LIKE-escaped first, then SQL-escaped. Without the first step a typed '%'
    // would match every person in the gallery, which is the unbounded list this
    // search exists to avoid.
    $like = addcslashes($query, '%_\\');
    $where[] = "p.name LIKE '%".pwg_db_real_escape_string($like)."%'";
    $order = 'p.name ASC';
  }
  else
  {
    $order = 'p.lastmodified DESC, p.name ASC';
  }

  $rows = query2array('
SELECT p.id, p.name, p.url_name, p.tag_id
  FROM '.PERSONS_TABLE.' AS p
  '.(count($where) ? 'WHERE '.implode("\n    AND ", $where) : '').'
  ORDER BY '.$order.'
  LIMIT '.(int)$params['per_page'].'
;');

  if ($query !== '')
  {
    // The cap above is applied on the collation order so the page is stable;
    // this orders that page the way a reader expects, with accents folded.
    usort($rows, function ($a, $b)
    {
      return strcmp(pwg_transliterate($a['name']), pwg_transliterate($b['name']));
    });
  }

  $persons = array();
  foreach ($rows as $row)
  {
    $persons[] = array(
      'id'       => (int)$row['id'],
      'name'     => $row['name'],
      'url_name' => $row['url_name'],
      'tag_id'   => $row['tag_id'] === null ? null : (int)$row['tag_id'],
      );
  }

  return array('persons' => $persons);
}

/**
 * API method: the regions on one photo.
 *
 * @param array $params
 *    @option int image_id
 * @param object $service
 * @return array|PwgError
 */
function ws_persons_getRegions($params, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (!persons_user_can_see_image($params['image_id']))
  {
    return persons_no_such_image();
  }

  return persons_regions_payload($params['image_id']);
}

/**
 * API method: puts one named box on one photo.
 *
 * Every value is validated here rather than trusted from the drawing surface:
 * the box comes off a mouse drag in a browser, so it is user input at a system
 * boundary.
 *
 * @param array $params
 *    @option int image_id
 *    @option string name
 *    @option float x, y, w, h - normalized, centre origin, pre-rotation
 *    @option string type
 *    @option string pwg_token
 * @param object $service
 * @return array|PwgError
 */
function ws_persons_addRegion($params, &$service)
{
  if (($refusal = persons_check_writer($params)) !== null)
  {
    return $refusal;
  }

  if (!persons_user_can_see_image($params['image_id']))
  {
    return persons_no_such_image();
  }

  $name = persons_clean_name($params['name']);
  if ($name === '')
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'A person needs a name');
  }

  foreach (array('x', 'y', 'w', 'h') as $key)
  {
    if (!persons_is_valid_normalized($params[$key]))
    {
      return new PwgError(WS_ERR_INVALID_PARAM, $key.' must be a number between 0 and 1');
    }
  }

  if (!in_array($params['type'], persons_region_types(), true))
  {
    return new PwgError(WS_ERR_INVALID_PARAM,
      'type must be one of: '.implode(', ', persons_region_types()));
  }

  $region = array(
    'name' => $name,
    'x'    => (float)$params['x'],
    'y'    => (float)$params['y'],
    'w'    => (float)$params['w'],
    'h'    => (float)$params['h'],
    'type' => $params['type'],
    );

  // The same rule the merge applies, asked here so a rejected box is an error
  // the user sees rather than a write that quietly stores nothing.
  if (persons_prepare_region_for_write($region) === null)
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'That box is too small or outside the photo');
  }

  $outcome = persons_apply_change($params['image_id'], array($region), array());

  if (!$outcome['ok'])
  {
    return new PwgError(500, $outcome['message']);
  }

  return persons_regions_payload($params['image_id']);
}

/**
 * API method: removes one box.
 *
 * The photo is looked up from the region, so the visibility gate applies to the
 * photo the region is on rather than to one the caller names.
 *
 * @param array $params
 *    @option int region_id
 *    @option string pwg_token
 * @param object $service
 * @return array|PwgError
 */
function ws_persons_deleteRegion($params, &$service)
{
  if (($refusal = persons_check_writer($params)) !== null)
  {
    return $refusal;
  }

  $region = pwg_db_fetch_assoc(pwg_query('
SELECT r.image_id, r.area_x, r.area_y, r.area_w, r.area_h, p.name
  FROM '.PERSONS_REGION_TABLE.' AS r
  JOIN '.PERSONS_TABLE.' AS p ON p.id = r.person_id
  WHERE r.id = '.(int)$params['region_id'].'
;'));

  // A region that is not there and a region on a photo the caller may not see
  // answer the same way, for the same reason persons_no_such_image() exists.
  if (!$region or !persons_user_can_see_image($region['image_id']))
  {
    return persons_no_such_image();
  }

  $matcher = array(
    'name' => $region['name'],
    'x'    => (float)$region['area_x'],
    'y'    => (float)$region['area_y'],
    'w'    => (float)$region['area_w'],
    'h'    => (float)$region['area_h'],
    );

  $outcome = persons_apply_change($region['image_id'], array(), array($matcher));

  if (!$outcome['ok'])
  {
    return new PwgError(500, $outcome['message']);
  }

  return persons_regions_payload($region['image_id']);
}

/**
 * API method: renames a person everywhere.
 *
 * @param array $params
 *    @option int person_id
 *    @option string name
 *    @option string pwg_token
 * @param object $service
 * @return array|PwgError
 */
function ws_persons_rename($params, &$service)
{
  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $outcome = persons_rename_person($params['person_id'], $params['name']);

  if (!$outcome['ok'])
  {
    return new PwgError(WS_ERR_INVALID_PARAM, $outcome['message']);
  }

  $person = persons_person_row($params['person_id']);

  return array(
    'id'       => (int)$params['person_id'],
    'name'     => $person === null ? null : $person['name'],
    'url_name' => $person === null ? null : $person['url_name'],
    'tag_id'   => ($person === null or $person['tag_id'] === null) ? null : (int)$person['tag_id'],
    'photos'   => $outcome['photos'],
    'failed'   => $outcome['failed'],
    );
}

/**
 * API method: removes a person and their regions from every photo.
 *
 * @param array $params
 *    @option int person_id
 *    @option string pwg_token
 * @param object $service
 * @return array|PwgError
 */
function ws_persons_delete($params, &$service)
{
  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $outcome = persons_delete_person($params['person_id']);

  if (!$outcome['ok'])
  {
    return new PwgError(WS_ERR_INVALID_PARAM, $outcome['message']);
  }

  return array('photos' => $outcome['photos'], 'failed' => $outcome['failed']);
}

/**
 * API method: rebuilds the index of one chunk of photos from their files.
 *
 * One chunk per request, never a whole gallery: a rescan shells out once per
 * photo, so the caller drives the loop and no single request can run into
 * max_execution_time.
 *
 * @param array $params
 *    @option string image_ids comma-separated
 *    @option string pwg_token
 * @param object $service
 * @return array|PwgError
 */
function ws_persons_rescan($params, &$service)
{
  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $ids = array();
  foreach (explode(',', (string)$params['image_ids']) as $raw)
  {
    $raw = trim($raw);
    if ($raw === '')
    {
      continue;
    }
    if (!preg_match('/^\d+$/', $raw) or (int)$raw < 1)
    {
      return new PwgError(WS_ERR_INVALID_PARAM, 'image_ids must be positive integers');
    }
    $ids[(int)$raw] = (int)$raw;
  }

  if (count($ids) == 0)
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'image_ids is empty');
  }

  // Refused rather than truncated: a caller that silently rescanned the first
  // ten of twenty would report success for photos it never opened.
  if (count($ids) > PERSONS_WRITEBACK_MAX_CHUNK)
  {
    return new PwgError(WS_ERR_INVALID_PARAM,
      'At most '.PERSONS_WRITEBACK_MAX_CHUNK.' photos per call');
  }

  $outcome = persons_rescan_images($ids);

  // Every call, not only a run that reached every photo: the row answers "when
  // did anything last re-read a file", and a chunked run has no other end.
  conf_update_param(PERSONS_LAST_RESCAN_PARAM, date('Y-m-d H:i:s'));

  return array('scanned' => $outcome['scanned'], 'failed' => $outcome['failed']);
}
