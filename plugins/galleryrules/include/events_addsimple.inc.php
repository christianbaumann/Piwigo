<?php
defined('GALLERYRULES_PATH') or die('Hacking attempt!');

include_once(GALLERYRULES_PATH.'include/addsimple.inc.php');

/*
 * pwg.images.addSimple, wrapped around core's handler:
 *
 * - a file identical to an existing photo is refused. Core would return that
 *   photo's id and give it the call's title, author, description and albums.
 * - a photo it adds is taken out of the upload lounge at once, so it is in its
 *   album, with the album's Herkunft, without a separate uploadCompleted.
 */

/** The answer code of a refused identical file. */
define('GALLERYRULES_IDENTICAL_FILE_CODE', 409);

/**
 * ws_add_methods, after core's: puts galleryrules around pwg.images.addSimple.
 *
 * @param array $arr
 */
function galleryrules_wrap_addsimple($arr)
{
  $service = $arr[0];
  $method = 'pwg.images.addSimple';
  if (!$service->hasMethod($method))
  {
    return;
  }

  $wrapped = &galleryrules_wrapped_addsimple();
  if (isset($wrapped['callback']))
  {
    return;
  }
  $wrapped = array(
    'callback' => $service->_methods[$method]['callback'],
    'include' => $service->_methods[$method]['include'],
    );

  $service->addMethod(
    $method,
    'ws_galleryrules_images_addSimple',
    $service->getMethodSignature($method),
    $service->getMethodDescription($method),
    GALLERYRULES_PATH.'include/events_addsimple.inc.php',
    $service->getMethodOptions($method)
    );
}

/**
 * The callback galleryrules_wrap_addsimple() replaced.
 *
 * @return array callback, include
 */
function &galleryrules_wrapped_addsimple()
{
  static $wrapped = array();
  return $wrapped;
}

/**
 * pwg.images.addSimple: refuses an identical file, otherwise core's answer,
 * with the new photo already in its albums.
 *
 * @param array $params
 * @param object $service
 * @return mixed
 */
function ws_galleryrules_images_addSimple($params, &$service)
{
  global $conf;

  // Core detects a duplicate only for a new photo and only with this option on.
  if (empty($params['image_id']) and $conf['upload_detect_duplicate']
    and isset($_FILES['image']['tmp_name']) and is_file($_FILES['image']['tmp_name']))
  {
    $identical = query2array('
SELECT id, name, file
  FROM '.IMAGES_TABLE.'
  WHERE md5sum = \''.md5_file($_FILES['image']['tmp_name']).'\'
  ORDER BY id
;');
    if (count($identical) > 0)
    {
      return new PwgError(GALLERYRULES_IDENTICAL_FILE_CODE, galleryrules_identical_file_message($identical));
    }
  }

  $wrapped = galleryrules_wrapped_addsimple();
  if (!empty($wrapped['include']))
  {
    include_once($wrapped['include']);
  }
  $result = call_user_func_array($wrapped['callback'], array($params, &$service));

  if (is_array($result) and isset($result['image_id']))
  {
    galleryrules_leave_lounge((int)$result['image_id']);
  }

  return $result;
}

/**
 * Moves one photo from the upload lounge into the albums it waits for, as
 * empty_lounge() does for all of them. associate_images_to_categories() fires
 * the event provenance passes Herkunft down on.
 *
 * @param int $image_id
 */
function galleryrules_leave_lounge($image_id)
{
  $category_ids = query2array('
SELECT category_id
  FROM '.LOUNGE_TABLE.'
  WHERE image_id = '.$image_id.'
;', null, 'category_id');

  if (count($category_ids) == 0)
  {
    return;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  associate_images_to_categories(array($image_id), $category_ids);

  pwg_query('
DELETE FROM '.LOUNGE_TABLE.'
  WHERE image_id = '.$image_id.'
;');

  invalidate_user_cache();
}
