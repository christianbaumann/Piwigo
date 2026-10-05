<?php
defined('PHOTOEDIT_PATH') or die('Hacking attempt!');

include_once(PHOTOEDIT_PATH.'include/pipeline.inc.php');

/**
 * API method pwg.photoedit.apply: turns a photo and writes it into its file.
 *
 * Registered admin_only and post_only; the webmaster check is here, since
 * core's options have no webmaster level (decision 0034).
 *
 * @param mixed[] $params
 *    @option int image_id
 *    @option int turns
 *    @option bool dry_run
 *    @option string pwg_token
 */
function ws_photoedit_apply($params, &$service)
{
  if (!is_webmaster())
  {
    return new PwgError(403, 'Only a webmaster may edit a photo');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $request = photoedit_validate_request($params['turns'], '');
  if (!$request['ok'])
  {
    return new PwgError(WS_ERR_INVALID_PARAM, $request['error']);
  }

  $result = photoedit_apply($params['image_id'], $request['turns'], $params['dry_run']);

  if (!$result['ok'])
  {
    $codes = array(
      'not_found' => 404,
      'unsupported' => WS_ERR_INVALID_PARAM,
      'locked' => 409,
      );
    $code = isset($codes[$result['code']]) ? $codes[$result['code']] : 500;

    return new PwgError($code, $result['message']);
  }

  unset($result['ok']);

  return $result;
}
