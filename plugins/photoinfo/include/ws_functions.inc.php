<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

include_once(PHOTOINFO_PATH.'include/writer.inc.php');
include_once(PHOTOINFO_PATH.'include/rescan.inc.php');

/**
 * Saves one photo's info text and writes it into the image file.
 *
 * The database is written first and stays written when the file write fails:
 * the answer then says so, and the administrator's text is not lost.
 *
 * @param array $params image_id, info, pwg_token
 * @param object $service
 * @return array|PwgError
 */
function ws_photoinfo_setInfo($params, &$service)
{
  global $conf;

  $image = photoinfo_ws_image($params);
  if ($image instanceof PwgError)
  {
    return $image;
  }

  if (!is_string($params['info']))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid info');
  }

  // include/common.inc.php adds slashes to every request value; the text goes
  // into the file as well as the database, so it is taken back to what was typed.
  $info = photoinfo_clean_info(stripslashes($params['info']), $conf['allow_html_descriptions']);

  $query = '
UPDATE '.IMAGES_TABLE.'
  SET comment = '.($info === '' ? 'NULL' : '\''.pwg_db_real_escape_string($info).'\'').'
  WHERE id = '.(int)$image['id'].'
;';
  pwg_query($query);

  pwg_activity('photo', $image['id'], 'edit');

  $image['comment'] = $info;
  $result = photoinfo_write_file($image, PHOTOINFO_WRITE_INFO);

  return array(
    'image_id' => (int)$image['id'],
    'info' => $info,
    'written' => $result['ok'],
    'message' => $result['message'],
    );
}

/**
 * Saves one photo's date and writes it into the image file.
 *
 * date_creation takes the start of the date, the plugin's columns its
 * precision, qualifier and range end. Empty fields clear the date. Like the
 * info text, the database stays written when the file write fails.
 *
 * @param array $params image_id, qualifier, year, month, day, end_year, end_month, end_day, pwg_token
 * @param object $service
 * @return array|PwgError
 */
function ws_photoinfo_setDate($params, &$service)
{
  $image = photoinfo_ws_image($params);
  if ($image instanceof PwgError)
  {
    return $image;
  }

  foreach (array('qualifier', 'year', 'month', 'day', 'end_year', 'end_month', 'end_day') as $name)
  {
    if (!is_string($params[$name]))
    {
      return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid '.$name);
    }
  }

  $input = photoinfo_dating_from_input(
    $params['qualifier'],
    array($params['year'], $params['month'], $params['day']),
    array($params['end_year'], $params['end_month'], $params['end_day']),
    (int)date('Y')
    );
  if ($input['error'] !== null)
  {
    return new PwgError(WS_ERR_INVALID_PARAM, $input['error']);
  }
  $dating = $input['dating'];

  $columns = photoinfo_dating_columns($dating);
  photoinfo_update_image($image['id'], $columns);

  pwg_activity('photo', $image['id'], 'edit');

  $result = photoinfo_write_file(array_merge($image, $columns), PHOTOINFO_WRITE_DATE);

  return array(
    'image_id' => (int)$image['id'],
    'date' => photoinfo_dating_display($dating),
    'edtf' => photoinfo_dating_edtf($dating),
    'written' => $result['ok'],
    'message' => $result['message'],
    );
}

/**
 * Restores one chunk of photos' dates and info texts from their files. Writes
 * the database only, never a file.
 *
 * One chunk per request, never a whole gallery: each photo costs one exiftool
 * run, so the caller drives the loop and no request runs into
 * max_execution_time.
 *
 * @param array $params image_ids (comma-separated), pwg_token
 * @param object $service
 * @return array|PwgError
 */
function ws_photoinfo_rescan($params, &$service)
{
  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  if (!defined('PROVENANCE_PATH'))
  {
    return new PwgError(500, PHOTOINFO_REQUIRES_PROVENANCE_MESSAGE);
  }

  // Refused rather than truncated: a caller that silently rescanned the first
  // ten of twenty would report success for photos nothing read.
  $ids = provenance_parse_id_list($params['image_ids'], PHOTOINFO_RESCAN_MAX_CHUNK);
  if ($ids === null or count($ids) == 0)
  {
    return new PwgError(WS_ERR_INVALID_PARAM,
      'image_ids must be 1 to '.PHOTOINFO_RESCAN_MAX_CHUNK.' comma-separated photo ids');
  }

  return photoinfo_rescan_images($ids);
}

/**
 * Checks a save request's token and loads the photo it names, with every column
 * a file write needs.
 *
 * @param array $params image_id, pwg_token
 * @return array|PwgError
 */
function photoinfo_ws_image($params)
{
  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  if (!defined('PROVENANCE_PATH'))
  {
    return new PwgError(500, PHOTOINFO_REQUIRES_PROVENANCE_MESSAGE);
  }

  $image = photoinfo_image_row($params['image_id']);
  if ($image === null)
  {
    return new PwgError(404, 'Invalid image_id');
  }

  return $image;
}
