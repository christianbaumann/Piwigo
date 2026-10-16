<?php
defined('GALLERYRULES_PATH') or die('Hacking attempt!');

/*
 * The replace option of Fotos hinzufügen never overwrites a photo it had to
 * guess. Core's pwg.images.upload in update mode replaced the photo with the
 * lower id when several in the album had the file name; galleryrules puts a
 * check around it that refuses then, naming them.
 */

include_once(GALLERYRULES_PATH.'include/replace.inc.php');

/**
 * The callback galleryrules_wrap_upload() replaced.
 *
 * @return array callback, include
 */
function &galleryrules_wrapped_upload()
{
  static $wrapped = null;
  return $wrapped;
}

/**
 * ws_add_methods, after core's: registers pwg.images.upload again around its
 * current callback, keeping its parameters, description and options.
 *
 * @param array $arr
 */
function galleryrules_wrap_upload($arr)
{
  $service = $arr[0];
  $method = 'pwg.images.upload';
  $wrapped = &galleryrules_wrapped_upload();
  if (!$service->hasMethod($method) or isset($wrapped))
  {
    return;
  }
  $wrapped = array(
    'callback' => $service->_methods[$method]['callback'],
    'include' => $service->_methods[$method]['include'],
    );

  $service->addMethod(
    $method,
    'ws_galleryrules_images_upload',
    $service->getMethodSignature($method),
    $service->getMethodDescription($method),
    GALLERYRULES_PATH.'include/events_replace.inc.php',
    $service->getMethodOptions($method)
    );
}

/**
 * pwg.images.upload, refused before any byte is written when the replace
 * option would have to choose between photos of one file name.
 *
 * @param array $params
 * @param object $service
 * @return mixed
 */
function ws_galleryrules_images_upload($params, &$service)
{
  if ($params['update_mode'] and !isset($params['format_of']) and !empty($params['category'][0]))
  {
    $name = pwg_db_real_escape_string(stripslashes((string)$params['name']));
    // The photos core's own update-mode query finds.
    $query = '
SELECT DISTINCT i.id
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE i.file = \''.$name.'\'
    AND ic.category_id = '.(int)$params['category'][0].'
;';
    $named = galleryrules_replace_ambiguity(query2array($query, null, 'id'));
    if ($named !== null)
    {
      load_language('plugin.lang', GALLERYRULES_PATH);
      // 409 lets the upload page's error handler show the message; a 200 would not.
      set_status_header(409, 'Conflict');
      return new PwgError(WS_ERR_INVALID_PARAM, l10n(
        'Nothing was replaced: photos %s in this album all have the file name "%s".',
        $named,
        stripslashes((string)$params['name'])
        ));
    }
  }

  $wrapped = galleryrules_wrapped_upload();
  if (!empty($wrapped['include']))
  {
    include_once($wrapped['include']);
  }
  return call_user_func_array($wrapped['callback'], array($params, &$service));
}
