<?php
defined('PHOTOEDIT_PATH') or die('Hacking attempt!');

include_once(PHOTOEDIT_PATH.'include/pipeline.inc.php');

/**
 * A pipeline or validation message in the calling account's language.
 *
 * The pipeline and the validation keep their English texts, which the logs and
 * the unit tests read; only what leaves the server is translated. A message
 * that carries a value is matched by its shape. A text with no entry (the
 * persons refusal is already German) passes through.
 *
 * @param string $message
 * @return string
 */
function photoedit_localize_message($message)
{
  $valued = array(
    '/^(turns must be a whole number from 0 to )(\d+)$/' => 'turns must be a whole number from 0 to %d',
    '/^(crop must leave at least )(\d+)( pixels on each side)$/' => 'crop must leave at least %d pixels on each side',
    '/^(Only )(.+)( files can be edited)$/' => 'Only %s files can be edited',
    );

  foreach ($valued as $pattern => $key)
  {
    if (preg_match($pattern, $message, $matches))
    {
      return sprintf(l10n($key), $matches[2]);
    }
  }

  return l10n($message);
}

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

  load_language('plugin.lang', PHOTOEDIT_PATH);

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, photoedit_localize_message('Invalid security token'));
  }

  if (!photoedit_user_may_edit($params['image_id']))
  {
    return new PwgError(404, photoedit_localize_message(PHOTOEDIT_NOT_FOUND_MESSAGE));
  }

  $request = photoedit_validate_request($params['turns'], $params['crop']);
  if (!$request['ok'])
  {
    return new PwgError(WS_ERR_INVALID_PARAM, photoedit_localize_message($request['error']));
  }

  $result = photoedit_apply($params['image_id'], $request['turns'], $request['crop'], $params['dry_run']);

  if (!$result['ok'])
  {
    $codes = array(
      'not_found' => 404,
      'invalid' => WS_ERR_INVALID_PARAM,
      'unsupported' => WS_ERR_INVALID_PARAM,
      'locked' => 409,
      'refused' => 403,
      );
    $code = isset($codes[$result['code']]) ? $codes[$result['code']] : 500;

    return new PwgError($code, photoedit_localize_message($result['message']));
  }

  unset($result['ok']);

  return $result;
}
