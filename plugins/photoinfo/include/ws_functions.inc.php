<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

include_once(PHOTOINFO_PATH.'include/writer.inc.php');

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
 * date_creation takes the start of the date, the precision column how much of
 * it is known. Empty fields clear the date. Like the info text, the database
 * stays written when the file write fails.
 *
 * @param array $params image_id, year, month, day, pwg_token
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

  foreach (array('year', 'month', 'day') as $name)
  {
    if (!is_string($params[$name]))
    {
      return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid '.$name);
    }
  }

  $input = photoinfo_date_from_input($params['year'], $params['month'], $params['day'], (int)date('Y'));
  if ($input['error'] !== null)
  {
    return new PwgError(WS_ERR_INVALID_PARAM, $input['error']);
  }
  $date = $input['date'];

  $query = '
UPDATE '.IMAGES_TABLE.'
  SET date_creation = '.($date === null ? 'NULL' : '\''.photoinfo_date_start($date).'\'').',
      photoinfo_date_precision = '.($date === null ? 'NULL' : '\''.photoinfo_date_precision($date).'\'').'
  WHERE id = '.(int)$image['id'].'
;';
  pwg_query($query);

  pwg_activity('photo', $image['id'], 'edit');

  $image['date_creation'] = $date === null ? null : photoinfo_date_start($date);
  $image['photoinfo_date_precision'] = $date === null ? null : photoinfo_date_precision($date);
  $result = photoinfo_write_file($image, PHOTOINFO_WRITE_DATE);

  return array(
    'image_id' => (int)$image['id'],
    'date' => photoinfo_date_display($date),
    'edtf' => photoinfo_date_edtf($date),
    'written' => $result['ok'],
    'message' => $result['message'],
    );
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

  $columns = array_merge(array('id', 'path'), photoinfo_written_columns(), array_keys(provenance_image_columns()));

  $query = '
SELECT '.implode(', ', $columns).'
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int)$params['image_id'].'
;';
  $image = pwg_db_fetch_assoc(pwg_query($query));

  if (empty($image))
  {
    return new PwgError(404, 'Invalid image_id');
  }

  return $image;
}
