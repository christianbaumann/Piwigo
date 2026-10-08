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
  global $photoinfo_core_edit_before, $page;

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
 * selected photo counts as changed.
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

  foreach ($collection as $image_id)
  {
    photoinfo_report_core_edit(photoinfo_apply_core_edit($image_id, null));
  }
}

/**
 * ws_add_methods, after core's: puts photoinfo around pwg.images.setInfo.
 *
 * @param array $arr
 */
function photoinfo_wrap_core_methods($arr)
{
  $service = &$arr[0];
  $method = 'pwg.images.setInfo';

  if (!$service->hasMethod($method))
  {
    return;
  }

  $service->addMethod(
    $method,
    'ws_photoinfo_images_setInfo',
    $service->getMethodSignature($method),
    $service->getMethodDescription($method),
    PHOTOINFO_PATH.'include/events_core_edit.inc.php',
    $service->getMethodOptions($method)
    );
}

/**
 * pwg.images.setInfo, then photoinfo's side of what it changed. Core's answer
 * is kept; when a file was written, the answer says whether that worked.
 *
 * @param array $params
 * @param object $service
 * @return mixed
 */
function ws_photoinfo_images_setInfo($params, &$service)
{
  include_once(PHPWG_ROOT_PATH.'include/ws_functions/pwg.images.php');

  $before = photoinfo_core_edit_snapshot($params['image_id']);

  $result = ws_images_setInfo($params, $service);
  if ($result instanceof PwgError or $before === null)
  {
    return $result;
  }

  $written = photoinfo_apply_core_edit($before['id'], $before);
  if ($written === null)
  {
    return $result;
  }

  return array(
    'image_id' => (int)$before['id'],
    'written' => $written['ok'],
    'message' => $written['message'],
    );
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
  if (!defined('PROVENANCE_PATH'))
  {
    return array('ok' => false, 'message' => PHOTOINFO_REQUIRES_PROVENANCE_MESSAGE);
  }

  $image = photoinfo_image_row($image_id);
  if ($image === null)
  {
    return null;
  }

  $edit = photoinfo_core_edit($before, $image);

  if ($edit['columns'] !== null)
  {
    photoinfo_update_image($image['id'], $edit['columns']);
    $image = array_merge($image, $edit['columns']);
  }

  if (!$edit['write'])
  {
    return null;
  }

  return photoinfo_write_file($image, PHOTOINFO_WRITE_ALL);
}

/**
 * Shows a failed file write on the admin page that caused it. The database
 * stays saved.
 *
 * @param array|null $result
 */
function photoinfo_report_core_edit($result)
{
  global $page;

  if ($result !== null and !$result['ok'])
  {
    $page['errors'][] = 'Photo Info: '.$result['message'];
  }
}
