<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

include_once(PHOTOINFO_PATH.'include/writer.inc.php');

/*
 * Core's own screens that save a photo's date or description, kept in agreement
 * with the plugin's columns and the image file:
 *
 * - the photo properties screen: picture_modify_before_update, then
 *   loc_end_picture_modify once the row is saved
 * - the Batch Manager's global "Set creation date": element_set_global_action
 * - pwg.images.setInfo, which the Batch Manager's unit mode calls: no event
 *   after the save, so the method is registered again around core's
 *
 * The same screens' tag changes are in events_tags.inc.php, beside the other
 * paths that change tags.
 */

/**
 * picture_modify_before_update: remembers the date and description the save
 * is about to replace.
 *
 * @param array $data the row core is about to save
 * @return array unchanged
 */
function photoinfo_picture_modify_before_update($data)
{
  global $photoinfo_core_edit_before;

  $photoinfo_core_edit_before = photoinfo_core_edit_snapshot($data['id']);

  return $data;
}

/**
 * loc_end_picture_modify: after a save on the photo properties screen.
 */
function photoinfo_picture_modify_after_save()
{
  global $photoinfo_core_edit_before;

  if (empty($photoinfo_core_edit_before))
  {
    return;
  }

  $before = $photoinfo_core_edit_before;
  $photoinfo_core_edit_before = null;

  photoinfo_report_core_edit(photoinfo_apply_core_edit($before['id'], $before));
}

/**
 * element_set_global_action: after a Batch Manager action on a selection. Only
 * "Set creation date" concerns this plugin; it sets the date outright, so every
 * selected photo counts as changed. Every row is brought in line before the
 * first file is written: exiftool takes a moment per photo, and a request that
 * runs out of time must not leave a "ca." beside a date it no longer belongs to.
 *
 * @param string $action
 * @param array $collection image ids
 */
function photoinfo_element_set_global_action($action, $collection)
{
  if ($action != 'date_creation')
  {
    return;
  }

  $to_write = array();
  foreach ($collection as $image_id)
  {
    if (photoinfo_store_core_edit($image_id, null))
    {
      $to_write[] = $image_id;
    }
  }

  foreach ($to_write as $image_id)
  {
    photoinfo_report_core_edit(photoinfo_write_core_edit($image_id));
  }
}

/**
 * ws_add_methods, after core's: puts photoinfo around pwg.images.setInfo.
 *
 * @param array $arr
 */
function photoinfo_wrap_core_methods($arr)
{
  photoinfo_wrap_method($arr[0], 'pwg.images.setInfo', 'ws_photoinfo_images_setInfo',
    PHOTOINFO_PATH.'include/events_core_edit.inc.php');
}

/**
 * The callbacks photoinfo_wrap_method() replaced, by method name.
 *
 * @return array method => array('callback', 'include')
 */
function &photoinfo_wrapped_methods()
{
  static $methods = array();
  return $methods;
}

/**
 * Registers a web-service method again around its current callback, keeping
 * its parameters, description and options. The wrapper calls the original
 * through photoinfo_call_wrapped(). Does nothing for a method that does not
 * exist, as when the plugin providing it is off, or that is wrapped already.
 *
 * @param object $service
 * @param string $method
 * @param string $callback
 * @param string $include the file defining $callback
 */
function photoinfo_wrap_method($service, $method, $callback, $include)
{
  if (!$service->hasMethod($method))
  {
    return;
  }

  $wrapped = &photoinfo_wrapped_methods();
  if (isset($wrapped[$method]))
  {
    return;
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
    $include,
    $service->getMethodOptions($method)
    );
}

/**
 * Calls the callback photoinfo_wrap_method() replaced.
 *
 * @param string $method
 * @param array $params
 * @param object $service
 * @return mixed its answer
 */
function photoinfo_call_wrapped($method, $params, &$service)
{
  $wrapped = photoinfo_wrapped_methods();
  if (!empty($wrapped[$method]['include']))
  {
    include_once($wrapped[$method]['include']);
  }

  return call_user_func_array($wrapped[$method]['callback'], array($params, &$service));
}

/**
 * pwg.images.setInfo, then photoinfo's side of what it changed: the date and
 * description, and the tags. Core's answer is kept; when a file was written,
 * the answer says whether that worked.
 *
 * @param array $params
 * @param object $service
 * @return mixed
 */
function ws_photoinfo_images_setInfo($params, &$service)
{
  $before = photoinfo_core_edit_snapshot($params['image_id']);
  $tags_before = photoinfo_tag_ids_of($params['image_id']);

  $result = photoinfo_call_wrapped('pwg.images.setInfo', $params, $service);
  if ($before === null)
  {
    return $result;
  }

  // Core can answer an error after it saved the row; what it saved is still synced.
  $written = photoinfo_apply_core_edit($before['id'], $before);
  $tags_written = array();
  if (photoinfo_tag_ids_of($before['id']) !== $tags_before)
  {
    $tags_written = photoinfo_write_tags(array($before['id']));
  }

  if ($result instanceof PwgError or ($written === null and count($tags_written) == 0))
  {
    return $result;
  }

  $answer = array('image_id' => (int)$before['id']);
  if ($written !== null)
  {
    $answer['written'] = $written['ok'];
    $answer['message'] = $written['message'];
  }

  return array_merge($answer, photoinfo_tag_write_answer($tags_written));
}

/**
 * What a web-service answer says about the tag writes it caused.
 *
 * @param array $results photoinfo_write_tags()'s answer
 * @return array 'tags_written' => whether every write worked, 'tags_message'
 *   => the first failure's message; empty when nothing was written
 */
function photoinfo_tag_write_answer($results)
{
  if (count($results) == 0)
  {
    return array();
  }

  $answer = array('tags_written' => true, 'tags_message' => '');
  foreach ($results as $result)
  {
    if (!$result['ok'])
    {
      return array('tags_written' => false, 'tags_message' => $result['message']);
    }
  }

  return $answer;
}

/**
 * @param int $image_id
 * @return array|null id, date_creation and comment; null for no such photo
 */
function photoinfo_core_edit_snapshot($image_id)
{
  $query = '
SELECT id, date_creation, comment
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int)$image_id.'
;';
  $row = pwg_db_fetch_assoc(pwg_query($query));

  return empty($row) ? null : $row;
}

/**
 * Brings the plugin's columns and the file in line with what a core screen
 * saved.
 *
 * @param int $image_id
 * @param array|null $before photoinfo_core_edit_snapshot() before the save; null
 *   when the save set the date outright
 * @return array|null the file write's result; null when nothing was written
 */
function photoinfo_apply_core_edit($image_id, $before)
{
  if (!photoinfo_store_core_edit($image_id, $before))
  {
    return null;
  }

  return photoinfo_write_core_edit($image_id);
}

/**
 * Stores the plugin's date columns after a core save. Needs nothing of
 * provenance, so the columns follow core's date even while it is off.
 *
 * @param int $image_id
 * @param array|null $before as for photoinfo_apply_core_edit()
 * @return bool whether the file needs writing
 */
function photoinfo_store_core_edit($image_id, $before)
{
  $after = photoinfo_core_edit_snapshot($image_id);
  if ($after === null)
  {
    return false;
  }

  $edit = photoinfo_core_edit($before, $after);
  if ($edit['columns'] !== null)
  {
    photoinfo_update_image($image_id, $edit['columns']);
  }

  return $edit['write'];
}

/**
 * Writes everything photoinfo keeps in one photo's file.
 *
 * @param int $image_id
 * @return array array('ok' => bool, 'message' => string)
 */
function photoinfo_write_core_edit($image_id)
{
  if (!defined('PROVENANCE_PATH'))
  {
    return array('ok' => false, 'message' => PHOTOINFO_REQUIRES_PROVENANCE_MESSAGE);
  }

  return photoinfo_write_file(photoinfo_image_row($image_id), PHOTOINFO_WRITE_ALL);
}

/**
 * Shows a failed file write on the admin page that caused it, once however many
 * photos it failed for. The database stays saved.
 *
 * @param array|null $result
 */
function photoinfo_report_core_edit($result)
{
  global $page;

  if ($result === null or $result['ok'])
  {
    return;
  }

  $message = PHOTOINFO_ADMIN_ERROR_PREFIX.$result['message'];
  if (!isset($page['errors']) or !in_array($message, $page['errors']))
  {
    $page['errors'][] = $message;
  }
}
