<?php
defined('PHOTOEDIT_PATH') or die('Hacking attempt!');

include_once(PHOTOEDIT_PATH.'include/pipeline.inc.php');

/**
 * API method pwg.photoedit.apply: turns and crops a photo and writes it into its file.
 *
 * Registered post_only. Open to every logged-in account that may see the
 * photo (decision 0059); a photo it may not see is answered like a missing one.
 *
 * @param mixed[] $params
 *    @option int image_id
 *    @option int turns
 *    @option string crop
 *    @option bool dry_run
 *    @option string pwg_token
 */
function ws_photoedit_apply($params, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  if (!photoedit_user_may_edit($params['image_id']))
  {
    return new PwgError(404, PHOTOEDIT_NOT_FOUND_MESSAGE);
  }

  $request = photoedit_validate_request($params['turns'], $params['crop']);
  if (!$request['ok'])
  {
    return new PwgError(WS_ERR_INVALID_PARAM, $request['error']);
  }

  $result = photoedit_apply($params['image_id'], $request['turns'], $request['crop'], $params['dry_run']);

  if (!$result['ok'])
  {
    $codes = array(
      'not_found' => 404,
      'invalid' => WS_ERR_INVALID_PARAM,
      'unsupported' => WS_ERR_INVALID_PARAM,
      'locked' => 409,
      );
    $code = isset($codes[$result['code']]) ? $codes[$result['code']] : 500;

    return new PwgError($code, $result['message']);
  }

  unset($result['ok']);

  return $result;
}
