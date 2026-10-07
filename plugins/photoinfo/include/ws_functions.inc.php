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

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  if (!defined('PROVENANCE_PATH'))
  {
    return new PwgError(500, PHOTOINFO_REQUIRES_PROVENANCE_MESSAGE);
  }

  $query = '
SELECT id, path, '.implode(', ', array_keys(provenance_image_columns())).'
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int)$params['image_id'].'
;';
  $image = pwg_db_fetch_assoc(pwg_query($query));

  if (empty($image))
  {
    return new PwgError(404, 'Invalid image_id');
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
  $result = photoinfo_write_file($image);

  return array(
    'image_id' => (int)$image['id'],
    'info' => $info,
    'written' => $result['ok'],
    'message' => $result['message'],
    );
}
