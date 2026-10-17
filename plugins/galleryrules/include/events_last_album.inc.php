<?php
defined('GALLERYRULES_PATH') or die('Hacking attempt!');

/*
 * A photo's last album cannot be removed. Core let the Batch Manager's
 * "Von Album abtrennen", the photo's properties page and the API take a photo
 * out of every album; it then disappeared for everyone but administrators,
 * with no hint. Each path is refused where it would leave a photo in no
 * album; moving a photo to another album stays possible.
 */

include_once(GALLERYRULES_PATH.'include/last_album.inc.php');

/** The answer code of a refused web-service call. */
define('GALLERYRULES_LAST_ALBUM_CODE', 409);

/**
 * The albums of some photos and their storage album.
 *
 * @param int[] $image_ids
 * @return array image id => array('albums' => int[], 'storage' => int|null)
 */
function galleryrules_photo_albums($image_ids)
{
  $image_ids = galleryrules_unique_ids($image_ids);
  if (count($image_ids) == 0)
  {
    return array();
  }

  $photos = array();
  $result = pwg_query('
SELECT i.id, i.storage_category_id, ic.category_id
  FROM '.IMAGES_TABLE.' AS i
    LEFT JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE i.id IN ('.implode(',', $image_ids).')
;');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $id = (int)$row['id'];
    if (!isset($photos[$id]))
    {
      $photos[$id] = array(
        'albums' => array(),
        'storage' => isset($row['storage_category_id']) ? (int)$row['storage_category_id'] : null,
        );
    }
    if (isset($row['category_id']))
    {
      $photos[$id]['albums'][] = (int)$row['category_id'];
    }
  }
  return $photos;
}

/**
 * The photos taking them out of one album would leave in no album.
 *
 * @param int[] $image_ids
 * @param int $album_id
 * @return int[]
 */
function galleryrules_left_by_dissociate($image_ids, $album_id)
{
  $before = $after = array();
  foreach (galleryrules_photo_albums($image_ids) as $id => $photo)
  {
    $before[$id] = $photo['albums'];
    $after[$id] = galleryrules_albums_after_dissociate($photo['albums'], $photo['storage'], $album_id);
  }
  return galleryrules_left_without_album($before, $after);
}

/**
 * The refusal, naming the photos.
 *
 * @param int[] $image_ids
 * @return string
 */
function galleryrules_last_album_message($image_ids)
{
  load_language('plugin.lang', GALLERYRULES_PATH);
  $named = implode(', ', array_map(function ($id) { return '#'.$id; }, $image_ids));
  if (count($image_ids) == 1)
  {
    return l10n('Nothing was removed: photo %s would be in no album any more. Move it to another album instead.', $named);
  }
  return l10n('Nothing was removed: photos %s would be in no album any more. Move them to another album instead.', $named);
}

/**
 * The refusal of an album delete, naming the photos.
 *
 * @param int[] $image_ids
 * @return string
 */
function galleryrules_album_delete_message($image_ids)
{
  load_language('plugin.lang', GALLERYRULES_PATH);
  $named = implode(', ', array_map(function ($id) { return '#'.$id; }, $image_ids));
  if (count($image_ids) == 1)
  {
    return l10n('Nothing was deleted: photo %s would be in no album any more. Move it to another album first or delete it with the album.', $named);
  }
  return l10n('Nothing was deleted: photos %s would be in no album any more. Move them to another album first or delete them with the album.', $named);
}

/**
 * The photos deleting these albums (and their sub-albums) with "keep photos"
 * would leave in no album.
 *
 * @param mixed $category_id array or "1,2;3" as pwg.categories.delete takes it
 * @return int[]
 */
function galleryrules_left_by_album_delete_ids($category_id)
{
  if (!is_array($category_id))
  {
    $category_id = preg_split('/[\s,;\|]/', (string)$category_id, -1, PREG_SPLIT_NO_EMPTY);
  }
  $ids = array_filter(array_map('intval', $category_id), function ($id) { return $id > 0; });
  if (count($ids) == 0)
  {
    return array();
  }

  $deleted = get_subcat_ids(array_values($ids));
  if (count($deleted) == 0)
  {
    return array();
  }
  $linked = query2array('
SELECT DISTINCT image_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id IN ('.implode(',', array_map('intval', $deleted)).')
;', null, 'image_id');

  return galleryrules_left_by_album_delete(galleryrules_photo_albums($linked), $deleted);
}

/**
 * loc_begin_element_set_global: the Batch Manager's "Von Album abtrennen" is
 * refused, for the whole selection, when it would leave a photo in no album.
 * The pwg_token is checked by core before this event.
 */
function galleryrules_batch_dissociate()
{
  global $page;

  if (!isset($_POST['submit']) or @$_POST['selectAction'] !== 'dissociate'
    or !isset($_POST['dissociate']) or !preg_match('/^\d+$/', (string)$_POST['dissociate']))
  {
    return;
  }

  // The selection as batch_manager_global.php reads it.
  $collection = array();
  if (isset($_POST['setSelected']))
  {
    $collection = explode(',', (string)@$_POST['whole_set']);
  }
  elseif (isset($_POST['selection']))
  {
    $collection = (array)$_POST['selection'];
  }
  $collection = array_filter($collection, function ($id) { return preg_match('/^\d+$/', (string)$id); });

  $left = galleryrules_left_by_dissociate($collection, (int)$_POST['dissociate']);
  if (count($left) > 0)
  {
    $page['errors'][] = galleryrules_last_album_message($left);
    unset($_POST['submit']);
  }
}

/**
 * picture_modify_before_update: the properties page keeps the photo's albums
 * as they are when the albums sent would leave it in none. Its other fields
 * are still saved.
 *
 * @param array $data
 * @return array $data, unchanged
 */
function galleryrules_properties_keep_last_album($data)
{
  global $page;

  $image_id = (int)$data['id'];
  $targets = array_filter((array)@$_POST['associate'], function ($id) { return preg_match('/^\d+$/', (string)$id); });
  $photos = galleryrules_photo_albums(array($image_id));
  if (!isset($photos[$image_id]))
  {
    return $data;
  }
  $photo = $photos[$image_id];

  $left = galleryrules_left_without_album(
    array($image_id => $photo['albums']),
    array($image_id => galleryrules_albums_after_move($photo['albums'], $photo['storage'], $targets))
    );
  if (count($left) > 0)
  {
    $_POST['associate'] = array_map('strval', $photo['albums']);
    $page['errors'][] = galleryrules_last_album_message($left);
  }
  return $data;
}

/**
 * ws_add_methods, after core's and after other plugins' wrappers: puts the
 * check around pwg.images.setInfo and pwg.images.setCategory, outermost, so a
 * refused call reaches no other handler.
 *
 * @param array $arr
 */
function galleryrules_wrap_album_methods($arr)
{
  $service = $arr[0];
  $methods = array(
    'pwg.images.setInfo' => 'ws_galleryrules_images_setInfo',
    'pwg.images.setCategory' => 'ws_galleryrules_images_setCategory',
    'pwg.categories.delete' => 'ws_galleryrules_categories_delete',
    );
  $wrapped = &galleryrules_wrapped_album_methods();
  foreach ($methods as $method => $callback)
  {
    if (!$service->hasMethod($method) or isset($wrapped[$method]))
    {
      continue;
    }
    $wrapped[$method] = array(
      'callback' => $service->_methods[$method]['callback'],
      'include' => $service->_methods[$method]['include'],
      );
    $service->addMethod(
      $method,
      $callback,
      $service->getMethodSignature($method),
      $service->getMethodDescription($method),
      GALLERYRULES_PATH.'include/events_last_album.inc.php',
      $service->getMethodOptions($method)
      );
  }
}

/**
 * The callbacks galleryrules_wrap_album_methods() replaced, by method name.
 *
 * @return array method => array('callback', 'include')
 */
function &galleryrules_wrapped_album_methods()
{
  static $wrapped = array();
  return $wrapped;
}

/**
 * Calls the handler a method had before galleryrules wrapped it.
 *
 * @param string $method
 * @param array $params
 * @param object $service
 * @return mixed
 */
function galleryrules_call_wrapped_album_method($method, $params, &$service)
{
  $wrapped = galleryrules_wrapped_album_methods();
  if (!empty($wrapped[$method]['include']))
  {
    include_once($wrapped[$method]['include']);
  }
  return call_user_func_array($wrapped[$method]['callback'], array($params, &$service));
}

/**
 * Whether the call carries this session's token. A call without it is left to
 * core, which refuses it as before.
 *
 * @param array $params
 * @return bool
 */
function galleryrules_valid_token($params)
{
  return isset($params['pwg_token']) and get_pwg_token() == $params['pwg_token'];
}

/**
 * pwg.images.setInfo: categories in replace mode that would leave the photo in
 * no album are refused, and nothing of the call is saved.
 *
 * @param array $params
 * @param object $service
 * @return mixed
 */
function ws_galleryrules_images_setInfo($params, &$service)
{
  if (isset($params['categories']) and 'replace' == $params['multiple_value_mode']
    and (!isset($params['pwg_token']) or galleryrules_valid_token($params)))
  {
    $image_id = (int)$params['image_id'];
    $photos = galleryrules_photo_albums(array($image_id));
    if (isset($photos[$image_id]))
    {
      $left = galleryrules_left_without_album(
        array($image_id => $photos[$image_id]['albums']),
        array($image_id => galleryrules_category_string_ids($params['categories']))
        );
      if (count($left) > 0)
      {
        return new PwgError(GALLERYRULES_LAST_ALBUM_CODE, galleryrules_last_album_message($left));
      }
    }
  }
  return galleryrules_call_wrapped_album_method('pwg.images.setInfo', $params, $service);
}

/**
 * pwg.images.setCategory: "dissociate" that would leave a photo in no album is
 * refused for all photos of the call. "associate" and "move" pass.
 *
 * @param array $params
 * @param object $service
 * @return mixed
 */
function ws_galleryrules_images_setCategory($params, &$service)
{
  if ('dissociate' == $params['action'] and galleryrules_valid_token($params))
  {
    $left = galleryrules_left_by_dissociate($params['image_id'], $params['category_id']);
    if (count($left) > 0)
    {
      return new PwgError(GALLERYRULES_LAST_ALBUM_CODE, galleryrules_last_album_message($left));
    }
  }
  return galleryrules_call_wrapped_album_method('pwg.images.setCategory', $params, $service);
}

/**
 * pwg.categories.delete: "keep photos" that would leave a photo in no album is
 * refused and nothing is deleted. Deleting the photos with the album passes.
 *
 * @param array $params
 * @param object $service
 * @return mixed
 */
function ws_galleryrules_categories_delete($params, &$service)
{
  if ('no_delete' == $params['photo_deletion_mode'] and galleryrules_valid_token($params))
  {
    $left = galleryrules_left_by_album_delete_ids($params['category_id']);
    if (count($left) > 0)
    {
      return new PwgError(GALLERYRULES_LAST_ALBUM_CODE, galleryrules_album_delete_message($left));
    }
  }
  return galleryrules_call_wrapped_album_method('pwg.categories.delete', $params, $service);
}
