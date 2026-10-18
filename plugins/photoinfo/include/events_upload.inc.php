<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

include_once(PHOTOINFO_PATH.'include/writer.inc.php');
include_once(PHOTOINFO_PATH.'include/rescan.inc.php');

/*
 * An upload or a replace through core's upload code merges the new file and
 * the gallery field by field (decision 0084): a field the file carries wins
 * and is read into the photo, a field it lacks receives the gallery's value.
 * The fields are the info text, the date and the keywords. Regions are
 * persons' (its handler runs first, so the photo's person tags already match
 * the file when the keywords are decided here).
 */

/**
 * loc_end_add_uploaded_file: merges the new file's info text, date and
 * keywords with the photo's. Core's notification cannot refuse an upload, so a
 * failure is logged and the upload stands.
 *
 * @param array $image_infos the photo's row: id, path, representative_ext
 */
function photoinfo_uploaded_file($image_infos)
{
  global $logger;

  $image_id = (int)$image_infos['id'];

  try
  {
    $image = defined('PROVENANCE_PATH') ? photoinfo_image_row($image_id) : null;
    if ($image === null)
    {
      return;
    }

    $outcome = photoinfo_with_persons_lock($image['path'], function () use ($image)
    {
      return photoinfo_merge_uploaded_file($image);
    });
    if (!$outcome['ok'])
    {
      $logger->error('photoinfo: upload of photo '.$image_id.' not merged: '.$outcome['message']);
    }
  }
  catch (Throwable $e)
  {
    $logger->error('photoinfo: upload of photo '.$image_id.' not merged: '.$e->getMessage());
  }
}

/**
 * Reads the new file and settles each field. Runs under the photo's persons
 * lock, so the file does not change between the read and the writes.
 *
 * @param array $image photoinfo_image_row()'s answer
 * @return array array('ok' => bool, 'message' => string) - the first failure
 */
function photoinfo_merge_uploaded_file($image)
{
  global $conf;

  if (!provenance_exiftool_available())
  {
    return array('ok' => false, 'message' => 'exiftool is not available on this server');
  }

  $image_id = (int)$image['id'];
  $tags = photoinfo_read_file_tags(provenance_image_file_path($image['path']));
  if (!is_array($tags))
  {
    return array('ok' => false, 'message' => $tags);
  }

  $failure = null;

  $outcome = photoinfo_rescan_columns($tags, $conf['allow_html_descriptions'], (int)date('Y'), $image['date_creation']);
  if (count($outcome['columns']) > 0)
  {
    photoinfo_update_image($image_id, $outcome['columns']);
  }
  if ($outcome['error'] !== null)
  {
    $failure = array('ok' => false, 'message' => $outcome['error']);
  }

  $image = array_merge($image, $outcome['columns']);
  $write = photoinfo_upload_write_field($tags, $outcome['columns'], $image);
  if ($write !== null)
  {
    $result = photoinfo_write_file(photoinfo_image_row($image_id), $write);
    $failure = $failure === null && !$result['ok'] ? $result : $failure;
  }

  $result = photoinfo_merge_uploaded_tags($image, $tags);
  $failure = $failure === null && !$result['ok'] ? $result : $failure;

  return $failure === null ? array('ok' => true, 'message' => '') : $failure;
}

/**
 * What the gallery has that the new file lacks: its info text, its date, or both.
 *
 * A date counts only when photoinfo set it (a precision is stored): a camera
 * date of the old file is not the photo's own.
 *
 * @param array $tags photoinfo_read_file_tags()'s answer
 * @param array $columns the columns taken from the file
 * @param array $image the photo's row with those columns applied
 * @return string|null PHOTOINFO_WRITE_*, null when the file lacks nothing
 */
function photoinfo_upload_write_field($tags, $columns, $image)
{
  $info = !isset($columns['comment']) && (string)$image['comment'] !== '';
  $date = $tags['edtf'] === null && $image['photoinfo_date_precision'] !== null && $image['date_creation'] !== null;

  if ($info and $date)
  {
    return PHOTOINFO_WRITE_ALL;
  }

  return $info ? PHOTOINFO_WRITE_INFO : ($date ? PHOTOINFO_WRITE_DATE : null);
}

/**
 * A file marked as carrying the photo's tags wins: the tags it names are
 * linked and the others are taken off. An unmarked file receives the photo's
 * tags, when it has any.
 *
 * @param array $image photoinfo_image_row()'s answer
 * @param array $tags photoinfo_read_file_tags()'s answer
 * @return array array('ok' => bool, 'message' => string)
 */
function photoinfo_merge_uploaded_tags($image, $tags)
{
  $image_id = (int)$image['id'];

  if ($tags['tags_marker'])
  {
    $added = photoinfo_rescan_apply_tags($image_id, $tags)['added'];
    // Tags the prune leaves (a marked person's) that the file does not name: the file needs them written.
    $left = array_intersect_key(
      photoinfo_rescan_tags($tags, photoinfo_image_tags($image_id), photoinfo_existing_tag_ids($tags))['not_in_file'],
      array_flip(trigger_change('photoinfo_prune_keeps_tag_ids', array(), $image_id)));
    $pruned = photoinfo_prune_images(array($image_id));
    if (isset($pruned['failed'][$image_id]))
    {
      return array('ok' => false, 'message' => $pruned['failed'][$image_id]);
    }

    // A person still marked on the photo keeps its tag, whatever the file's keywords name.
    $restored = false;
    if (defined('PERSONS_LOCK_DIR'))
    {
      include_once(PERSONS_PATH.'include/index.inc.php');
      $restored = persons_sync_image_tags($image_id);
    }
    $restored = $restored || count($left) > 0;
    if ($added > 0 or isset($pruned['removed'][$image_id]))
    {
      invalidate_user_cache_nb_tags();
    }

    return $restored ? photoinfo_write_tags_file($image) : array('ok' => true, 'message' => '');
  }

  if (count(photoinfo_image_tags($image_id)) == 0)
  {
    return array('ok' => true, 'message' => '');
  }

  return photoinfo_write_tags_file($image);
}
