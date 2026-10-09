<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

include_once(PHOTOINFO_PATH.'include/events_core_edit.inc.php');

/*
 * Every path that changes a photo's tags writes them into its file. Core and
 * the other plugins announce no tag change, so each path is caught where it
 * can be:
 *
 * - the photo properties screen: picture_modify_before_update, then
 *   loc_end_picture_modify once the tags are saved
 * - the Batch Manager's add and remove tags: element_set_global_action
 * - pwg.images.setInfo: its wrapper in events_core_edit.inc.php
 * - typetags' badges, and the admin tags page's rename, copy, merge and
 *   delete: their methods registered again around the original
 * - a tag moved to another group: typetags_tags_regrouped
 * - a face tagged, renamed or removed: persons_tags_changed
 * - a photoedit edit, which can cut a face away: photoedit_end, once
 *   provenance and persons have given their locks back, and only when the
 *   tags changed - the edit copied the old keywords into the new file, and
 *   recorded that file's checksum and version, which a write would make stale
 *
 * A tag typed in on the first four paths, and on typetags' field for a new
 * tag, is put into the Freitext group before the file is written. The admin
 * tags page creates tags deliberately and groups them there.
 */

/**
 * picture_modify_before_update: remembers the tags the save may replace.
 *
 * @param array $data the row core is about to save
 * @return array unchanged
 */
function photoinfo_tags_picture_modify_before_update($data)
{
  global $photoinfo_tags_before;

  $photoinfo_tags_before = array(
    'id' => (int)$data['id'],
    'tags' => photoinfo_tag_ids_of($data['id']),
    'max_tag_id' => photoinfo_max_tag_id(),
    );

  return $data;
}

/**
 * loc_end_picture_modify: after a save on the photo properties screen. The
 * event fires on every load of the screen; the snapshot says a save happened.
 */
function photoinfo_tags_picture_modify_after_save()
{
  global $photoinfo_tags_before;

  if (empty($photoinfo_tags_before))
  {
    return;
  }

  $before = $photoinfo_tags_before;
  $photoinfo_tags_before = null;

  if (photoinfo_tag_ids_of($before['id']) !== $before['tags'])
  {
    photoinfo_assign_freitext($before['max_tag_id'], array($before['id']));
    photoinfo_report_tag_writes(photoinfo_write_tags(array($before['id'])));
  }
}

/**
 * loc_begin_element_set_global: before the Batch Manager runs an action,
 * which may create the tags typed into its field.
 */
function photoinfo_tags_begin_element_set_global()
{
  global $photoinfo_max_tag_id_before;

  $photoinfo_max_tag_id_before = photoinfo_max_tag_id();
}

/**
 * element_set_global_action: after the Batch Manager added or removed tags on
 * a selection.
 *
 * @param string $action
 * @param array $collection image ids
 */
function photoinfo_tags_element_set_global_action($action, $collection)
{
  // Core refuses either action without a tag, and then changes nothing.
  global $photoinfo_max_tag_id_before;

  if (in_array($action, array('add_tags', 'del_tags')) and !empty($_POST[$action]))
  {
    if ($action == 'add_tags' and isset($photoinfo_max_tag_id_before))
    {
      photoinfo_assign_freitext($photoinfo_max_tag_id_before, $collection);
    }
    photoinfo_report_tag_writes(photoinfo_write_tags($collection));
  }
}

/**
 * typetags_tags_regrouped: tags moved to another group, or out of a deleted
 * one, change the hierarchy of every photo carrying them.
 *
 * @param int[] $tag_ids
 */
function photoinfo_tags_regrouped($tag_ids)
{
  photoinfo_report_tag_writes(photoinfo_write_tags(photoinfo_images_with_tags($tag_ids)));
}

/**
 * persons_tags_changed: a face tagged, renamed or removed changed the photos'
 * person tags. Hands back how each write went, which persons' answers pass on.
 *
 * @param array $tag_writes image id => array('ok', 'message') of earlier listeners
 * @param int[] $image_ids
 * @return array $tag_writes with this plugin's writes
 */
function photoinfo_persons_tags_changed($tag_writes, $image_ids)
{
  // The regions are saved already; a failed write must not turn that into an error.
  try
  {
    $results = photoinfo_write_tags($image_ids);
  }
  catch (Throwable $e)
  {
    $results = array_fill_keys(array_map('intval', $image_ids), array('ok' => false, 'message' => $e->getMessage()));
  }
  photoinfo_report_tag_writes($results);

  return array_replace((array)$tag_writes, $results);
}

/**
 * The tag ids of the photos photoedit is editing in this request, by image id.
 *
 * @return array
 */
function &photoinfo_photoedit_tags_before()
{
  static $before = array();
  return $before;
}

/**
 * photoedit_begin: remembers the photo's tags before the edit.
 *
 * @param array $image the photo's row: id, path
 */
function photoinfo_photoedit_begin($image)
{
  $before = &photoinfo_photoedit_tags_before();
  $before[(int)$image['id']] = photoinfo_tag_ids_of($image['id']);
}

/**
 * photoedit_end, after provenance and persons gave their locks back: an edit
 * that cut a face away dropped its person tag while photoinfo could not write.
 * Fired in photoedit's finally, so nothing may escape from here.
 *
 * @param array $image the photo's row: id, path
 * @param array $transform
 * @param bool $ok whether the edit was written
 */
function photoinfo_photoedit_end($image, $transform, $ok)
{
  global $logger;

  $before = &photoinfo_photoedit_tags_before();
  $image_id = (int)$image['id'];
  $tags_before = isset($before[$image_id]) ? $before[$image_id] : null;
  unset($before[$image_id]);

  try
  {
    if (!$ok or $tags_before === null or photoinfo_tag_ids_of($image_id) === $tags_before)
    {
      return;
    }

    foreach (photoinfo_write_tags(array($image_id)) as $result)
    {
      if (!$result['ok'])
      {
        $logger->error('photoinfo: tags of photo '.$image_id.' not written after its edit: '.$result['message']);
      }
    }
  }
  catch (Throwable $e)
  {
    $logger->error('photoinfo: tags of photo '.$image_id.' not written after its edit: '.$e->getMessage());
  }
}

/**
 * ws_add_methods, after core's and typetags': puts photoinfo around every
 * method that changes tags without an event.
 *
 * @param array $arr
 */
function photoinfo_wrap_tag_methods($arr)
{
  $methods = array(
    'typetags.image.addTag' => 'ws_photoinfo_typetags_addTag',
    'typetags.image.removeTag' => 'ws_photoinfo_typetags_removeTag',
    'typetags.image.addNewTag' => 'ws_photoinfo_typetags_addNewTag',
    'pwg.tags.rename' => 'ws_photoinfo_tags_rename',
    'pwg.tags.duplicate' => 'ws_photoinfo_tags_duplicate',
    'pwg.tags.merge' => 'ws_photoinfo_tags_merge',
    'pwg.tags.delete' => 'ws_photoinfo_tags_delete',
    );

  foreach ($methods as $method => $callback)
  {
    photoinfo_wrap_method($arr[0], $method, $callback, PHOTOINFO_PATH.'include/events_tags.inc.php');
  }
}

/** typetags' + badge on the picture page. */
function ws_photoinfo_typetags_addTag($params, &$service)
{
  return photoinfo_call_and_write('typetags.image.addTag', $params, $service, null, array($params['image_id']));
}

/** typetags' x on a badge on the picture page. */
function ws_photoinfo_typetags_removeTag($params, &$service)
{
  return photoinfo_call_and_write('typetags.image.removeTag', $params, $service, null, array($params['image_id']));
}

/**
 * typetags' field for a new tag on the picture page: a new name goes to
 * Freitext, and the answer's badge is the tag's after that.
 */
function ws_photoinfo_typetags_addNewTag($params, &$service)
{
  $max_tag_id = photoinfo_max_tag_id();

  $result = photoinfo_call_wrapped('typetags.image.addNewTag', $params, $service);
  if ($result instanceof PwgError)
  {
    return $result;
  }

  photoinfo_assign_freitext($max_tag_id, array($params['image_id']));

  return array_merge(
    $result,
    typetags_tag_badge($result['tag_id']),
    photoinfo_tag_write_answer(photoinfo_write_tags(array($params['image_id'])))
    );
}

/** The admin tags page's rename. */
function ws_photoinfo_tags_rename($params, &$service)
{
  return photoinfo_call_and_write('pwg.tags.rename', $params, $service, array($params['tag_id']), null);
}

/** The copy carries the same photos as the tag it copies. */
function ws_photoinfo_tags_duplicate($params, &$service)
{
  return photoinfo_call_and_write('pwg.tags.duplicate', $params, $service, array($params['tag_id']), null);
}

/** The merged tags' links are gone afterwards, so their photos are read first. */
function ws_photoinfo_tags_merge($params, &$service)
{
  $photos = photoinfo_images_with_tags(array_merge(array($params['destination_tag_id']), $params['merge_tag_id']));

  return photoinfo_call_and_write('pwg.tags.merge', $params, $service, null, $photos);
}

/** The deleted tags' links are gone afterwards, so their photos are read first. */
function ws_photoinfo_tags_delete($params, &$service)
{
  return photoinfo_call_and_write('pwg.tags.delete', $params, $service, null,
    photoinfo_images_with_tags($params['tag_id']));
}

/**
 * Calls a wrapped method, then writes the tags of the photos it changed. An
 * answer that is an error writes nothing.
 *
 * @param string $method
 * @param array $params
 * @param object $service
 * @param int[]|null $tag_ids the photos carrying these tags afterwards, or
 * @param int[]|null $image_ids these photos
 * @return mixed the method's answer, with tags_written and tags_message when
 *   a file was written
 */
function photoinfo_call_and_write($method, $params, &$service, $tag_ids, $image_ids)
{
  $result = photoinfo_call_wrapped($method, $params, $service);
  if ($result instanceof PwgError)
  {
    return $result;
  }

  $answer = photoinfo_tag_write_answer(
    photoinfo_write_tags($image_ids !== null ? $image_ids : photoinfo_images_with_tags($tag_ids))
    );
  if (count($answer) == 0)
  {
    return $result;
  }

  return is_array($result) ? array_merge($result, $answer) : $answer;
}

/**
 * Shows the failed tag writes on the admin page that caused them, once per
 * message. The database stays saved.
 *
 * @param array $results photoinfo_write_tags()'s answer
 */
function photoinfo_report_tag_writes($results)
{
  foreach ($results as $result)
  {
    photoinfo_report_core_edit($result);
  }
}
