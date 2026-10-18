<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

include_once(PERSONS_PATH.'include/index.inc.php');

/**
 * No tag tool separates a person's tag from its mark: removing the tag from a
 * photo where the person is marked, or deleting the tag, is refused on every
 * path core and typetags offer. The mark is the source; its tag follows it
 * (decision 0020). The only ways to drop the tag are deleting the mark or the
 * person.
 *
 * Pure decisions are in functions.inc.php (persons_marked_tags_removed(),
 * persons_split_raw_tags()); this file reads the database and hooks the paths.
 */

/**
 * The tags of the persons marked (with a Face region) on each photo: the tags
 * persons_sync_image_tags() keeps there.
 *
 * @param int[] $image_ids
 * @return array image id => int[] tag ids, only photos with a mark
 */
function persons_marked_tag_ids($image_ids)
{
  $image_ids = array_filter(array_map('intval', $image_ids));
  if (count($image_ids) == 0)
  {
    return array();
  }

  $marked = array();
  $result = pwg_query('
SELECT DISTINCT r.image_id, p.tag_id
  FROM '.PERSONS_REGION_TABLE.' AS r
  JOIN '.PERSONS_TABLE.' AS p ON p.id = r.person_id
  WHERE r.image_id IN ('.implode(',', $image_ids).')
    AND r.region_type = \'Face\'
    AND p.tag_id IS NOT NULL
;');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $marked[(int)$row['image_id']][] = (int)$row['tag_id'];
  }

  return $marked;
}

/**
 * @param int[] $image_ids
 * @return array image id => int[] tag ids it carries now
 */
function persons_current_tag_ids($image_ids)
{
  $image_ids = array_filter(array_map('intval', $image_ids));
  $current = array_fill_keys($image_ids, array());
  if (count($image_ids) == 0)
  {
    return $current;
  }

  $result = pwg_query('
SELECT image_id, tag_id
  FROM '.IMAGE_TAG_TABLE.'
  WHERE image_id IN ('.implode(',', $image_ids).')
;');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $current[(int)$row['image_id']][] = (int)$row['tag_id'];
  }

  return $current;
}

/**
 * The existing tags a tag field keeps: its ~~id~~ values and the tags whose
 * name equals a typed one. Creates nothing; a new name keeps no existing tag.
 *
 * @param string|string[]|null $raw
 * @return int[]
 */
function persons_kept_tag_ids($raw)
{
  $split = persons_split_raw_tags($raw);
  $kept = $split['ids'];

  $names = array();
  foreach ($split['names'] as $name)
  {
    $name = strip_tags(stripslashes($name));
    if (class_exists('Normalizer'))
    {
      $nfc = Normalizer::normalize($name, Normalizer::FORM_C);
      $name = $nfc === false ? $name : $nfc;
    }
    $names[] = "'".pwg_db_real_escape_string(trim($name))."'";
  }
  if (count($names) > 0)
  {
    $kept = array_merge($kept, array_map('intval', query2array(
      'SELECT id FROM '.TAGS_TABLE.' WHERE name IN ('.implode(',', $names).');', null, 'id')));
  }

  return $kept;
}

/**
 * The refusal for a change that leaves each photo with the tags in $kept.
 *
 * @param array $kept image id => tag ids after the change
 * @return array image id => refused tag ids
 */
function persons_refused_by_kept($kept)
{
  $image_ids = array_keys($kept);
  $marked = persons_marked_tag_ids($image_ids);
  if (count($marked) == 0)
  {
    return array();
  }

  return persons_marked_tags_removed($marked, persons_current_tag_ids($image_ids), $kept);
}

/**
 * Of these tags, the ones that are a person's own tag (persons_own_tag_id()).
 *
 * @param int[] $tag_ids
 * @return int[]
 */
function persons_own_tags_among($tag_ids)
{
  $tag_ids = array_filter(array_map('intval', $tag_ids));
  if (count($tag_ids) == 0)
  {
    return array();
  }

  $own = array();
  $result = pwg_query(
    'SELECT id, name, tag_id FROM '.PERSONS_TABLE.' WHERE tag_id IN ('.implode(',', $tag_ids).');');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $tag_id = persons_own_tag_id($row);
    if ($tag_id !== null)
    {
      $own[] = $tag_id;
    }
  }

  return array_values(array_unique($own));
}

/**
 * The message of a refusal, naming the tags.
 *
 * @param string $key PERSONS_TAG_REMOVAL_REFUSED_MESSAGE or _DELETE_
 * @param int[] $tag_ids
 * @return string
 */
function persons_tag_refusal_message($key, $tag_ids)
{
  load_language('plugin.lang', PERSONS_PATH);

  $names = query2array('SELECT name FROM '.TAGS_TABLE
    .' WHERE id IN ('.implode(',', array_map('intval', $tag_ids)).') ORDER BY name;', null, 'name');

  return l10n($key, implode(', ', $names));
}

/**
 * @param array $refused image id => tag ids, see persons_marked_tags_removed()
 * @return int[] every refused tag once
 */
function persons_refused_tag_list($refused)
{
  $tags = array();
  foreach ($refused as $tag_ids)
  {
    $tags = array_merge($tags, $tag_ids);
  }
  return array_values(array_unique($tags));
}

/**
 * ws_invoke_allowed: refuses a web-service call that would take a person's tag
 * off a photo where the person is marked, or delete a person's tag. Runs before
 * the method, so a refused call reaches none of its handlers.
 *
 * @param true|PwgError $res
 * @param string $method
 * @param array $params
 * @return true|PwgError
 */
function persons_guard_ws_invoke($res, $method, $params)
{
  if ($res instanceof PwgError)
  {
    return $res;
  }

  $key = PERSONS_TAG_REMOVAL_REFUSED_MESSAGE;
  $refused = array();

  if ('pwg.images.setInfo' == $method)
  {
    // The order core applies them in: tag_ids first, then tag_list.
    if (isset($params['tag_ids']) and 'replace' == $params['multiple_value_mode'])
    {
      // core keeps the candidates that are ids and drops the rest
      $kept = array_map('intval', array_filter(array_map('trim', explode(',', $params['tag_ids'])),
        function ($id) { return preg_match('/^\d+$/', $id); }));
      $refused = persons_refused_tag_list(persons_refused_by_kept(array((int)$params['image_id'] => $kept)));
    }
    elseif (!isset($params['tag_ids']) and isset($_REQUEST['tag_list']))
    {
      $refused = persons_refused_tag_list(persons_refused_by_kept(
        array((int)$params['image_id'] => persons_kept_tag_ids((array)$_REQUEST['tag_list']))));
    }
  }
  elseif ('typetags.image.removeTag' == $method)
  {
    // A photo the caller cannot see is typetags' 404, not a statement about its marks.
    if (!is_a_guest() and persons_user_can_see_image($params['image_id']))
    {
      $current = persons_current_tag_ids(array($params['image_id']));
      $kept = array((int)$params['image_id'] => array_diff(reset($current), array((int)$params['tag_id'])));
      $refused = persons_refused_tag_list(persons_refused_by_kept($kept));
    }
  }
  elseif ('pwg.tags.delete' == $method)
  {
    $key = PERSONS_TAG_DELETE_REFUSED_MESSAGE;
    $refused = persons_own_tags_among((array)$params['tag_id']);
  }
  elseif ('pwg.tags.rename' == $method)
  {
    $key = PERSONS_TAG_RENAME_REFUSED_MESSAGE;
    $refused = persons_own_tags_among(array($params['tag_id']));
  }
  elseif ('pwg.tags.merge' == $method)
  {
    // merge deletes every merged tag but the destination
    $key = PERSONS_TAG_DELETE_REFUSED_MESSAGE;
    $refused = persons_own_tags_among(
      array_diff((array)$params['merge_tag_id'], array($params['destination_tag_id'])));
  }

  if (count($refused) == 0)
  {
    return $res;
  }

  return new PwgError(WS_ERR_INVALID_PARAM, persons_tag_refusal_message($key, $refused));
}

/**
 * photoinfo_prune_keeps_tag_ids: pwg.photoinfo.pruneTags leaves the tags of the
 * persons marked on the photo, whatever its file names.
 *
 * @param int[] $tag_ids
 * @param int $image_id
 * @return int[]
 */
function persons_prune_keeps_marked_tags($tag_ids, $image_id)
{
  return persons_typetags_fixed_tag_ids($tag_ids, $image_id);
}

/**
 * ws_add_methods, after core's: puts persons around the two upload methods
 * that set a photo's tags from tag_ids. ws_invoke_allowed cannot change the
 * parameters a method receives, so the wrapper does.
 *
 * @param array $arr
 */
function persons_wrap_upload_methods($arr)
{
  $service = $arr[0];
  $wrapped = &persons_wrapped_upload_methods();

  foreach (array('pwg.images.add' => 'ws_persons_images_add',
                 'pwg.images.uploadAsync' => 'ws_persons_images_uploadAsync') as $method => $callback)
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
      PERSONS_PATH.'include/tag_guard.inc.php',
      $service->getMethodOptions($method)
      );
  }
}

/**
 * @return array method => array('callback', 'include') of the callbacks persons_wrap_upload_methods() replaced
 */
function &persons_wrapped_upload_methods()
{
  static $methods = array();
  return $methods;
}

/**
 * tag_ids with the tags of the persons marked on an existing photo added, so
 * the upload's set_tags() cannot take them off. Empty tag_ids stay empty: core
 * sets no tags then.
 *
 * @param string|null $tag_ids comma separated ids
 * @param int|null $image_id
 * @return string|null
 */
function persons_tag_ids_with_marked($tag_ids, $image_id)
{
  if (empty($tag_ids) or empty($image_id) or (int)$image_id < 1)
  {
    return $tag_ids;
  }

  $marked = persons_marked_tag_ids(array((int)$image_id));
  if (!isset($marked[(int)$image_id]))
  {
    return $tag_ids;
  }

  return implode(',', array_unique(array_merge(explode(',', $tag_ids), $marked[(int)$image_id])));
}

function persons_call_wrapped_upload($method, $params, &$service)
{
  $params['tag_ids'] = persons_tag_ids_with_marked($params['tag_ids'], $params['image_id']);

  $wrapped = persons_wrapped_upload_methods();
  if (!empty($wrapped[$method]['include']))
  {
    include_once($wrapped[$method]['include']);
  }

  return call_user_func_array($wrapped[$method]['callback'], array($params, &$service));
}

function ws_persons_images_add($params, &$service)
{
  return persons_call_wrapped_upload('pwg.images.add', $params, $service);
}

function ws_persons_images_uploadAsync($params, &$service)
{
  return persons_call_wrapped_upload('pwg.images.uploadAsync', $params, $service);
}

/**
 * Puts a refused photo's person tags back into the raw tag field, so the form
 * saves everything else, and says why.
 *
 * @param int $image_id
 * @param string $field the $_POST key of the photo's tag field
 */
function persons_keep_marked_tags_in_field($image_id, $field)
{
  global $page;

  $refused = persons_refused_by_kept(
    array((int)$image_id => persons_kept_tag_ids(isset($_POST[$field]) ? $_POST[$field] : null)));
  if (count($refused) == 0)
  {
    return;
  }

  $tags = persons_refused_tag_list($refused);
  $raw = isset($_POST[$field]) ? $_POST[$field] : array();
  $raw = is_array($raw) ? $raw : ($raw === '' ? array() : explode(',', $raw));
  foreach ($tags as $tag_id)
  {
    $raw[] = '~~'.$tag_id.'~~';
  }
  $_POST[$field] = $raw;
  $page['errors'][] = persons_tag_refusal_message(PERSONS_TAG_REMOVAL_REFUSED_MESSAGE, $tags);
}

/**
 * picture_modify_before_update: the properties page keeps the tags of the
 * persons marked on the photo; its other fields are still saved.
 *
 * @param array $data
 * @return array $data, unchanged
 */
function persons_properties_keep_marked_tags($data)
{
  persons_keep_marked_tags_in_field($data['id'], 'tags');
  return $data;
}

/**
 * loc_begin_element_set_unit: the Batch Manager's unit-mode form, posted
 * without its JavaScript, keeps each photo's marked person tags, like the
 * properties page. (With JavaScript it saves through pwg.images.setInfo.)
 */
function persons_batch_unit_keep_marked_tags()
{
  if (!isset($_POST['submit']) or !isset($_POST['element_ids'])
    or !preg_match('/^\d+(,\d+)*$/', (string)$_POST['element_ids']))
  {
    return;
  }

  foreach (explode(',', $_POST['element_ids']) as $image_id)
  {
    persons_keep_marked_tags_in_field($image_id, 'tags-'.$image_id);
  }
}

/**
 * loc_begin_element_set_global: the Batch Manager's "remove tags" is refused,
 * for the whole selection, when it would take a person's tag off a photo where
 * the person is marked. The pwg_token is checked by core before this event.
 */
function persons_batch_refuse_del_tags()
{
  global $page;

  if (!isset($_POST['submit']) or @$_POST['selectAction'] !== 'del_tags' or empty($_POST['del_tags']))
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
  $removed = array_filter((array)$_POST['del_tags'], function ($id) { return preg_match('/^\d+$/', (string)$id); });

  $current = persons_current_tag_ids($collection);
  $kept = array();
  foreach ($current as $image_id => $tag_ids)
  {
    $kept[$image_id] = array_diff($tag_ids, array_map('intval', $removed));
  }

  $refused = persons_refused_by_kept($kept);
  if (count($refused) > 0)
  {
    $page['errors'][] = persons_tag_refusal_message(
      PERSONS_TAG_REMOVAL_REFUSED_MESSAGE, persons_refused_tag_list($refused));
    unset($_POST['submit']);
  }
}

/**
 * typetags_fixed_tag_ids: typetags offers no x on the tags of the persons
 * marked on the photo.
 *
 * @param int[] $tag_ids
 * @param int $image_id
 * @return int[]
 */
function persons_typetags_fixed_tag_ids($tag_ids, $image_id)
{
  $marked = persons_marked_tag_ids(array($image_id));
  return array_merge($tag_ids, isset($marked[(int)$image_id]) ? $marked[(int)$image_id] : array());
}
